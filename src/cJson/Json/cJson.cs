#nullable disable

using System;
using System.Collections.Generic;
using System.Data.Common;
using System.Globalization;
using System.Text;
using System.Text.Encodings.Web;
using System.Text.Json;
using System.Text.Json.Nodes;
using System.Text.RegularExpressions;

namespace CrudLib
{
    /// <summary>
    /// JSON DOM 하나를 유일한 저장소로 쓰는 레코드셋 (VBA cXml의 JSON판).
    ///
    /// cXml이 DOMDocument를 직접 읽고 썼듯이, cJson은 JsonObject(doc)를 직접 읽고 쓴다.
    /// 별도의 내부 자료구조가 없으므로 ToJson()은 언제 불러도 현재 상태 그대로다.
    ///
    /// JSON 구조 (PHP Crud::toJson()의 {schema, cursor, data}에 isLoadedSchema, where를 더한 것):
    /// {
    ///   "isLoadedSchema": false,
    ///   "schema": { "tableName": "", "primaryKey": "code", "fields": {} },
    ///   "cursor": -1,
    ///   "where": "",
    ///   "data": [ { "code": "A1", "name": "사과" }, ... ]
    /// }
    ///
    /// 규칙:
    /// - 키 필드 이름은 schema.primaryKey, 비어 있으면 "code".
    /// - cursor는 data 안의 인덱스. -1 = BOF, data.Count = EOF.
    /// - isLoadedSchema = false: 필드를 자유롭게 추가할 수 있다.
    /// - isLoadedSchema = true : schema.fields에 없는 필드를 쓰면 예외.
    /// - 쓰기 실패는 예외, 읽기 실패(없는 키/필드, BOF/EOF)는 "" 반환 (cXml과 같음).
    /// - where는 문자열 조건식으로 JSON에 저장한다 (cXml이 루트 속성에 저장한 것과 같음).
    ///   직렬화 → Load 하면 필터도 다시 걸린다. 문법은 cJsonWhere 참고.
    ///
    /// DB는 모른다. 나중에 Crud가 LoadSchema()와 SetValue()로 이 객체를 채운다.
    /// </summary>
    public partial class cJson
    {
        /// <summary>schema.primaryKey가 비어 있을 때 쓰는 키 필드 이름 (cXml 기본값)</summary>
        public const string DefaultKeyName = "code";

        /// <summary>Pseudo 키 접두어. 실제 키는 "Pseudo" + type + "_" + 원래키</summary>
        public const string PseudoPrefix = "Pseudo";

        /// <summary>
        /// 새 행(아직 DB에 없는 행)의 임시 키 접두어 = Pseudo type "0". AddNew()가 붙이고, Crud가 이 접두어로 새 행을 알아본다.
        /// type "0"은 새 행 전용으로 예약한다 — LoadPseudo("0")은 예외 (기존 행이 새 행으로 보여 중복 INSERT되는 것을 막는다).
        /// </summary>
        public const string NewKeyPrefix = PseudoPrefix + "0_";

        /// <summary>AddNew()가 쓴 마지막 번호. JSON에는 들어가지 않는다 (Load 뒤에도 ExistsKey 검사로 겹치지 않는다).</summary>
        private int newKeySeq = 0;

        /// <summary>유일한 저장소</summary>
        private JsonObject doc = null;

        /// <summary>LoadSql 등에서 쓰는 DbContext. JSON에는 들어가지 않는다. 연결의 주인은 주입한 쪽.</summary>
        private DbContext dbContext = null;

        /// <summary>마지막 LoadSql / LoadSqlQuery가 실행한 SQL (확인용)</summary>
        private string lastSql = "";

        /// <summary>doc["where"]를 해석한 검사 함수 (캐시). null이면 전체.</summary>
        private Func<JsonObject, bool> whereFilter = null;

        private static readonly JsonSerializerOptions jsonCompact = CreateOptions(false);
        private static readonly JsonSerializerOptions jsonIndented = CreateOptions(true);

        /// <summary>
        /// 만들면서 로드한다. 인자를 모두 생략하면 빈 구조이고, 바로 SetValue 등을 쓸 수 있다.
        /// pJson  : 규칙은 Load(string)과 같다.
        /// pSchema: 주면 Load 다음에 LoadSchema(CrudSchema)까지 한다.
        /// pDbContext: 주면 LoadSql / LoadSqlSchema / LoadSqlQuery에서 쓴다 (SetDbContext와 같음).
        ///
        /// 예:
        ///   new cJson()
        ///   new cJson(json)
        ///   new cJson(pSchema: schema)
        ///   new cJson(json, pSchema: schema)
        ///   new cJson(pDbContext: ctx)
        /// </summary>
        public cJson(string pJson = "", CrudSchema pSchema = null, DbContext pDbContext = null)
        {
            dbContext = pDbContext;
            Load(pJson);
            if (pSchema != null)
            {
                LoadSchema(pSchema);
            }
        }

        // ==================================================================
        // 로드 / 출력
        // ==================================================================

        /// <summary>
        /// JSON 문자열로 로드한다. 빈 문자열이면 빈 구조를 만든다.
        /// - cJson 형식 {isLoadedSchema, schema, cursor, where, data}: 빠진 키는 기본값으로 채운다.
        ///   isLoadedSchema가 없으면 schema.fields가 비어 있지 않을 때 true (PHP Crud JSON 호환).
        ///   cursor가 없으면 레코드가 있으면 0, 없으면 -1.
        /// - 배열 [ {...}, {...} ]: data로 로드한다.
        ///   where가 있으면 조건식을 다시 해석해 필터를 건다 (없으면 필터 없음).
        /// 형식이 틀리거나 키가 비었거나 중복이거나 where 문법이 틀리면 예외.
        /// </summary>
        public cJson Load(string pJson = "")
        {
            JsonNode parsed;
            JsonObject newDoc;
            JsonArray arr;
            Func<JsonObject, bool> compiled;

            if (pJson == null || pJson.Trim() == "")
            {
                doc = CreateEmptyDoc(DefaultKeyName);
                whereFilter = null;
                return this;
            }

            try
            {
                parsed = JsonNode.Parse(pJson);
            }
            catch (JsonException ex)
            {
                throw new ArgumentException("JSON 형식이 올바르지 않습니다: " + ex.Message, "pJson", ex);
            }

            if (parsed is JsonArray)
            {
                arr = (JsonArray)parsed;
                newDoc = CreateEmptyDoc(DefaultKeyName);
                newDoc["data"] = arr;
                if (arr.Count > 0)
                {
                    newDoc["cursor"] = JsonValue.Create(0);
                }
            }
            else if (parsed is JsonObject)
            {
                newDoc = NormalizeDoc((JsonObject)parsed);
            }
            else
            {
                throw new ArgumentException("JSON 최상위는 객체 또는 배열이어야 합니다.", "pJson");
            }

            compiled = ValidateDoc(newDoc);

            doc = newDoc;
            whereFilter = compiled;
            return this;
        }

        /// <summary>다른 cJson의 내용을 복사해서 로드한다. where도 JSON에 들어 있으므로 함께 복사된다.</summary>
        public cJson Load(cJson pOther)
        {
            if (pOther == null)
            {
                throw new ArgumentNullException("pOther");
            }
            return Load(pOther.ToJson());
        }

        /// <summary>전체 JSON 문자열 (cXml의 GET_XML). 한글은 이스케이프하지 않는다.</summary>
        public string ToJson(bool pIndented = false)
        {
            return doc.ToJsonString(GetOptions(pIndented));
        }

        /// <summary>
        /// where 조건에 맞는 레코드만 담은 JSON (cXml의 GET_WHERE_XML).
        /// cursor는 결과 data 안에서의 현재 레코드 위치, 현재 레코드가 없으면 -1.
        /// where 조건식도 함께 담는다 (걸러진 data에 다시 걸려도 결과는 같다).
        /// where가 없으면 ToJson()과 같은 내용이다.
        /// </summary>
        public string ToJsonWhere(bool pIndented = false)
        {
            JsonObject result;
            JsonArray filtered;
            JsonArray data;
            int i;
            int newCursor;
            int cursor;

            data = Data();
            cursor = GetCursor();
            filtered = new JsonArray();
            newCursor = -1;

            for (i = 0; i < data.Count; i++)
            {
                if (Matches(i))
                {
                    if (i == cursor)
                    {
                        newCursor = filtered.Count;
                    }
                    filtered.Add(data[i].DeepClone());
                }
            }

            result = new JsonObject();
            result["isLoadedSchema"] = JsonValue.Create(IsLoadedSchema());
            result["schema"] = Schema().DeepClone();
            result["cursor"] = JsonValue.Create(newCursor);
            result["where"] = JsonValue.Create(GetWhere());
            result["data"] = filtered;

            return result.ToJsonString(GetOptions(pIndented));
        }

        /// <summary>현재 커서 레코드 하나의 JSON (cXml의 GET_XML_CurrentNode). BOF/EOF면 "".</summary>
        public string CurrentToJson(bool pIndented = false)
        {
            if (!IsCursorValid())
            {
                return "";
            }
            return Data()[GetCursor()].ToJsonString(GetOptions(pIndented));
        }

        // ==================================================================
        // 스키마
        // ==================================================================

        /// <summary>
        /// 스키마를 넣고 isLoadedSchema = true로 바꾼다.
        /// pSchemaJson은 {tableName, primaryKey, fields} 또는 그것을 "schema" 키로 감싼 JSON.
        /// fields는 필드 이름을 키로 하는 객체여야 한다 (PHP Crud 형식).
        /// 이미 있는 data가 새 스키마에 맞지 않으면(없는 필드, 키 누락·중복) 예외이고, 아무것도 바뀌지 않는다.
        /// </summary>
        public cJson LoadSchema(string pSchemaJson)
        {
            JsonNode parsed;
            JsonObject schemaObj;
            JsonObject candidate;
            JsonNode inner;
            Func<JsonObject, bool> compiled;

            if (pSchemaJson == null || pSchemaJson.Trim() == "")
            {
                throw new ArgumentException("스키마 JSON이 비어 있습니다.", "pSchemaJson");
            }

            try
            {
                parsed = JsonNode.Parse(pSchemaJson);
            }
            catch (JsonException ex)
            {
                throw new ArgumentException("스키마 JSON 형식이 올바르지 않습니다: " + ex.Message, "pSchemaJson", ex);
            }

            schemaObj = parsed as JsonObject;
            if (schemaObj == null)
            {
                throw new ArgumentException("스키마 JSON은 객체여야 합니다.", "pSchemaJson");
            }

            // {"schema": {...}} 형태면 안쪽을 쓴다.
            if (schemaObj.TryGetPropertyValue("schema", out inner) && inner is JsonObject)
            {
                schemaObj.Remove("schema");
                schemaObj = (JsonObject)inner;
            }

            if (!(schemaObj["fields"] is JsonObject))
            {
                throw new ArgumentException("스키마에 fields 객체가 없습니다. (필드 이름을 키로 하는 객체여야 함)", "pSchemaJson");
            }
            NormalizeSchema(schemaObj);

            // 사본에 적용해서 검증한 뒤 바꾼다 (실패하면 원래 상태 유지).
            candidate = (JsonObject)doc.DeepClone();
            candidate["isLoadedSchema"] = JsonValue.Create(true);
            candidate["schema"] = schemaObj;
            compiled = ValidateDoc(candidate);

            doc = candidate;
            whereFilter = compiled;
            return this;
        }

        /// <summary>
        /// 방언(ISqlDialect.LoadSchema)이 만든 CrudSchema를 PHP 형식 JSON으로 바꿔 탑재한다.
        ///
        /// CrudSchema       → JSON
        /// TableName        → tableName
        /// PrimaryKey       → primaryKey
        /// Fields (List)    → fields (필드 이름을 키로 하는 객체, DB 정의 순서 유지)
        /// CrudField.Name   → fields의 키
        /// Type             → type
        /// Kind             → kind  ("Integer" 같은 이름 문자열. PHP에는 없는 키)
        /// Nullable         → nullable
        /// MaxLength        → maxLength
        /// DefaultValue     → default
        /// AutoIncrement    → autoIncrement
        /// IsPrimaryKey     → isPrimaryKey
        ///
        /// 변환은 SqlDialectUtil.SchemaToJsonObject()가 하며, 방언의 ToJson()과 같은 형식이다.
        /// cJson은 키 기반이므로 PK가 없는 테이블은 예외.
        /// 그 밖의 규칙(기존 data 검증, 실패 시 상태 유지)은 LoadSchema(string)과 같다.
        /// </summary>
        public cJson LoadSchema(CrudSchema pSchema)
        {
            JsonObject schemaObj;

            if (pSchema == null)
            {
                throw new ArgumentNullException("pSchema");
            }
            if (pSchema.PrimaryKey == null || pSchema.PrimaryKey.Trim() == "")
            {
                throw new ArgumentException("PK가 없는 테이블은 cJson에 탑재할 수 없습니다. (테이블: " + pSchema.TableName + ")", "pSchema");
            }

            // 변환은 방언의 ToJson()과 같은 함수를 쓴다 (형식이 항상 같도록).
            schemaObj = SqlDialectUtil.SchemaToJsonObject(pSchema);

            if (!schemaObj["fields"].AsObject().ContainsKey(pSchema.PrimaryKey))
            {
                throw new ArgumentException("PK 필드(" + pSchema.PrimaryKey + ")가 필드 목록에 없습니다.", "pSchema");
            }

            return LoadSchema(schemaObj.ToJsonString(jsonCompact));
        }

        /// <summary>
        /// 탑재된 스키마를 CrudSchema로 돌려준다 (LoadSchema(CrudSchema)의 반대).
        /// 나중에 Crud가 SQL을 만들 때 쓴다. 스키마가 없으면 예외.
        /// kind가 없거나 모르는 값이면(PHP JSON 등) Other.
        /// 돌려준 객체를 고쳐도 cJson에는 영향이 없다.
        /// </summary>
        public CrudSchema GetSchema()
        {
            CrudSchema result;
            CrudField f;
            JsonObject meta;
            JsonNode node;
            CrudFieldKind kind;
            string pk;
            int maxLength;

            if (!IsLoadedSchema())
            {
                throw new InvalidOperationException("스키마가 탑재되지 않았습니다. LoadSchema()를 먼저 호출하세요.");
            }

            result = new CrudSchema();
            result.TableName = ToText(Schema()["tableName"]);
            pk = ToText(Schema()["primaryKey"]);
            result.PrimaryKey = pk == "" ? null : pk;

            foreach (KeyValuePair<string, JsonNode> p in Fields())
            {
                f = new CrudField();
                f.Name = p.Key;

                meta = p.Value as JsonObject;
                if (meta != null)
                {
                    f.Type = ToText(meta["type"]);

                    if (Enum.TryParse<CrudFieldKind>(ToText(meta["kind"]), true, out kind))
                    {
                        f.Kind = kind;
                    }

                    if (meta.TryGetPropertyValue("nullable", out node) && node != null)
                    {
                        f.Nullable = ToBool(node);
                    }

                    node = meta["maxLength"];
                    if (node != null && int.TryParse(ToText(node), NumberStyles.Integer, CultureInfo.InvariantCulture, out maxLength))
                    {
                        f.MaxLength = maxLength;
                    }

                    node = meta["default"];
                    f.DefaultValue = node == null ? null : ToText(node);

                    f.AutoIncrement = ToBool(meta["autoIncrement"]);
                    f.IsPrimaryKey = ToBool(meta["isPrimaryKey"]);
                }

                result.Fields.Add(f);
            }

            return result;
        }

        /// <summary>
        /// 스키마를 비우고 isLoadedSchema = false로 바꾼다. data는 그대로 둔다.
        /// 키 필드 이름(primaryKey)은 data의 키가 깨지지 않도록 유지한다.
        /// </summary>
        public cJson UnloadSchema()
        {
            string keyName;

            keyName = GetKeyName();
            doc["isLoadedSchema"] = JsonValue.Create(false);
            doc["schema"] = CreateEmptySchema(keyName);
            return this;
        }

        /// <summary>스키마 탑재 여부</summary>
        public bool IsLoadedSchema()
        {
            return doc["isLoadedSchema"].GetValue<bool>();
        }

        /// <summary>schema 부분의 JSON</summary>
        public string GetSchemaJson(bool pIndented = false)
        {
            return Schema().ToJsonString(GetOptions(pIndented));
        }

        /// <summary>
        /// 키 필드 이름. schema.primaryKey이며, 비어 있으면 "code".
        /// 설정은 스키마가 없고 data가 비어 있을 때만 가능하다 (cXml load의 key_attribute_name 인자).
        /// </summary>
        public string KeyName
        {
            get
            {
                return GetKeyName();
            }
            set
            {
                if (value == null || value.Trim() == "")
                {
                    throw new ArgumentException("키 필드 이름이 비어 있습니다.");
                }
                if (IsLoadedSchema())
                {
                    throw new InvalidOperationException("스키마가 탑재된 상태에서는 키 필드 이름을 바꿀 수 없습니다. (schema.primaryKey를 따름)");
                }
                if (Data().Count > 0)
                {
                    throw new InvalidOperationException("레코드가 있는 상태에서는 키 필드 이름을 바꿀 수 없습니다.");
                }
                Schema()["primaryKey"] = JsonValue.Create(value);
            }
        }

        /// <summary>
        /// 필드 이름 목록. 스키마가 있으면 schema.fields 순서,
        /// 없으면 data에 나온 필드를 처음 나온 순서대로 모은다.
        /// </summary>
        public List<string> GetFieldNames()
        {
            List<string> result;
            HashSet<string> seen;
            JsonArray data;
            int i;

            result = new List<string>();

            if (IsLoadedSchema())
            {
                foreach (KeyValuePair<string, JsonNode> p in Fields())
                {
                    result.Add(p.Key);
                }
                return result;
            }

            seen = new HashSet<string>();
            data = Data();
            for (i = 0; i < data.Count; i++)
            {
                foreach (KeyValuePair<string, JsonNode> p in data[i].AsObject())
                {
                    if (seen.Add(p.Key))
                    {
                        result.Add(p.Key);
                    }
                }
            }
            return result;
        }

        /// <summary>필드 존재 여부. 스키마가 있으면 schema.fields, 없으면 data 중 하나라도 가진 필드.</summary>
        public bool ExistsField(string pField)
        {
            if (pField == null || pField == "")
            {
                return false;
            }
            if (IsLoadedSchema())
            {
                return Fields().ContainsKey(pField);
            }
            return GetFieldNames().Contains(pField);
        }

        // ==================================================================
        // DbContext 연계 (읽기 전용: SELECT로 스키마와 data를 채운다)
        // INSERT/UPDATE/DELETE는 Crud가 cJson을 들고 맡는다.
        // ==================================================================

        /// <summary>
        /// DbContext를 주입한다. null이면 해제. JSON에는 들어가지 않는다.
        /// cJson은 연결을 열기만 하고 닫거나 Dispose하지 않는다 (주인은 호출한 쪽).
        /// </summary>
        public cJson SetDbContext(DbContext pDbContext)
        {
            dbContext = pDbContext;
            return this;
        }

        /// <summary>주입된 DbContext. 없으면 null.</summary>
        public DbContext GetDbContext()
        {
            return dbContext;
        }

        /// <summary>
        /// 테이블의 스키마를 탑재하고 SELECT 결과로 data를 채운다. 읽은 행 수를 돌려준다.
        ///
        /// pTableName : 테이블 이름. 스키마는 DbContext.GetSchema()(메모리 캐시)로 가져온다.
        /// pWhere     : WHERE 절 내용 (WHERE 글자 없이). 값은 {0}, {1} 자리로 쓰고 pParams로 넘긴다.
        ///              예: LoadSql("tLogin", "나이 > {0} and 이름 like {1}", pParams: new object[] { 20, "홍%" })
        ///              {n}은 방언의 파라미터 이름(@p0 등)으로 바뀌어 바인딩된다.
        ///              값을 문자열로 직접 이어 붙이지 말 것 (SQL 인젝션).
        /// pOrderBy   : ORDER BY 절 내용. 비우면 PK 순서.
        /// pParams    : {n} 자리에 들어갈 값.
        /// pWhitelist : 주면 이 필드들과 PK만 스키마와 SELECT에 남긴다 (PK는 항상 포함).
        ///              테이블에 없는 이름이 있으면 예외.
        ///
        /// ts, __ts__, Binary 필드는 스키마에 없으므로 읽지 않는다 (SqlDialectUtil.ExcludedFields). 컬럼을 명시하므로 SELECT *가 아니다.
        /// 기존 스키마·data·where는 모두 바뀌고, 커서는 첫 레코드(없으면 -1).
        /// 실패하면(DB 오류, 키 누락·중복 등) 예외이고 cJson은 원래 상태 그대로다.
        /// </summary>
        public int LoadSql(string pTableName, string pWhere = "", string pOrderBy = "", object[] pParams = null, List<string> pWhitelist = null)
        {
            CrudSchema schema;
            cJson temp;
            string sql;
            DbCommand cmd;
            ISqlDialect dialect;

            AssertDbContext();

            schema = PrepareSchema(pTableName, pWhitelist);
            dialect = dbContext.Dialect();
            sql = BuildSelectSql(dialect, schema, ReplacePlaceholders(dialect, pWhere, pParams), pOrderBy);

            temp = new cJson(pSchema: schema);

            cmd = dbContext.CreateCommand(sql);
            using (cmd)
            {
                BindParams(dialect, cmd, pParams);
                ReadRows(cmd, schema, temp.Data());
            }

            lastSql = sql;
            return CommitLoaded(temp);
        }

        /// <summary>
        /// 테이블의 스키마만 탑재하고 data는 비운다 (신규 입력 화면용). 걸러내는 규칙은 LoadSql과 같다.
        /// </summary>
        public cJson LoadSqlSchema(string pTableName, List<string> pWhitelist = null)
        {
            CrudSchema schema;

            AssertDbContext();

            schema = PrepareSchema(pTableName, pWhitelist);
            CommitLoaded(new cJson(pSchema: schema));
            lastSql = "";
            return this;
        }

        /// <summary>
        /// 임의의 SELECT 문으로 data를 채운다 (cXml의 load_from_sql). 읽은 행 수를 돌려준다.
        /// JOIN, 집계 등 테이블 하나로 표현할 수 없는 조회용이며, 스키마는 없다 (isLoadedSchema = false).
        ///
        /// pSql     : SELECT 문 전체. 값은 {0}, {1} 자리로 쓰고 pParams로 넘긴다 (LoadSql과 같음).
        /// pKeyName : 키 필드 이름. 비우면 현재 KeyName. 결과의 모든 행에 이 컬럼 값이 있어야 하고 중복이 없어야 한다.
        ///
        /// 같은 이름의 컬럼이 여럿이면 뒤의 것이 남는다 (별칭 AS를 쓸 것).
        /// byte[] 값(이진 컬럼)은 넣지 않는다. 기존 스키마·data·where는 모두 바뀐다.
        /// 실패하면 예외이고 cJson은 원래 상태 그대로다.
        /// </summary>
        public int LoadSqlQuery(string pSql, object[] pParams = null, string pKeyName = "")
        {
            cJson temp;
            string sql;
            DbCommand cmd;
            ISqlDialect dialect;

            AssertDbContext();

            if (pSql == null || pSql.Trim() == "")
            {
                throw new ArgumentException("SQL이 비어 있습니다.", "pSql");
            }

            dialect = dbContext.Dialect();
            sql = ReplacePlaceholders(dialect, pSql, pParams);

            temp = new cJson();
            if (pKeyName == null || pKeyName.Trim() == "")
            {
                temp.KeyName = GetKeyName();
            }
            else
            {
                temp.KeyName = pKeyName;
            }

            cmd = dbContext.CreateCommand(sql);
            using (cmd)
            {
                BindParams(dialect, cmd, pParams);
                ReadRows(cmd, null, temp.Data());
            }

            lastSql = sql;
            return CommitLoaded(temp);
        }

        /// <summary>마지막 LoadSql / LoadSqlQuery가 실행한 SQL ({n}이 파라미터 이름으로 바뀐 것). 확인·로그용.</summary>
        public string GetLastSql()
        {
            return lastSql;
        }

        private void AssertDbContext()
        {
            if (dbContext == null)
            {
                throw new InvalidOperationException("DbContext가 없습니다. new cJson(pDbContext: ctx) 또는 SetDbContext(ctx)로 주입하세요.");
            }
        }

        /// <summary>
        /// GetSchema 결과(복사본)에서 화이트리스트 밖의 필드를 뺀다.
        /// ts, Binary 필드는 방언 LoadSchema에서 이미 빠지지만, 같은 규칙(SqlDialectUtil.IsExcludedField)으로 한 번 더 거른다.
        /// PK는 화이트리스트에 없어도 남긴다. 화이트리스트에 테이블에 없는 이름이 있으면 예외.
        /// </summary>
        private CrudSchema PrepareSchema(string pTableName, List<string> pWhitelist)
        {
            CrudSchema source;
            CrudSchema result;
            CrudField f;
            HashSet<string> names;
            bool isPk;
            int i;

            if (pTableName == null || pTableName.Trim() == "")
            {
                throw new ArgumentException("테이블 이름이 비어 있습니다.", "pTableName");
            }

            source = dbContext.GetSchema(pTableName);

            if (pWhitelist != null)
            {
                names = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
                for (i = 0; i < source.Fields.Count; i++)
                {
                    names.Add(source.Fields[i].Name);
                }
                for (i = 0; i < pWhitelist.Count; i++)
                {
                    if (!names.Contains(pWhitelist[i]))
                    {
                        throw new ArgumentException("화이트리스트의 필드 " + pWhitelist[i] + "가 테이블 " + pTableName + "에 없습니다.", "pWhitelist");
                    }
                }
            }

            result = new CrudSchema();
            result.TableName = source.TableName;
            result.PrimaryKey = source.PrimaryKey;

            for (i = 0; i < source.Fields.Count; i++)
            {
                f = source.Fields[i];
                isPk = source.PrimaryKey != null && string.Equals(f.Name, source.PrimaryKey, StringComparison.OrdinalIgnoreCase);

                if (SqlDialectUtil.IsExcludedField(f, source.PrimaryKey))
                {
                    continue;
                }
                if (pWhitelist != null && !isPk && !ContainsIgnoreCase(pWhitelist, f.Name))
                {
                    continue;
                }
                result.Fields.Add(f);
            }

            return result;
        }

        private static bool ContainsIgnoreCase(List<string> pList, string pName)
        {
            int i;

            for (i = 0; i < pList.Count; i++)
            {
                if (string.Equals(pList[i], pName, StringComparison.OrdinalIgnoreCase))
                {
                    return true;
                }
            }
            return false;
        }

        /// <summary>SELECT [col1], [col2] FROM [table] WHERE ... ORDER BY ... (ORDER BY가 없으면 PK)</summary>
        private static string BuildSelectSql(ISqlDialect pDialect, CrudSchema pSchema, string pWhere, string pOrderBy)
        {
            StringBuilder sb;
            int i;

            sb = new StringBuilder("SELECT ");
            for (i = 0; i < pSchema.Fields.Count; i++)
            {
                if (i > 0)
                {
                    sb.Append(", ");
                }
                sb.Append(pDialect.QuoteIdentifier(pSchema.Fields[i].Name));
            }
            sb.Append(" FROM ");
            sb.Append(pDialect.QuoteIdentifier(pSchema.TableName));

            if (pWhere != null && pWhere.Trim() != "")
            {
                sb.Append(" WHERE ");
                sb.Append(pWhere);
            }

            if (pOrderBy != null && pOrderBy.Trim() != "")
            {
                sb.Append(" ORDER BY ");
                sb.Append(pOrderBy);
            }
            else if (pSchema.PrimaryKey != null)
            {
                sb.Append(" ORDER BY ");
                sb.Append(pDialect.QuoteIdentifier(pSchema.PrimaryKey));
            }

            return sb.ToString();
        }

        /// <summary>
        /// {0}, {1} ... 을 방언의 파라미터 이름(@p0 ...)으로 바꾼다.
        /// 넘긴 값보다 큰 번호가 있으면 예외.
        /// </summary>
        private static string ReplacePlaceholders(ISqlDialect pDialect, string pSql, object[] pParams)
        {
            int count;

            if (pSql == null)
            {
                return "";
            }

            count = pParams == null ? 0 : pParams.Length;

            return Regex.Replace(pSql, @"\{(\d+)\}", delegate (Match m)
            {
                int index;

                index = int.Parse(m.Groups[1].Value, CultureInfo.InvariantCulture);
                if (index >= count)
                {
                    throw new ArgumentException("SQL의 {" + index.ToString() + "}에 넣을 값이 없습니다. (넘긴 값 " + count.ToString() + "개)", "pParams");
                }
                return pDialect.ParamName(index);
            });
        }

        private static void BindParams(ISqlDialect pDialect, DbCommand pCmd, object[] pParams)
        {
            int i;

            if (pParams == null)
            {
                return;
            }
            for (i = 0; i < pParams.Length; i++)
            {
                SqlDialectUtil.AddParam(pCmd, pDialect.ParamName(i), pParams[i]);
            }
        }

        /// <summary>
        /// 명령을 실행해 행마다 JsonObject를 만들어 pTarget에 넣는다.
        /// pSchema가 있으면 필드의 Kind로 bool 등을 판단하고, 없으면 .NET 타입으로 판단한다.
        /// byte[] 값은 넣지 않는다.
        /// </summary>
        private static void ReadRows(DbCommand pCmd, CrudSchema pSchema, JsonArray pTarget)
        {
            ReadRows(pCmd, pSchema, pTarget, null);
        }

        /// <summary>
        /// ReadRows와 같고, pColumnsOut이 null이 아니면 결과의 컬럼 정보(이름·종류)도 채운다.
        /// 행이 0건이어도 컬럼 정보는 채워진다 (LoadSqlQueryPage가 JSON "columns"를 만들 때 쓴다).
        /// </summary>
        private static void ReadRows(DbCommand pCmd, CrudSchema pSchema, JsonArray pTarget, List<CrudField> pColumnsOut)
        {
            DbDataReader reader;
            Dictionary<string, CrudFieldKind> kinds;
            JsonObject rec;
            object value;
            string name;
            CrudFieldKind kind;
            int i;

            kinds = new Dictionary<string, CrudFieldKind>(StringComparer.OrdinalIgnoreCase);
            if (pSchema != null)
            {
                for (i = 0; i < pSchema.Fields.Count; i++)
                {
                    kinds[pSchema.Fields[i].Name] = pSchema.Fields[i].Kind;
                }
            }

            reader = pCmd.ExecuteReader();
            using (reader)
            {
                if (pColumnsOut != null)
                {
                    ReadColumns(reader, pColumnsOut);
                }

                while (reader.Read())
                {
                    rec = new JsonObject();
                    for (i = 0; i < reader.FieldCount; i++)
                    {
                        name = reader.GetName(i);
                        value = reader.GetValue(i);
                        if (value is byte[])
                        {
                            continue;
                        }
                        if (!kinds.TryGetValue(name, out kind))
                        {
                            kind = CrudFieldKind.Other;
                        }
                        rec[name] = DbValueToNode(value, kind);
                    }
                    pTarget.Add(rec);
                }
            }
        }

        /// <summary>
        /// DB 값을 JSON 값으로 바꾼다.
        /// DBNull → null, 숫자 → 숫자, bool(또는 Kind = Bool) → true/false,
        /// DateTime → "yyyy-MM-dd HH:mm:ss" (밀리초가 있으면 ".fff"까지), 그 밖에는 문자열.
        /// </summary>
        private static JsonNode DbValueToNode(object pValue, CrudFieldKind pKind)
        {
            DateTime dt;
            DateTimeOffset dto;

            if (pValue == null || pValue is DBNull)
            {
                return null;
            }

            if (pValue is bool)
            {
                return JsonValue.Create((bool)pValue);
            }
            if (pKind == CrudFieldKind.Bool)
            {
                // MySQL tinyint(1) 등은 숫자로 온다.
                return JsonValue.Create(Convert.ToDouble(pValue, CultureInfo.InvariantCulture) != 0);
            }

            if (pValue is string) return JsonValue.Create((string)pValue);
            if (pValue is int) return JsonValue.Create((int)pValue);
            if (pValue is long) return JsonValue.Create((long)pValue);
            if (pValue is short) return JsonValue.Create((short)pValue);
            if (pValue is byte) return JsonValue.Create((byte)pValue);
            if (pValue is sbyte) return JsonValue.Create((sbyte)pValue);
            if (pValue is uint) return JsonValue.Create((uint)pValue);
            if (pValue is ulong) return JsonValue.Create((ulong)pValue);
            if (pValue is ushort) return JsonValue.Create((ushort)pValue);
            if (pValue is decimal) return JsonValue.Create((decimal)pValue);
            if (pValue is double) return JsonValue.Create((double)pValue);
            if (pValue is float) return JsonValue.Create((float)pValue);

            if (pValue is DateTime)
            {
                dt = (DateTime)pValue;
                if (dt.Millisecond != 0)
                {
                    return JsonValue.Create(dt.ToString("yyyy-MM-dd HH:mm:ss.fff", CultureInfo.InvariantCulture));
                }
                return JsonValue.Create(dt.ToString("yyyy-MM-dd HH:mm:ss", CultureInfo.InvariantCulture));
            }
            if (pValue is DateTimeOffset)
            {
                dto = (DateTimeOffset)pValue;
                return JsonValue.Create(dto.ToString("yyyy-MM-dd HH:mm:sszzz", CultureInfo.InvariantCulture));
            }

            return JsonValue.Create(Convert.ToString(pValue, CultureInfo.InvariantCulture));
        }

        /// <summary>임시로 채운 cJson을 검증한 뒤 이 객체의 내용으로 바꾼다. 읽은 행 수를 돌려준다.</summary>
        private int CommitLoaded(cJson pTemp)
        {
            JsonArray data;

            data = pTemp.Data();
            pTemp.doc["cursor"] = JsonValue.Create(data.Count > 0 ? 0 : -1);
            pTemp.doc["where"] = JsonValue.Create("");
            ValidateDoc(pTemp.doc);

            doc = pTemp.doc;
            whereFilter = null;
            return data.Count;
        }

        // ==================================================================
        // 값 쓰기
        // ==================================================================

        /// <summary>
        /// 키 레코드의 필드에 값을 쓴다.
        /// - 키 레코드가 없으면 data 끝에 새로 만들고 커서를 그 레코드로 옮긴다 (cXml과 같음).
        /// - 스키마가 탑재된 상태에서 schema.fields에 없는 필드면 예외.
        /// - 키가 비었으면 예외.
        /// - 키 필드 자체를 바꾸면: 빈 값이거나 다른 레코드와 중복이면 예외.
        /// pValue: null/DBNull은 JSON null, 문자열·숫자·bool·DateTime 등은 해당 JSON 값, JsonNode는 그대로.
        /// </summary>
        public cJson SetValue(string pKey, string pField, object pValue)
        {
            int index;
            JsonObject rec;
            string keyName;

            AssertKeyArgument(pKey);
            AssertWritableField(pField);

            keyName = GetKeyName();
            index = FindIndex(pKey);

            if (index < 0)
            {
                AssertWritableField(keyName);

                rec = new JsonObject();
                rec[keyName] = JsonValue.Create(pKey);
                Data().Add(rec);
                index = Data().Count - 1;
                SetCursorRaw(index);
            }

            WriteField(index, pField, pValue);
            return this;
        }

        /// <summary>
        /// 새 행을 만들고 커서를 그 행으로 옮긴다. 키 필드에 임시 키 "Pseudo0_n"을 넣고 그 키를 돌려준다.
        /// - 다른 필드는 비어 있다. 값은 SetValue(field, value)로 채운다 (커서가 새 행에 있다).
        /// - Crud에 붙이면(AttachBody, 또는 Crud.AddNew가 이 함수를 부름) 임시 키를 보고 새 행(INSERT)으로 알아본다.
        /// - 이미 있는 키와 겹치지 않는 번호를 고른다.
        /// </summary>
        public string AddNew()
        {
            string key;

            do
            {
                newKeySeq++;
                key = NewKeyPrefix + newKeySeq.ToString();
            }
            while (ExistsKey(key));

            SetValue(key, GetKeyName(), key);           // 없는 키 → data 끝에 새 행 + 커서 이동 (SetValue 동작)
            return key;
        }

        /// <summary>현재 커서 레코드의 필드에 값을 쓴다. 커서가 BOF/EOF면 예외.</summary>
        public cJson SetValue(string pField, object pValue)
        {
            AssertWritableField(pField);

            if (!IsCursorValid())
            {
                throw new InvalidOperationException("커서가 BOF/EOF에 있어 쓸 레코드가 없습니다. 키를 지정하거나 커서를 레코드로 옮기세요. (필드: " + pField + ")");
            }

            WriteField(GetCursor(), pField, pValue);
            return this;
        }

        // ==================================================================
        // 값 읽기
        // ==================================================================

        /// <summary>
        /// 키 레코드의 필드 값을 문자열로 읽는다 (cXml과 같음).
        /// 레코드·필드가 없거나 JSON null이면 "". 숫자·bool은 JSON 표기 그대로("1000", "0.5", "true").
        /// null과 "없음"을 구분하려면 IsNullValue()를 쓴다.
        /// </summary>
        public string GetValue(string pKey, string pField)
        {
            JsonNode node;

            TryGetNodeByKey(pKey, pField, out node);
            return ToText(node);
        }

        /// <summary>현재 커서 레코드의 필드 값을 문자열로 읽는다. BOF/EOF면 "".</summary>
        public string GetValue(string pField)
        {
            JsonNode node;

            TryGetNodeAtCursor(pField, out node);
            return ToText(node);
        }

        /// <summary>
        /// 키 레코드에 필드가 있고 그 값이 JSON null이면 true.
        /// 레코드나 필드가 없으면 false (GetValue는 둘 다 ""라서 구분이 안 됨).
        /// </summary>
        public bool IsNullValue(string pKey, string pField)
        {
            JsonNode node;

            return TryGetNodeByKey(pKey, pField, out node) && node == null;
        }

        /// <summary>현재 커서 레코드에 필드가 있고 그 값이 JSON null이면 true. BOF/EOF면 false.</summary>
        public bool IsNullValue(string pField)
        {
            JsonNode node;

            return TryGetNodeAtCursor(pField, out node) && node == null;
        }

        /// <summary>숫자로 읽는다. 문자열은 천단위 콤마를 지우고 변환한다. 실패하면 0.</summary>
        public double GetDouble(string pKey, string pField)
        {
            JsonNode node;

            TryGetNodeByKey(pKey, pField, out node);
            return ToDouble(node);
        }

        /// <summary>현재 커서 레코드의 값을 숫자로 읽는다.</summary>
        public double GetDouble(string pField)
        {
            JsonNode node;

            TryGetNodeAtCursor(pField, out node);
            return ToDouble(node);
        }

        /// <summary>
        /// bool로 읽는다. true / 0이 아닌 숫자 / 문자열 "true","1","-1","y","yes"는 true, 그 외는 false.
        /// </summary>
        public bool GetBool(string pKey, string pField)
        {
            JsonNode node;

            TryGetNodeByKey(pKey, pField, out node);
            return ToBool(node);
        }

        /// <summary>현재 커서 레코드의 값을 bool로 읽는다.</summary>
        public bool GetBool(string pField)
        {
            JsonNode node;

            TryGetNodeAtCursor(pField, out node);
            return ToBool(node);
        }

        /// <summary>키 레코드 존재 여부 (cXml의 exists_key). where와 관계없이 전체에서 찾는다.</summary>
        public bool ExistsKey(string pKey)
        {
            return FindIndex(pKey) >= 0;
        }

        /// <summary>현재 레코드의 필드 이름 목록 (cXml의 GET_attributes_currentnode). BOF/EOF면 "".</summary>
        public string GetCurrentFields(string pSep = ",")
        {
            StringBuilder sb;

            if (!IsCursorValid())
            {
                return "";
            }

            sb = new StringBuilder();
            foreach (KeyValuePair<string, JsonNode> p in Data()[GetCursor()].AsObject())
            {
                if (sb.Length > 0)
                {
                    sb.Append(pSep);
                }
                sb.Append(p.Key);
            }
            return sb.ToString();
        }

        /// <summary>
        /// 다른 cJson의 레코드를 키 기준으로 반영한다 (cXml의 modify_xml).
        /// pAddIfMissing = true면 없는 키는 새로 만들고, false면 있는 키만 고친다.
        /// 스키마 위반 필드가 하나라도 있으면 아무것도 바꾸지 않고 예외. 커서는 원래 위치로 되돌린다.
        /// 반영한 레코드 수를 돌려준다.
        /// </summary>
        public int Merge(cJson pOther, bool pAddIfMissing = true)
        {
            JsonArray source;
            string otherKeyName;
            string key;
            int savedCursor;
            int count;
            int i;
            JsonObject rec;

            if (pOther == null)
            {
                throw new ArgumentNullException("pOther");
            }

            // 자기 자신을 넘겨도 안전하도록 사본을 순회한다.
            source = (JsonArray)pOther.Data().DeepClone();
            otherKeyName = pOther.GetKeyName();

            // 1. 먼저 전부 검증
            for (i = 0; i < source.Count; i++)
            {
                rec = source[i].AsObject();
                key = ToText(rec[otherKeyName]);
                if (!pAddIfMissing && FindIndex(key) < 0)
                {
                    continue;
                }
                foreach (KeyValuePair<string, JsonNode> p in rec)
                {
                    if (p.Key != otherKeyName)
                    {
                        AssertWritableField(p.Key);
                    }
                }
            }

            // 2. 반영
            savedCursor = GetCursor();
            count = 0;

            for (i = 0; i < source.Count; i++)
            {
                rec = source[i].AsObject();
                key = ToText(rec[otherKeyName]);

                if (FindIndex(key) < 0)
                {
                    if (!pAddIfMissing)
                    {
                        continue;
                    }
                    SetValue(key, GetKeyName(), key);
                }

                foreach (KeyValuePair<string, JsonNode> p in rec)
                {
                    if (p.Key != otherKeyName)
                    {
                        SetValue(key, p.Key, p.Value);
                    }
                }
                count++;
            }

            SetCursorRaw(savedCursor);
            return count;
        }

        /// <summary>
        /// 레코드들을 data 끝에 추가한다 (cXml의 insert_node_from_string).
        /// pJson: 레코드 배열 [ {...} ], 레코드 하나 {...}, 또는 cJson 형식 {"data": [...]}.
        /// 키 누락·중복(기존 또는 추가분끼리)·스키마 위반이 하나라도 있으면 아무것도 추가하지 않고 예외.
        /// 커서는 움직이지 않는다 (EOF였으면 EOF 유지). 추가한 레코드 수를 돌려준다.
        /// </summary>
        public int AppendRecords(string pJson)
        {
            JsonNode parsed;
            JsonArray source;
            JsonObject obj;
            JsonObject rec;
            JsonNode inner;
            HashSet<string> keys;
            string keyName;
            string key;
            bool wasEof;
            int i;

            if (pJson == null || pJson.Trim() == "")
            {
                return 0;
            }

            try
            {
                parsed = JsonNode.Parse(pJson);
            }
            catch (JsonException ex)
            {
                throw new ArgumentException("JSON 형식이 올바르지 않습니다: " + ex.Message, "pJson", ex);
            }

            if (parsed is JsonArray)
            {
                source = (JsonArray)parsed;
            }
            else if (parsed is JsonObject)
            {
                obj = (JsonObject)parsed;
                if (obj.TryGetPropertyValue("data", out inner) && inner is JsonArray)
                {
                    source = (JsonArray)inner;
                }
                else
                {
                    source = new JsonArray();
                    source.Add(obj);
                }
            }
            else
            {
                throw new ArgumentException("레코드 배열 또는 객체여야 합니다.", "pJson");
            }

            // 1. 검증
            keyName = GetKeyName();
            keys = new HashSet<string>();
            for (i = 0; i < Data().Count; i++)
            {
                keys.Add(ToText(Data()[i][keyName]));
            }

            for (i = 0; i < source.Count; i++)
            {
                rec = source[i] as JsonObject;
                if (rec == null)
                {
                    throw new ArgumentException("추가할 " + i.ToString() + "번째 항목이 객체가 아닙니다.", "pJson");
                }
                key = ToText(rec[keyName]);
                if (key == "")
                {
                    throw new ArgumentException("추가할 " + i.ToString() + "번째 레코드에 키 필드(" + keyName + ") 값이 없습니다.", "pJson");
                }
                if (!keys.Add(key))
                {
                    throw new ArgumentException("키가 중복됩니다: " + key, "pJson");
                }
                foreach (KeyValuePair<string, JsonNode> p in rec)
                {
                    AssertWritableField(p.Key);
                }
            }

            // 2. 추가
            wasEof = GetCursor() >= Data().Count && Data().Count > 0;
            for (i = 0; i < source.Count; i++)
            {
                Data().Add(source[i].DeepClone());
            }
            if (wasEof)
            {
                SetCursorRaw(Data().Count);
            }

            return source.Count;
        }

        // ==================================================================
        // 커서
        // where가 있으면 조건에 맞는 레코드 안에서만 이동한다.
        // ==================================================================

        /// <summary>첫 레코드로. 레코드가 없으면 EOF.</summary>
        public void MoveFirst()
        {
            SetCursorRaw(NextIndex(-1));
        }

        /// <summary>마지막 레코드로. 레코드가 없으면 BOF.</summary>
        public void MoveLast()
        {
            SetCursorRaw(PrevIndex(Data().Count));
        }

        /// <summary>다음 레코드로. 더 없으면 EOF.</summary>
        public void MoveNext()
        {
            int cursor;

            cursor = GetCursor();
            if (cursor >= Data().Count)
            {
                return;
            }
            SetCursorRaw(NextIndex(cursor));
        }

        /// <summary>이전 레코드로. 더 없으면 BOF.</summary>
        public void MovePrevious()
        {
            int cursor;

            cursor = GetCursor();
            if (cursor < 0)
            {
                return;
            }
            SetCursorRaw(PrevIndex(cursor));
        }

        /// <summary>
        /// 키 레코드로 이동한다 (cXml의 move_cursor, setCursor).
        /// 없거나 where 조건에 맞지 않으면 false이고 커서는 그대로다.
        /// </summary>
        public bool MoveToKey(string pKey)
        {
            int index;

            index = FindIndex(pKey);
            if (index < 0 || !Matches(index))
            {
                return false;
            }
            SetCursorRaw(index);
            return true;
        }

        /// <summary>
        /// where 결과 안에서 pIndex번째(0부터) 레코드로 이동한다 (cXml의 MOVE_Cursor_ItemNumber).
        /// where가 없으면 data 인덱스와 같다. 범위를 벗어나면 BOF(음수) 또는 EOF로 가고 false.
        /// </summary>
        public bool MoveTo(int pIndex)
        {
            JsonArray data;
            int seen;
            int i;

            data = Data();
            if (pIndex < 0)
            {
                SetCursorRaw(-1);
                return false;
            }

            seen = 0;
            for (i = 0; i < data.Count; i++)
            {
                if (Matches(i))
                {
                    if (seen == pIndex)
                    {
                        SetCursorRaw(i);
                        return true;
                    }
                    seen++;
                }
            }

            SetCursorRaw(data.Count);
            return false;
        }

        /// <summary>커서가 마지막 레코드 다음(또는 레코드 없음)인지</summary>
        public bool EOF()
        {
            return Data().Count == 0 || GetCursor() >= Data().Count;
        }

        /// <summary>커서가 첫 레코드 이전(또는 레코드 없음)인지</summary>
        public bool BOF()
        {
            return Data().Count == 0 || GetCursor() < 0;
        }

        /// <summary>현재 커서. data 안의 인덱스이며 -1 = BOF, data 개수 = EOF.</summary>
        public int GetCursor()
        {
            return doc["cursor"].GetValue<int>();
        }

        /// <summary>현재 레코드의 키 값 (cXml의 get_cursor). BOF/EOF면 "".</summary>
        public string GetCurrentKey()
        {
            if (!IsCursorValid())
            {
                return "";
            }
            return ToText(Data()[GetCursor()][GetKeyName()]);
        }

        /// <summary>레코드 수. where가 있으면 조건에 맞는 수.</summary>
        public int Count()
        {
            int count;
            int i;

            if (whereFilter == null)
            {
                return Data().Count;
            }

            count = 0;
            for (i = 0; i < Data().Count; i++)
            {
                if (Matches(i))
                {
                    count++;
                }
            }
            return count;
        }

        /// <summary>레코드가 없는지 (cXml의 IsNull). where가 있으면 조건 기준.</summary>
        public bool IsEmpty()
        {
            return Count() == 0;
        }

        // ==================================================================
        // 필터 / 삭제
        // ==================================================================

        /// <summary>
        /// where 조건식을 걸고 MoveFirst()한다 (cXml의 SET_Value_Where).
        /// 조건식은 JSON의 "where"에 저장되므로 ToJson → Load 하면 필터도 그대로 다시 걸린다.
        /// 문법은 cJsonWhere 참고. 예:
        ///   SetWhere("kind = '과일' and price >= 1000")
        ///   SetWhere("contains(@분류, '안주')")
        /// 빈 문자열이면 ClearWhere()와 같다.
        /// 문법이 틀리거나, 스키마가 탑재됐는데 schema.fields에 없는 필드를 쓰면 예외 (상태는 그대로).
        /// </summary>
        public void SetWhere(string pWhere)
        {
            Func<JsonObject, bool> compiled;

            if (pWhere == null || pWhere.Trim() == "")
            {
                ClearWhere();
                return;
            }

            compiled = CompileWhere(pWhere, IsLoadedSchema(), Fields());

            doc["where"] = JsonValue.Create(pWhere);
            whereFilter = compiled;
            MoveFirst();
        }

        /// <summary>where를 해제한다 (JSON의 "where"도 ""). 커서는 그대로 둔다.</summary>
        public void ClearWhere()
        {
            doc["where"] = JsonValue.Create("");
            whereFilter = null;
        }

        /// <summary>where가 걸려 있는지</summary>
        public bool HasWhere()
        {
            return whereFilter != null;
        }

        /// <summary>현재 where 조건식. 없으면 "".</summary>
        public string GetWhere()
        {
            return ToText(doc["where"]);
        }

        /// <summary>
        /// 키 레코드를 삭제한다. 없으면 false.
        /// 현재 레코드를 지우면 커서는 다음 레코드(없으면 EOF)로 간다.
        /// </summary>
        public bool Delete(string pKey)
        {
            int index;
            List<int> list;

            index = FindIndex(pKey);
            if (index < 0)
            {
                return false;
            }

            list = new List<int>();
            list.Add(index);
            RemoveIndexes(list);
            return true;
        }

        /// <summary>where에 맞는 레코드를 전부 삭제한다. where가 없으면 전부. 지운 수를 돌려준다.</summary>
        public int DeleteWhere()
        {
            List<int> list;
            int i;

            list = new List<int>();
            for (i = 0; i < Data().Count; i++)
            {
                if (Matches(i))
                {
                    list.Add(i);
                }
            }
            RemoveIndexes(list);
            return list.Count;
        }

        /// <summary>data를 비우고 커서를 -1로 둔다. 스키마와 where는 유지한다.</summary>
        public void Clear()
        {
            Data().Clear();
            SetCursorRaw(-1);
        }

        // ==================================================================
        // Pseudo (임시 레코드 표시: 키 앞에 "Pseudo{type}_"를 붙인다)
        // ==================================================================

        /// <summary>
        /// where에 맞는 레코드의 키 앞에 "Pseudo{type}_"를 붙인다 (cXml의 LOAD_Pseudo, CONVERT_Pseudo).
        /// 이미 같은 접두어가 붙은 키는 건너뛴다. 바뀐 키가 다른 키와 겹치면 아무것도 바꾸지 않고 예외.
        /// 커서는 인덱스라서 키가 바뀌어도 그대로다. 바꾼 수를 돌려준다.
        /// type "0"은 새 행 임시 키(NewKeyPrefix) 전용이라 쓸 수 없다 (예외). 기본값은 "1".
        /// </summary>
        public int LoadPseudo(string pType = "1")
        {
            string prefix;
            string keyName;
            string key;
            Dictionary<int, string> renames;
            HashSet<string> finalKeys;
            JsonArray data;
            int i;

            if (pType == "0")
            {
                // "Pseudo0_"는 Crud가 새 행으로 보는 접두어다. 기존 행에 붙이면 저장할 때 같은 행이 한 번 더 INSERT된다.
                throw new ArgumentException("Pseudo type \"0\"은 새 행 임시 키(AddNew) 전용입니다. 다른 type을 쓰세요 (기본값 \"1\").", "pType");
            }

            prefix = PseudoPrefix + (pType == null ? "" : pType) + "_";
            keyName = GetKeyName();
            data = Data();
            renames = new Dictionary<int, string>();

            for (i = 0; i < data.Count; i++)
            {
                if (!Matches(i))
                {
                    continue;
                }
                key = ToText(data[i][keyName]);
                if (!key.StartsWith(prefix, StringComparison.Ordinal))
                {
                    renames[i] = prefix + key;
                }
            }

            finalKeys = new HashSet<string>();
            for (i = 0; i < data.Count; i++)
            {
                if (renames.ContainsKey(i))
                {
                    key = renames[i];
                }
                else
                {
                    key = ToText(data[i][keyName]);
                }
                if (!finalKeys.Add(key))
                {
                    throw new InvalidOperationException("Pseudo 키로 바꾸면 키가 중복됩니다: " + key);
                }
            }

            foreach (KeyValuePair<int, string> p in renames)
            {
                data[p.Key][keyName] = JsonValue.Create(p.Value);
            }
            return renames.Count;
        }

        /// <summary>
        /// 현재 레코드가 Pseudo인지. pType이 ""이면 종류와 관계없이 "Pseudo"로 시작하면 true.
        /// IsCurrentPseudo("0") = 현재 행이 AddNew로 만든 새 행인지. 기본값은 LoadPseudo와 같은 "1".
        /// </summary>
        public bool IsCurrentPseudo(string pType = "1")
        {
            if (!IsCursorValid())
            {
                return false;
            }
            return IsPseudoKey(GetCurrentKey(), pType);
        }

        /// <summary>
        /// where에 맞는 레코드 중 Pseudo 레코드를 삭제한다 (cXml의 DELETE_Pseudo). 지운 수를 돌려준다.
        /// DeletePseudo("0") = 저장 안 한 새 행(AddNew)을 버린다. 기본값은 LoadPseudo와 같은 "1".
        /// </summary>
        public int DeletePseudo(string pType = "1")
        {
            List<int> list;
            string keyName;
            int i;

            keyName = GetKeyName();
            list = new List<int>();
            for (i = 0; i < Data().Count; i++)
            {
                if (Matches(i) && IsPseudoKey(ToText(Data()[i][keyName]), pType))
                {
                    list.Add(i);
                }
            }
            RemoveIndexes(list);
            return list.Count;
        }

        // ==================================================================
        // 공용 변환 (where 람다 안에서도 쓴다)
        // ==================================================================

        /// <summary>JSON 값을 문자열로. null/없음은 "", 문자열은 그대로, 그 외는 JSON 표기.</summary>
        public static string ToText(JsonNode pNode)
        {
            if (pNode == null)
            {
                return "";
            }
            if (pNode.GetValueKind() == JsonValueKind.String)
            {
                return pNode.GetValue<string>();
            }
            return pNode.ToJsonString(jsonCompact);
        }

        /// <summary>JSON 값을 숫자로. 실패하면 0.</summary>
        public static double ToDouble(JsonNode pNode)
        {
            JsonValueKind kind;
            double d;
            string s;

            if (pNode == null)
            {
                return 0;
            }

            kind = pNode.GetValueKind();
            if (kind == JsonValueKind.Number)
            {
                return double.Parse(pNode.ToJsonString(), NumberStyles.Float, CultureInfo.InvariantCulture);
            }
            if (kind == JsonValueKind.True)
            {
                return 1;
            }
            if (kind == JsonValueKind.String)
            {
                s = pNode.GetValue<string>().Trim().Replace(",", "");
                if (double.TryParse(s, NumberStyles.Float, CultureInfo.InvariantCulture, out d))
                {
                    return d;
                }
            }
            return 0;
        }

        /// <summary>JSON 값을 bool로. true / 0이 아닌 숫자 / "true","1","-1","y","yes"는 true.</summary>
        public static bool ToBool(JsonNode pNode)
        {
            JsonValueKind kind;
            string s;

            if (pNode == null)
            {
                return false;
            }

            kind = pNode.GetValueKind();
            if (kind == JsonValueKind.True)
            {
                return true;
            }
            if (kind == JsonValueKind.Number)
            {
                return ToDouble(pNode) != 0;
            }
            if (kind == JsonValueKind.String)
            {
                s = pNode.GetValue<string>().Trim().ToLowerInvariant();
                return s == "true" || s == "1" || s == "-1" || s == "y" || s == "yes";
            }
            return false;
        }

        // ==================================================================
        // 내부
        // ==================================================================

        private JsonObject Schema()
        {
            return doc["schema"].AsObject();
        }

        private JsonObject Fields()
        {
            return Schema()["fields"].AsObject();
        }

        private JsonArray Data()
        {
            return doc["data"].AsArray();
        }

        /// <summary>
        /// Crud 전용: data 배열 원본 (사본 아님). Crud가 원본 사본과 비교할 때만 쓴다.
        /// 여기서 직접 고치면 키 검사 등을 거치지 않으므로 읽기만 한다.
        /// </summary>
        internal JsonArray CrudData()
        {
            return Data();
        }

        private string GetKeyName()
        {
            return KeyNameOf(doc);
        }

        private static string KeyNameOf(JsonObject pDoc)
        {
            string s;

            s = ToText(pDoc["schema"]["primaryKey"]);
            if (s.Trim() == "")
            {
                return DefaultKeyName;
            }
            return s;
        }

        private void SetCursorRaw(int pCursor)
        {
            int count;

            count = Data().Count;
            if (count == 0)
            {
                pCursor = -1;
            }
            else if (pCursor < -1)
            {
                pCursor = -1;
            }
            else if (pCursor > count)
            {
                pCursor = count;
            }
            doc["cursor"] = JsonValue.Create(pCursor);
        }

        private bool IsCursorValid()
        {
            int cursor;

            cursor = GetCursor();
            return cursor >= 0 && cursor < Data().Count;
        }

        private bool Matches(int pIndex)
        {
            if (whereFilter == null)
            {
                return true;
            }
            return whereFilter(Data()[pIndex].AsObject());
        }

        /// <summary>pFrom 다음부터 조건에 맞는 첫 인덱스. 없으면 data 개수(EOF).</summary>
        private int NextIndex(int pFrom)
        {
            int i;

            for (i = pFrom + 1; i < Data().Count; i++)
            {
                if (Matches(i))
                {
                    return i;
                }
            }
            return Data().Count;
        }

        /// <summary>pFrom 이전에서 조건에 맞는 마지막 인덱스. 없으면 -1(BOF).</summary>
        private int PrevIndex(int pFrom)
        {
            int i;

            for (i = pFrom - 1; i >= 0; i--)
            {
                if (Matches(i))
                {
                    return i;
                }
            }
            return -1;
        }

        /// <summary>키로 data 인덱스를 찾는다 (where 무시). 없으면 -1.</summary>
        private int FindIndex(string pKey)
        {
            JsonArray data;
            string keyName;
            int i;

            if (pKey == null || pKey == "")
            {
                return -1;
            }

            data = Data();
            keyName = GetKeyName();
            for (i = 0; i < data.Count; i++)
            {
                if (ToText(data[i][keyName]) == pKey)
                {
                    return i;
                }
            }
            return -1;
        }

        private bool TryGetNodeByKey(string pKey, string pField, out JsonNode pNode)
        {
            int index;

            pNode = null;
            if (pField == null || pField == "")
            {
                return false;
            }
            index = FindIndex(pKey);
            if (index < 0)
            {
                return false;
            }
            return Data()[index].AsObject().TryGetPropertyValue(pField, out pNode);
        }

        private bool TryGetNodeAtCursor(string pField, out JsonNode pNode)
        {
            pNode = null;
            if (pField == null || pField == "" || !IsCursorValid())
            {
                return false;
            }
            return Data()[GetCursor()].AsObject().TryGetPropertyValue(pField, out pNode);
        }

        private void AssertKeyArgument(string pKey)
        {
            if (pKey == null || pKey.Trim() == "")
            {
                throw new ArgumentException("키 값이 비어 있습니다. 현재 레코드에 쓰려면 SetValue(field, value)를 쓰세요.", "pKey");
            }
        }

        /// <summary>필드 이름이 비었거나, 스키마가 탑재됐는데 schema.fields에 없으면 예외.</summary>
        private void AssertWritableField(string pField)
        {
            if (pField == null || pField.Trim() == "")
            {
                throw new ArgumentException("필드 이름이 비어 있습니다.", "pField");
            }
            if (IsLoadedSchema() && !Fields().ContainsKey(pField))
            {
                throw new ArgumentException("스키마에 없는 필드입니다: " + pField + " (테이블: " + ToText(Schema()["tableName"]) + ")", "pField");
            }
        }

        private void WriteField(int pIndex, string pField, object pValue)
        {
            JsonNode node;
            string newKey;
            int other;

            node = ToNode(pValue);

            if (pField == GetKeyName())
            {
                newKey = ToText(node);
                if (newKey.Trim() == "")
                {
                    throw new ArgumentException("키 필드(" + pField + ")에 빈 값을 쓸 수 없습니다.", "pValue");
                }
                other = FindIndex(newKey);
                if (other >= 0 && other != pIndex)
                {
                    throw new ArgumentException("키가 중복됩니다: " + newKey, "pValue");
                }
            }

            Data()[pIndex].AsObject()[pField] = node;
        }

        /// <summary>오름차순 인덱스 목록을 지우고 커서를 보정한다.</summary>
        private void RemoveIndexes(List<int> pIndexes)
        {
            int cursor;
            int removedBefore;
            bool currentRemoved;
            int i;
            int index;

            if (pIndexes.Count == 0)
            {
                return;
            }

            cursor = GetCursor();
            removedBefore = 0;
            currentRemoved = false;

            for (i = pIndexes.Count - 1; i >= 0; i--)
            {
                index = pIndexes[i];
                Data().RemoveAt(index);
                if (index < cursor)
                {
                    removedBefore++;
                }
                else if (index == cursor)
                {
                    currentRemoved = true;
                }
            }

            cursor = cursor - removedBefore;

            if (Data().Count == 0)
            {
                cursor = -1;
            }
            else if (currentRemoved)
            {
                // 지운 자리부터 조건에 맞는 다음 레코드 (없으면 EOF)
                cursor = NextIndex(cursor - 1);
            }

            SetCursorRaw(cursor);
        }

        private static bool IsPseudoKey(string pKey, string pType)
        {
            string prefix;

            if (pType == null || pType == "")
            {
                prefix = PseudoPrefix;
            }
            else
            {
                prefix = PseudoPrefix + pType + "_";
            }
            return pKey.StartsWith(prefix, StringComparison.Ordinal);
        }

        private static JsonNode ToNode(object pValue)
        {
            JsonNode node;

            if (pValue == null || pValue is DBNull)
            {
                return null;
            }

            node = pValue as JsonNode;
            if (node != null)
            {
                if (node.Parent != null)
                {
                    return node.DeepClone();
                }
                return node;
            }

            return JsonSerializer.SerializeToNode(pValue, pValue.GetType(), jsonCompact);
        }

        private static JsonObject CreateEmptySchema(string pKeyName)
        {
            JsonObject schema;

            schema = new JsonObject();
            schema["tableName"] = JsonValue.Create("");
            schema["primaryKey"] = JsonValue.Create(pKeyName);
            schema["fields"] = new JsonObject();
            return schema;
        }

        private static JsonObject CreateEmptyDoc(string pKeyName)
        {
            JsonObject d;

            d = new JsonObject();
            d["isLoadedSchema"] = JsonValue.Create(false);
            d["schema"] = CreateEmptySchema(pKeyName);
            d["cursor"] = JsonValue.Create(-1);
            d["where"] = JsonValue.Create("");
            d["data"] = new JsonArray();
            return d;
        }

        /// <summary>schema 객체에 빠진 키(tableName, primaryKey, fields)를 채운다.</summary>
        private static void NormalizeSchema(JsonObject pSchema)
        {
            if (!pSchema.ContainsKey("tableName") || pSchema["tableName"] == null)
            {
                pSchema["tableName"] = JsonValue.Create("");
            }
            if (!pSchema.ContainsKey("primaryKey") || pSchema["primaryKey"] == null)
            {
                pSchema["primaryKey"] = JsonValue.Create(DefaultKeyName);
            }
            if (!pSchema.ContainsKey("fields") || pSchema["fields"] == null)
            {
                pSchema["fields"] = new JsonObject();
            }
            if (!(pSchema["fields"] is JsonObject))
            {
                throw new ArgumentException("schema.fields는 필드 이름을 키로 하는 객체여야 합니다.");
            }
        }

        /// <summary>
        /// 외부 JSON 객체를 {isLoadedSchema, schema, cursor, data, (그 밖의 키)} 순서로 다시 만든다.
        /// </summary>
        private static JsonObject NormalizeDoc(JsonObject pSrc)
        {
            JsonObject result;
            JsonObject schema;
            JsonArray data;
            JsonNode schemaNode;
            JsonNode loadedNode;
            JsonNode cursorNode;
            JsonNode dataNode;
            JsonNode whereNode;
            List<string> restKeys;
            JsonValueKind kind;
            bool loaded;
            int cursor;
            string whereText;

            schemaNode = Take(pSrc, "schema");
            loadedNode = Take(pSrc, "isLoadedSchema");
            cursorNode = Take(pSrc, "cursor");
            whereNode = Take(pSrc, "where");
            dataNode = Take(pSrc, "data");

            if (schemaNode == null && loadedNode == null && cursorNode == null && whereNode == null && dataNode == null)
            {
                throw new ArgumentException("cJson 형식이 아닙니다. (isLoadedSchema / schema / cursor / where / data 키가 하나도 없음)");
            }

            // where: 없으면 "" (PHP Crud JSON 호환). 해석은 ValidateDoc에서 한다.
            if (whereNode == null)
            {
                whereText = "";
            }
            else if (whereNode.GetValueKind() == JsonValueKind.String)
            {
                whereText = whereNode.GetValue<string>();
            }
            else
            {
                throw new ArgumentException("where는 문자열이어야 합니다.");
            }

            // schema
            if (schemaNode == null)
            {
                schema = CreateEmptySchema(DefaultKeyName);
            }
            else
            {
                schema = schemaNode as JsonObject;
                if (schema == null)
                {
                    throw new ArgumentException("schema는 객체여야 합니다.");
                }
                NormalizeSchema(schema);
            }

            // data
            if (dataNode == null)
            {
                data = new JsonArray();
            }
            else
            {
                data = dataNode as JsonArray;
                if (data == null)
                {
                    throw new ArgumentException("data는 배열이어야 합니다.");
                }
            }

            // isLoadedSchema: 없으면 fields가 있을 때 true (PHP Crud JSON 호환)
            if (loadedNode == null)
            {
                loaded = schema["fields"].AsObject().Count > 0;
            }
            else
            {
                kind = loadedNode.GetValueKind();
                if (kind == JsonValueKind.True)
                {
                    loaded = true;
                }
                else if (kind == JsonValueKind.False)
                {
                    loaded = false;
                }
                else
                {
                    throw new ArgumentException("isLoadedSchema는 true/false여야 합니다.");
                }
            }

            // cursor: 없으면 레코드가 있으면 0, 없으면 -1
            if (cursorNode == null)
            {
                cursor = data.Count > 0 ? 0 : -1;
            }
            else
            {
                if (cursorNode.GetValueKind() != JsonValueKind.Number
                    || !int.TryParse(cursorNode.ToJsonString(), NumberStyles.Integer, CultureInfo.InvariantCulture, out cursor))
                {
                    throw new ArgumentException("cursor는 정수여야 합니다.");
                }
            }
            if (data.Count == 0 || cursor < -1)
            {
                cursor = -1;
            }
            else if (cursor > data.Count)
            {
                cursor = data.Count;
            }

            result = new JsonObject();
            result["isLoadedSchema"] = JsonValue.Create(loaded);
            result["schema"] = schema;
            result["cursor"] = JsonValue.Create(cursor);
            result["where"] = JsonValue.Create(whereText);
            result["data"] = data;

            // 모르는 최상위 키는 버리지 않고 뒤에 붙인다.
            restKeys = new List<string>();
            foreach (KeyValuePair<string, JsonNode> p in pSrc)
            {
                restKeys.Add(p.Key);
            }
            foreach (string k in restKeys)
            {
                JsonNode v;
                pSrc.TryGetPropertyValue(k, out v);
                pSrc.Remove(k);
                result[k] = v;
            }

            return result;
        }

        /// <summary>
        /// where 조건식을 해석한다. 스키마가 탑재됐으면 식에 나온 필드가 schema.fields에 있는지도 본다.
        /// 틀리면 ArgumentException.
        /// </summary>
        private static Func<JsonObject, bool> CompileWhere(string pWhere, bool pLoaded, JsonObject pFields)
        {
            Func<JsonObject, bool> compiled;
            List<string> used;
            int i;

            compiled = cJsonWhere.Compile(pWhere, out used);

            if (pLoaded)
            {
                for (i = 0; i < used.Count; i++)
                {
                    if (!pFields.ContainsKey(used[i]))
                    {
                        throw new ArgumentException("where 조건식의 필드 " + used[i] + "가 스키마에 없습니다.  식: " + pWhere);
                    }
                }
            }
            return compiled;
        }

        /// <summary>
        /// data의 레코드가 객체인지, 키가 있고 중복이 없는지, 스키마 탑재 시 필드가 스키마에 있는지 검사한다.
        /// where 조건식도 해석해서 돌려준다 (없으면 null).
        /// </summary>
        private static Func<JsonObject, bool> ValidateDoc(JsonObject pDoc)
        {
            string whereText;
            string keyName;
            bool loaded;
            JsonObject fields;
            JsonArray data;
            JsonObject rec;
            HashSet<string> keys;
            string key;
            int i;

            keyName = KeyNameOf(pDoc);
            loaded = pDoc["isLoadedSchema"].GetValue<bool>();
            fields = pDoc["schema"]["fields"].AsObject();
            data = pDoc["data"].AsArray();
            keys = new HashSet<string>();

            for (i = 0; i < data.Count; i++)
            {
                rec = data[i] as JsonObject;
                if (rec == null)
                {
                    throw new ArgumentException("data[" + i.ToString() + "]가 객체가 아닙니다.");
                }

                key = ToText(rec[keyName]);
                if (key.Trim() == "")
                {
                    throw new ArgumentException("data[" + i.ToString() + "]에 키 필드(" + keyName + ") 값이 없습니다.");
                }
                if (!keys.Add(key))
                {
                    throw new ArgumentException("키가 중복됩니다: " + key);
                }

                if (loaded)
                {
                    foreach (KeyValuePair<string, JsonNode> p in rec)
                    {
                        if (!fields.ContainsKey(p.Key))
                        {
                            throw new ArgumentException("data[" + i.ToString() + "]의 필드 " + p.Key + "가 스키마에 없습니다.");
                        }
                    }
                }
            }

            whereText = ToText(pDoc["where"]);
            if (whereText.Trim() == "")
            {
                return null;
            }
            return CompileWhere(whereText, loaded, fields);
        }

        private static JsonNode Take(JsonObject pObj, string pName)
        {
            JsonNode node;

            if (pObj.TryGetPropertyValue(pName, out node))
            {
                pObj.Remove(pName);
                return node;
            }
            return null;
        }

        private static JsonSerializerOptions CreateOptions(bool pIndented)
        {
            JsonSerializerOptions o;

            o = new JsonSerializerOptions();
            o.WriteIndented = pIndented;
            // 한글을 \uXXXX로 바꾸지 않는다 (PHP JSON_UNESCAPED_UNICODE와 같음).
            o.Encoder = JavaScriptEncoder.UnsafeRelaxedJsonEscaping;
            return o;
        }

        private static JsonSerializerOptions GetOptions(bool pIndented)
        {
            if (pIndented)
            {
                return jsonIndented;
            }
            return jsonCompact;
        }
    }
}
