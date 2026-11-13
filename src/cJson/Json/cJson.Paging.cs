#nullable disable

using System;
using System.Collections.Generic;
using System.Data.Common;
using System.Globalization;
using System.Text.Json.Nodes;

namespace CrudLib
{
    /// <summary>
    /// cJson의 페이징 읽기 부분 (cJson.cs가 길어 파일만 나눈 것. 같은 클래스다).
    ///
    /// - LoadSqlTablePage : 테이블에서 한 페이지만 읽는다 (스키마 탑재).
    /// - LoadSqlQueryPage : 임의 SELECT 문을 (쿼리) AS sub로 감싸 한 페이지만 읽는다 (스키마 없음).
    /// - GetTableColumns / GetQueryColumns : SqlWhere·SqlOrderBy에 넘길 컬럼 목록.
    /// - GetPage / GetPageSize / GetTotalRows / GetTotalPages / IsPaged : 페이지 정보.
    ///
    /// 페이지 정보는 JSON의 "pager"에 담긴다 (데이터일 뿐이라 직렬화해도 안전하다).
    ///   "pager": { "page": 3, "pageSize": 20, "totalRows": 135, "totalPages": 7 }
    /// LoadSql / LoadSqlQuery / LoadSqlSchema는 새 doc을 만들므로 pager가 남지 않는다.
    ///
    /// 사용법·규칙은 cjson.md "페이징" 절.
    /// </summary>
    public partial class cJson
    {
        /// <summary>한 페이지 행 수의 상한. 이보다 크게 요청하면 이 값으로 줄인다.</summary>
        public const int MaxPageSize = 1000;

        // ==================================================================
        // 페이징 읽기
        // ==================================================================

        /// <summary>
        /// 테이블에서 한 페이지만 읽는다. 스키마를 탑재하고, 이번에 읽은 행 수를 돌려준다.
        ///
        /// pTableName, pWhere, pOrderBy, pParams, pWhitelist : LoadSql과 같다.
        ///   (pWhere의 값은 {0}, {1} 자리로 쓰고 pParams로 넘긴다. pOrderBy가 비면 PK 순서)
        /// pPage     : 읽을 페이지 (1부터). 1보다 작으면 1, 마지막 페이지보다 크면 마지막 페이지.
        /// pPageSize : 한 페이지 행 수. 1보다 작으면 1, MaxPageSize보다 크면 MaxPageSize.
        ///   (page·pageSize는 URL에서 오는 값이라 예외 대신 보정한다. 실제로 쓴 값은 pager에 남는다)
        ///
        /// 실행 순서: SELECT COUNT(*) → 페이지 보정 → SELECT ... ORDER BY ... + 방언의 LimitClause.
        /// GetLastSql()은 COUNT 문과 SELECT 문을 줄바꿈으로 이어서 돌려준다.
        /// 실패하면 예외이고 cJson은 원래 상태 그대로다.
        /// </summary>
        public int LoadSqlTablePage(string pTableName, string pWhere, string pOrderBy, object[] pParams,
                                    int pPage, int pPageSize, List<string> pWhitelist = null)
        {
            CrudSchema schema;
            ISqlDialect dialect;
            cJson temp;
            PageInfo info;
            DbCommand cmd;
            string where;
            string countSql;
            string selectSql;
            int totalRows;
            int count;

            AssertDbContext();

            schema = PrepareSchema(pTableName, pWhitelist);
            dialect = dbContext.Dialect();
            where = ReplacePlaceholders(dialect, pWhere, pParams);

            // 1. 전체 건수
            countSql = "SELECT COUNT(*) FROM " + dialect.QuoteIdentifier(schema.TableName);
            if (where.Trim() != "")
            {
                countSql = countSql + " WHERE " + where;
            }
            totalRows = ExecuteCount(dialect, countSql, pParams);

            // 2. 페이지 보정
            info = CalcPage(pPage, pPageSize, totalRows);

            // 3. 페이지 읽기 (ORDER BY는 BuildSelectSql이 pOrderBy 또는 PK로 반드시 붙인다 → MSSQL OFFSET 조건 충족)
            selectSql = BuildSelectSql(dialect, schema, where, pOrderBy)
                      + " " + dialect.LimitClause(info.Offset, info.PageSize);

            temp = new cJson(pSchema: schema);
            cmd = dbContext.CreateCommand(selectSql);
            using (cmd)
            {
                BindParams(dialect, cmd, pParams);
                ReadRows(cmd, schema, temp.Data());
            }

            temp.doc["pager"] = MakePagerNode(info);

            count = CommitLoaded(temp);
            lastSql = countSql + "\n" + selectSql;
            return count;
        }

        /// <summary>
        /// 임의 SELECT 문에서 한 페이지만 읽는다. 스키마는 없다 (isLoadedSchema = false). 이번에 읽은 행 수를 돌려준다.
        ///
        /// pSql      : SELECT 문 전체. 서버 코드에 고정한 문장만 넣는다 (사용자 입력 금지).
        ///             값은 {0}, {1} 자리로 쓴다. 안에 ORDER BY를 쓰지 않는다 (MSSQL은 서브쿼리 안 ORDER BY 오류).
        ///             계산 컬럼에는 반드시 별칭(AS)을 붙인다.
        /// pKeyName  : 각 행을 유일하게 구별하는 컬럼. 비우면 현재 KeyName. 없으면 ROW_NUMBER() AS rn 등을 넣어 쓴다.
        /// pWhere    : 바깥에서 거는 조건 (SqlWhere.GetWhere()). 쿼리의 결과 컬럼 이름으로 쓴다.
        /// pOrderBy  : 바깥에서 거는 정렬 (SqlOrderBy.GetOrderBy()). 비우면 키 컬럼 오름차순.
        /// pParams   : 쿼리의 자리표시 값 + pWhere의 자리표시 값을 번호 순서대로 하나의 배열에 담는다.
        ///             (쿼리가 {0}, {1}을 쓰면 SqlWhere는 pParamStart = 2로 만들고 GetParams(앞 값)로 합친다)
        /// pPage, pPageSize : LoadSqlTablePage와 같은 규칙.
        ///
        /// 실행 SQL:
        ///   SELECT COUNT(*) FROM (pSql) AS sub WHERE pWhere
        ///   SELECT * FROM (pSql) AS sub WHERE pWhere ORDER BY pOrderBy + LimitClause
        /// JSON에 "columns"(결과 컬럼 이름 배열)를 함께 담는다. 0건이어도 화면이 헤더를 그릴 수 있게.
        /// 실패하면 예외이고 cJson은 원래 상태 그대로다.
        /// </summary>
        public int LoadSqlQueryPage(string pSql, string pKeyName, string pWhere, string pOrderBy, object[] pParams,
                                    int pPage, int pPageSize)
        {
            ISqlDialect dialect;
            cJson temp;
            PageInfo info;
            DbCommand cmd;
            List<CrudField> resultColumns;
            JsonArray columnNames;
            string from;
            string where;
            string orderBy;
            string keyName;
            string countSql;
            string selectSql;
            int totalRows;
            int count;
            int i;

            AssertDbContext();

            dialect = dbContext.Dialect();
            from = WrapSubQuery(pSql);

            keyName = pKeyName;
            if (keyName == null || keyName.Trim() == "")
            {
                keyName = GetKeyName();
            }

            where = "";
            if (pWhere != null && pWhere.Trim() != "")
            {
                where = " WHERE " + pWhere;
            }

            orderBy = pOrderBy;
            if (orderBy == null || orderBy.Trim() == "")
            {
                orderBy = dialect.QuoteIdentifier(keyName) + " ASC";
            }

            // 쿼리와 pWhere가 같은 번호 체계를 쓰므로 문장 전체를 한 번에 바꾼다.
            countSql = ReplacePlaceholders(dialect, "SELECT COUNT(*) FROM " + from + where, pParams);
            totalRows = ExecuteCount(dialect, countSql, pParams);

            info = CalcPage(pPage, pPageSize, totalRows);

            selectSql = ReplacePlaceholders(dialect, "SELECT * FROM " + from + where + " ORDER BY " + orderBy, pParams)
                      + " " + dialect.LimitClause(info.Offset, info.PageSize);

            temp = new cJson();
            temp.KeyName = keyName;
            resultColumns = new List<CrudField>();

            cmd = dbContext.CreateCommand(selectSql);
            using (cmd)
            {
                BindParams(dialect, cmd, pParams);
                ReadRows(cmd, null, temp.Data(), resultColumns);
            }

            columnNames = new JsonArray();
            for (i = 0; i < resultColumns.Count; i++)
            {
                columnNames.Add(JsonValue.Create(resultColumns[i].Name));
            }
            temp.doc["columns"] = columnNames;
            temp.doc["pager"] = MakePagerNode(info);

            count = CommitLoaded(temp);
            lastSql = countSql + "\n" + selectSql;
            return count;
        }

        // ==================================================================
        // 컬럼 목록 (SqlWhere, SqlOrderBy에 넘긴다)
        // ==================================================================

        /// <summary>
        /// 테이블의 컬럼 목록 (DbContext 스키마 캐시). ts, Binary 필드는 빠져 있다.
        /// 돌려준 목록을 고쳐도 캐시에는 영향이 없다. cJson의 상태는 바뀌지 않는다.
        /// </summary>
        public List<CrudField> GetTableColumns(string pTableName)
        {
            CrudSchema schema;
            List<CrudField> result;
            int i;

            AssertDbContext();

            schema = PrepareSchema(pTableName, null);
            result = new List<CrudField>();
            for (i = 0; i < schema.Fields.Count; i++)
            {
                result.Add(schema.Fields[i].Clone());
            }
            return result;
        }

        /// <summary>
        /// 임의 SELECT 문의 결과 컬럼 목록을 0건 탐색으로 알아낸다 (데이터는 읽지 않는다).
        ///   SELECT * FROM (pSql) AS sub WHERE 1 = 0
        /// 알 수 있는 것: 이름(Name), DB 타입 이름(Type), 종류(Kind: .NET 타입으로 판단).
        /// 알 수 없는 것: PK, NULL 허용, 길이, 기본값 (모두 기본값으로 둔다).
        /// pSql의 규칙은 LoadSqlQueryPage와 같다. cJson의 상태(data, lastSql)는 바뀌지 않는다.
        /// </summary>
        public List<CrudField> GetQueryColumns(string pSql, object[] pParams = null)
        {
            ISqlDialect dialect;
            DbCommand cmd;
            DbDataReader reader;
            List<CrudField> result;
            string sql;

            AssertDbContext();

            dialect = dbContext.Dialect();
            sql = ReplacePlaceholders(dialect, "SELECT * FROM " + WrapSubQuery(pSql) + " WHERE 1 = 0", pParams);
            result = new List<CrudField>();

            cmd = dbContext.CreateCommand(sql);
            using (cmd)
            {
                BindParams(dialect, cmd, pParams);
                reader = cmd.ExecuteReader();
                using (reader)
                {
                    ReadColumns(reader, result);
                }
            }
            return result;
        }

        // ==================================================================
        // 페이지 정보
        // ==================================================================

        /// <summary>LoadSqlTablePage / LoadSqlQueryPage로 읽은 상태인지 (JSON에 pager가 있는지)</summary>
        public bool IsPaged()
        {
            return Pager() != null;
        }

        /// <summary>현재 페이지 (1부터). 페이지로 읽지 않았으면 1.</summary>
        public int GetPage()
        {
            return PagerInt("page", 1);
        }

        /// <summary>한 페이지 행 수. 페이지로 읽지 않았으면 Count().</summary>
        public int GetPageSize()
        {
            return PagerInt("pageSize", Count());
        }

        /// <summary>
        /// DB 전체 건수 (조건에 맞는 것). 페이지로 읽지 않았으면 Count().
        /// Count()는 메모리에 있는 행 수(이 페이지)이므로 다르다.
        /// </summary>
        public int GetTotalRows()
        {
            return PagerInt("totalRows", Count());
        }

        /// <summary>전체 페이지 수. 페이지로 읽지 않았으면 레코드가 있을 때 1, 없으면 0.</summary>
        public int GetTotalPages()
        {
            int fallback;

            fallback = 0;
            if (Count() > 0)
            {
                fallback = 1;
            }
            return PagerInt("totalPages", fallback);
        }

        // ==================================================================
        // 내부
        // ==================================================================

        /// <summary>CalcPage의 결과 (보정된 페이지 값들)</summary>
        private class PageInfo
        {
            public int Page { get; set; }
            public int PageSize { get; set; }
            public int TotalRows { get; set; }
            public int TotalPages { get; set; }
            public int Offset { get; set; }
        }

        /// <summary>
        /// 페이지 값을 보정하고 offset을 계산한다.
        /// pageSize: 1 ~ MaxPageSize, page: 1 ~ totalPages (0건이면 1).
        /// </summary>
        private static PageInfo CalcPage(int pPage, int pPageSize, int pTotalRows)
        {
            PageInfo info;
            int page;
            int pageSize;
            int totalPages;

            pageSize = pPageSize;
            if (pageSize < 1)
            {
                pageSize = 1;
            }
            if (pageSize > MaxPageSize)
            {
                pageSize = MaxPageSize;
            }

            totalPages = (pTotalRows + pageSize - 1) / pageSize;

            page = pPage;
            if (page < 1)
            {
                page = 1;
            }
            if (totalPages == 0)
            {
                // 0건이면 페이지는 1뿐이다.
                page = 1;
            }
            else if (page > totalPages)
            {
                // 마지막 페이지의 글을 지운 직후 등에 빈 화면이 나오지 않도록 마지막 페이지로.
                page = totalPages;
            }

            info = new PageInfo();
            info.Page = page;
            info.PageSize = pageSize;
            info.TotalRows = pTotalRows;
            info.TotalPages = totalPages;
            info.Offset = (page - 1) * pageSize;
            return info;
        }

        private static JsonObject MakePagerNode(PageInfo pInfo)
        {
            JsonObject pager;

            pager = new JsonObject();
            pager["page"] = JsonValue.Create(pInfo.Page);
            pager["pageSize"] = JsonValue.Create(pInfo.PageSize);
            pager["totalRows"] = JsonValue.Create(pInfo.TotalRows);
            pager["totalPages"] = JsonValue.Create(pInfo.TotalPages);
            return pager;
        }

        /// <summary>SELECT COUNT(*) 문을 실행해 건수를 돌려준다. pSql은 자리표시가 이미 바뀐 문장.</summary>
        private int ExecuteCount(ISqlDialect pDialect, string pSql, object[] pParams)
        {
            DbCommand cmd;
            object value;

            cmd = dbContext.CreateCommand(pSql);
            using (cmd)
            {
                BindParams(pDialect, cmd, pParams);
                value = cmd.ExecuteScalar();
            }

            if (value == null || value is DBNull)
            {
                return 0;
            }
            // MSSQL은 int, MySQL은 long으로 돌려준다.
            return Convert.ToInt32(value, CultureInfo.InvariantCulture);
        }

        /// <summary>
        /// "(pSql) AS sub"를 만든다. 비어 있으면 예외.
        /// 끝의 세미콜론은 서브쿼리에서 문법 오류가 나므로 떼어 낸다.
        /// </summary>
        private static string WrapSubQuery(string pSql)
        {
            string sql;

            if (pSql == null || pSql.Trim() == "")
            {
                throw new ArgumentException("SQL이 비어 있습니다.", "pSql");
            }

            sql = pSql.Trim();
            while (sql.EndsWith(";"))
            {
                sql = sql.Substring(0, sql.Length - 1).TrimEnd();
            }
            return "(" + sql + ") AS sub";
        }

        /// <summary>DataReader의 컬럼 정보(이름, DB 타입 이름, 종류)를 pTarget에 넣는다. 행은 읽지 않는다.</summary>
        private static void ReadColumns(DbDataReader pReader, List<CrudField> pTarget)
        {
            CrudField field;
            int i;

            for (i = 0; i < pReader.FieldCount; i++)
            {
                field = new CrudField();
                field.Name = pReader.GetName(i);
                field.Type = pReader.GetDataTypeName(i);
                field.Kind = KindOfType(pReader.GetFieldType(i));
                pTarget.Add(field);
            }
        }

        /// <summary>.NET 타입으로 종류를 정한다 (스키마가 없는 쿼리 결과용).</summary>
        private static CrudFieldKind KindOfType(Type pType)
        {
            if (pType == null)
            {
                return CrudFieldKind.Other;
            }
            if (pType == typeof(string) || pType == typeof(char) || pType == typeof(Guid))
            {
                return CrudFieldKind.Text;
            }
            if (pType == typeof(int) || pType == typeof(long) || pType == typeof(short) || pType == typeof(byte)
                || pType == typeof(sbyte) || pType == typeof(uint) || pType == typeof(ulong) || pType == typeof(ushort))
            {
                return CrudFieldKind.Integer;
            }
            if (pType == typeof(decimal) || pType == typeof(double) || pType == typeof(float))
            {
                return CrudFieldKind.Decimal;
            }
            if (pType == typeof(DateTime) || pType == typeof(DateTimeOffset))
            {
                return CrudFieldKind.DateTime;
            }
            if (pType == typeof(bool))
            {
                return CrudFieldKind.Bool;
            }
            if (pType == typeof(byte[]))
            {
                return CrudFieldKind.Binary;
            }
            return CrudFieldKind.Other;
        }

        /// <summary>JSON의 pager 객체. 없으면 null.</summary>
        private JsonObject Pager()
        {
            JsonNode node;

            if (!doc.TryGetPropertyValue("pager", out node))
            {
                return null;
            }
            return node as JsonObject;
        }

        /// <summary>pager의 정수 값. pager나 값이 없거나 숫자가 아니면 pDefault.</summary>
        private int PagerInt(string pName, int pDefault)
        {
            JsonObject pager;
            int value;

            pager = Pager();
            if (pager == null)
            {
                return pDefault;
            }
            if (!int.TryParse(ToText(pager[pName]), NumberStyles.Integer, CultureInfo.InvariantCulture, out value))
            {
                return pDefault;
            }
            return value;
        }
    }
}
