#nullable disable

using System;
using System.Collections.Generic;
using System.Globalization;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using System.Text.RegularExpressions;

namespace CrudLib
{
    /// <summary>
    /// cJson의 where 문자열 조건식을 해석해 레코드 검사 함수로 바꾼다.
    /// 문자열이라 JSON에 저장할 수 있고, Load할 때 다시 해석해서 필터를 되살린다.
    ///
    /// 문법 (키워드는 대소문자 무시):
    ///   식      : 조건 (and|or 조건)*      우선순위: not > and > or, 괄호 사용 가능
    ///   조건    : 값 비교연산자 값
    ///           | 필드 [not] like '패턴'      % = 여러 글자, _ = 한 글자
    ///           | 필드 [not] in (값, 값, ...)
    ///           | 필드 is [not] null           필드가 없거나 JSON null
    ///           | contains(필드, '글자') | startswith(필드, '글자') | endswith(필드, '글자')
    ///           | not 조건
    ///           | 필드                         값을 bool로 판단 (true, 0이 아닌 숫자, "y" 등)
    ///   비교연산자: =  <>  !=  >  <  >=  <=
    ///   값      : 필드 | '문자열' | "문자열" | 숫자 | true | false
    ///   필드    : 이름 (한글 가능), @이름 (cXml 호환), [공백 있는 이름]
    ///   문자열 안의 따옴표는 두 번 쓴다: '홍''길동'
    ///
    /// 비교 규칙:
    ///   - 한쪽이 숫자이고 다른 쪽도 숫자로 읽히면 숫자로 비교한다 (price > 1000, price = '1000').
    ///   - 한쪽이 숫자인데 다른 쪽이 숫자가 아니면: = 는 false, <> 는 true, 크기 비교는 false.
    ///   - 그 외는 문자열 비교 (대소문자 구분, cXml의 XPath와 같음).
    ///   - 없는 필드나 null은 문자열 비교에서 ""로 본다 (분류 = '' 은 없는 것도 포함).
    ///
    /// 예:
    ///   kind = '과일' and price >= 1000
    ///   (분류 = '안주' or 분류 = '주류') and not 품절
    ///   name like '%사과%'
    ///   code in ('A1', 'B2')
    ///   memo is null
    ///   contains(@분류, '안주')
    /// </summary>
    internal static class cJsonWhere
    {
        /// <summary>
        /// 조건식을 해석한다. 문법이 틀리면 ArgumentException.
        /// pFields에는 식에 나온 필드 이름이 담긴다 (스키마 검사용).
        /// </summary>
        public static Func<JsonObject, bool> Compile(string pWhere, out List<string> pFields)
        {
            Parser parser;
            Func<JsonObject, bool> result;

            if (pWhere == null || pWhere.Trim() == "")
            {
                throw new ArgumentException("where 조건식이 비어 있습니다.");
            }

            parser = new Parser(pWhere);
            result = parser.ParseAll();
            pFields = parser.Fields;
            return result;
        }

        // ==================================================================
        // 토큰
        // ==================================================================

        private enum TokenKind
        {
            Identifier,   // 필드 이름 또는 키워드
            Field,        // @이름, [이름] (키워드가 될 수 없는 필드)
            String,
            Number,
            Operator,     // = <> != > < >= <=
            LParen,
            RParen,
            Comma,
            End
        }

        private class Token
        {
            public TokenKind Kind;
            public string Text;
            public int Position;

            public Token(TokenKind pKind, string pText, int pPosition)
            {
                Kind = pKind;
                Text = pText;
                Position = pPosition;
            }

            /// <summary>키워드인지 (대소문자 무시)</summary>
            public bool IsWord(string pWord)
            {
                return Kind == TokenKind.Identifier && string.Equals(Text, pWord, StringComparison.OrdinalIgnoreCase);
            }
        }

        private static List<Token> Tokenize(string pText)
        {
            List<Token> tokens;
            StringBuilder sb;
            int i;
            int start;
            char c;
            char quote;

            tokens = new List<Token>();
            i = 0;

            while (i < pText.Length)
            {
                c = pText[i];

                if (char.IsWhiteSpace(c))
                {
                    i++;
                    continue;
                }

                start = i;

                if (c == '(')
                {
                    tokens.Add(new Token(TokenKind.LParen, "(", start));
                    i++;
                }
                else if (c == ')')
                {
                    tokens.Add(new Token(TokenKind.RParen, ")", start));
                    i++;
                }
                else if (c == ',')
                {
                    tokens.Add(new Token(TokenKind.Comma, ",", start));
                    i++;
                }
                else if (c == '=' || c == '<' || c == '>' || c == '!')
                {
                    if (i + 1 < pText.Length && (pText.Substring(i, 2) == "<>" || pText.Substring(i, 2) == "!=" || pText.Substring(i, 2) == ">=" || pText.Substring(i, 2) == "<="))
                    {
                        tokens.Add(new Token(TokenKind.Operator, pText.Substring(i, 2), start));
                        i += 2;
                    }
                    else if (c == '!')
                    {
                        throw Error(pText, start, "'!' 다음에는 '='가 와야 합니다 (!=).");
                    }
                    else
                    {
                        tokens.Add(new Token(TokenKind.Operator, c.ToString(), start));
                        i++;
                    }
                }
                else if (c == '\'' || c == '"')
                {
                    // 문자열. 같은 따옴표 두 개는 따옴표 하나.
                    quote = c;
                    sb = new StringBuilder();
                    i++;
                    while (true)
                    {
                        if (i >= pText.Length)
                        {
                            throw Error(pText, start, "문자열이 닫히지 않았습니다.");
                        }
                        if (pText[i] == quote)
                        {
                            if (i + 1 < pText.Length && pText[i + 1] == quote)
                            {
                                sb.Append(quote);
                                i += 2;
                                continue;
                            }
                            i++;
                            break;
                        }
                        sb.Append(pText[i]);
                        i++;
                    }
                    tokens.Add(new Token(TokenKind.String, sb.ToString(), start));
                }
                else if (char.IsDigit(c) || (c == '-' && i + 1 < pText.Length && char.IsDigit(pText[i + 1]) && IsValueExpected(tokens)))
                {
                    i++;
                    while (i < pText.Length && (char.IsDigit(pText[i]) || pText[i] == '.'))
                    {
                        i++;
                    }
                    tokens.Add(new Token(TokenKind.Number, pText.Substring(start, i - start), start));
                }
                else if (c == '[')
                {
                    i++;
                    while (i < pText.Length && pText[i] != ']')
                    {
                        i++;
                    }
                    if (i >= pText.Length)
                    {
                        throw Error(pText, start, "'['가 닫히지 않았습니다.");
                    }
                    tokens.Add(new Token(TokenKind.Field, pText.Substring(start + 1, i - start - 1), start));
                    i++;
                }
                else if (c == '@' || IsNameChar(c))
                {
                    if (c == '@')
                    {
                        i++;
                        if (i < pText.Length && pText[i] == '[')
                        {
                            // @[이름]
                            continue;
                        }
                    }
                    start = i;
                    while (i < pText.Length && IsNameChar(pText[i]))
                    {
                        i++;
                    }
                    if (i == start)
                    {
                        throw Error(pText, start, "'@' 다음에 필드 이름이 없습니다.");
                    }
                    if (c == '@')
                    {
                        tokens.Add(new Token(TokenKind.Field, pText.Substring(start, i - start), start - 1));
                    }
                    else
                    {
                        tokens.Add(new Token(TokenKind.Identifier, pText.Substring(start, i - start), start));
                    }
                }
                else
                {
                    throw Error(pText, start, "알 수 없는 글자 '" + c.ToString() + "'");
                }
            }

            tokens.Add(new Token(TokenKind.End, "", pText.Length));
            return tokens;
        }

        /// <summary>'-'를 음수 부호로 볼지 (앞이 비었거나 연산자, 괄호, 쉼표, 키워드면 값 자리)</summary>
        private static bool IsValueExpected(List<Token> pTokens)
        {
            Token last;

            if (pTokens.Count == 0)
            {
                return true;
            }
            last = pTokens[pTokens.Count - 1];
            return last.Kind == TokenKind.Operator || last.Kind == TokenKind.LParen || last.Kind == TokenKind.Comma
                || last.IsWord("in") || last.IsWord("and") || last.IsWord("or") || last.IsWord("not");
        }

        private static bool IsNameChar(char c)
        {
            return char.IsLetterOrDigit(c) || c == '_' || c == '.' || c == '$';
        }

        private static ArgumentException Error(string pText, int pPosition, string pMessage)
        {
            return new ArgumentException("where 조건식 오류 (" + (pPosition + 1).ToString() + "번째 글자): " + pMessage + "  식: " + pText);
        }

        // ==================================================================
        // 값
        // ==================================================================

        /// <summary>비교에 쓰는 값 하나</summary>
        private class Value
        {
            public bool IsNull;      // 없는 필드 또는 JSON null
            public bool IsNumber;    // JSON 숫자 또는 숫자 리터럴
            public bool NumOk;       // 숫자로 읽힘 (IsNumber 포함)
            public double Num;
            public string Text = "";
        }

        /// <summary>값 자리 하나 (필드 또는 리터럴)</summary>
        private class Operand
        {
            public string FieldName;   // 필드면 이름, 리터럴이면 null
            public Value Literal;

            public Value Get(JsonObject pRec)
            {
                JsonNode node;

                if (FieldName == null)
                {
                    return Literal;
                }
                pRec.TryGetPropertyValue(FieldName, out node);
                return FromNode(node);
            }
        }

        private static Value FromNode(JsonNode pNode)
        {
            Value v;
            JsonValueKind kind;

            v = new Value();
            if (pNode == null)
            {
                v.IsNull = true;
                return v;
            }

            kind = pNode.GetValueKind();
            v.Text = cJson.ToText(pNode);
            if (kind == JsonValueKind.Number)
            {
                v.IsNumber = true;
                v.NumOk = true;
                v.Num = cJson.ToDouble(pNode);
            }
            else if (kind == JsonValueKind.String)
            {
                v.NumOk = TryNumber(v.Text, out v.Num);
            }
            return v;
        }

        private static Value FromLiteral(string pText, bool pIsNumber)
        {
            Value v;

            v = new Value();
            v.Text = pText;
            v.IsNumber = pIsNumber;
            v.NumOk = TryNumber(pText, out v.Num);
            return v;
        }

        private static bool TryNumber(string pText, out double pNum)
        {
            return double.TryParse(pText.Trim(), NumberStyles.Float, CultureInfo.InvariantCulture, out pNum);
        }

        /// <summary>a 연산자 b. 숫자/문자열 규칙은 클래스 설명 참고.</summary>
        private static bool Compare(Value a, string pOp, Value b)
        {
            bool numeric;
            bool oneSideNumber;
            int cmp;

            oneSideNumber = a.IsNumber || b.IsNumber;
            numeric = oneSideNumber && a.NumOk && b.NumOk;

            if (oneSideNumber && !numeric)
            {
                // 숫자와 숫자가 아닌 것의 비교
                return pOp == "<>" || pOp == "!=";
            }

            if (numeric)
            {
                cmp = a.Num.CompareTo(b.Num);
            }
            else
            {
                cmp = string.CompareOrdinal(a.Text, b.Text);
            }

            if (pOp == "=") return cmp == 0;
            if (pOp == "<>" || pOp == "!=") return cmp != 0;
            if (pOp == ">") return cmp > 0;
            if (pOp == "<") return cmp < 0;
            if (pOp == ">=") return cmp >= 0;
            if (pOp == "<=") return cmp <= 0;
            return false;
        }

        /// <summary>SQL like 패턴(% _)을 정규식으로</summary>
        private static Regex LikeToRegex(string pPattern)
        {
            StringBuilder sb;
            int i;
            char c;

            sb = new StringBuilder("^");
            for (i = 0; i < pPattern.Length; i++)
            {
                c = pPattern[i];
                if (c == '%')
                {
                    sb.Append(".*");
                }
                else if (c == '_')
                {
                    sb.Append('.');
                }
                else
                {
                    sb.Append(Regex.Escape(c.ToString()));
                }
            }
            sb.Append('$');
            return new Regex(sb.ToString(), RegexOptions.Singleline);
        }

        // ==================================================================
        // 파서 (재귀 하강)
        // ==================================================================

        private class Parser
        {
            private string text;
            private List<Token> tokens;
            private int pos;

            public List<string> Fields = new List<string>();

            public Parser(string pText)
            {
                text = pText;
                tokens = Tokenize(pText);
                pos = 0;
            }

            public Func<JsonObject, bool> ParseAll()
            {
                Func<JsonObject, bool> result;

                result = ParseOr();
                if (Peek().Kind != TokenKind.End)
                {
                    throw Error(text, Peek().Position, "'" + Peek().Text + "' 앞에서 식이 끝나야 합니다. (and/or가 빠졌는지 확인)");
                }
                return result;
            }

            private Token Peek()
            {
                return tokens[pos];
            }

            private Token Next()
            {
                Token t;

                t = tokens[pos];
                if (t.Kind != TokenKind.End)
                {
                    pos++;
                }
                return t;
            }

            private void Expect(TokenKind pKind, string pWhat)
            {
                if (Peek().Kind != pKind)
                {
                    throw Error(text, Peek().Position, pWhat + "가 와야 합니다.");
                }
                Next();
            }

            private Func<JsonObject, bool> ParseOr()
            {
                Func<JsonObject, bool> left;
                Func<JsonObject, bool> right;
                Func<JsonObject, bool> l;

                left = ParseAnd();
                while (Peek().IsWord("or"))
                {
                    Next();
                    right = ParseAnd();
                    l = left;
                    left = r => l(r) || right(r);
                }
                return left;
            }

            private Func<JsonObject, bool> ParseAnd()
            {
                Func<JsonObject, bool> left;
                Func<JsonObject, bool> right;
                Func<JsonObject, bool> l;

                left = ParseNot();
                while (Peek().IsWord("and"))
                {
                    Next();
                    right = ParseNot();
                    l = left;
                    left = r => l(r) && right(r);
                }
                return left;
            }

            private Func<JsonObject, bool> ParseNot()
            {
                Func<JsonObject, bool> inner;

                if (Peek().IsWord("not"))
                {
                    Next();
                    inner = ParseNot();
                    return r => !inner(r);
                }
                return ParsePrimary();
            }

            private Func<JsonObject, bool> ParsePrimary()
            {
                Func<JsonObject, bool> inner;
                Token t;
                Operand left;

                t = Peek();

                // ( 식 )
                if (t.Kind == TokenKind.LParen)
                {
                    Next();
                    inner = ParseOr();
                    Expect(TokenKind.RParen, "')'");
                    return inner;
                }

                // 함수: contains / startswith / endswith
                if (t.Kind == TokenKind.Identifier && tokens[pos + 1].Kind == TokenKind.LParen
                    && (t.IsWord("contains") || t.IsWord("startswith") || t.IsWord("endswith")))
                {
                    return ParseFunction();
                }

                left = ParseOperand();
                return ParseCondition(left);
            }

            private Func<JsonObject, bool> ParseFunction()
            {
                Token name;
                Operand target;
                Operand arg;
                string fn;

                name = Next();
                fn = name.Text.ToLowerInvariant();
                Expect(TokenKind.LParen, "'('");
                target = ParseOperand();
                Expect(TokenKind.Comma, "','");
                arg = ParseOperand();
                Expect(TokenKind.RParen, "')'");

                if (fn == "contains")
                {
                    return r => target.Get(r).Text.IndexOf(arg.Get(r).Text, StringComparison.Ordinal) >= 0;
                }
                if (fn == "startswith")
                {
                    return r => target.Get(r).Text.StartsWith(arg.Get(r).Text, StringComparison.Ordinal);
                }
                return r => target.Get(r).Text.EndsWith(arg.Get(r).Text, StringComparison.Ordinal);
            }

            /// <summary>값 다음에 오는 것: 비교연산자, like, in, is null, 또는 없음(bool 필드)</summary>
            private Func<JsonObject, bool> ParseCondition(Operand pLeft)
            {
                Token t;
                Operand right;
                string op;
                bool negate;
                Regex re;
                List<Operand> list;
                Func<JsonObject, bool> f;

                t = Peek();

                // 비교연산자
                if (t.Kind == TokenKind.Operator)
                {
                    op = Next().Text;
                    right = ParseOperand();
                    return r => Compare(pLeft.Get(r), op, right.Get(r));
                }

                // is [not] null
                if (t.IsWord("is"))
                {
                    Next();
                    negate = false;
                    if (Peek().IsWord("not"))
                    {
                        Next();
                        negate = true;
                    }
                    if (!Peek().IsWord("null"))
                    {
                        throw Error(text, Peek().Position, "'is' 다음에는 null 또는 not null이 와야 합니다.");
                    }
                    Next();
                    if (negate)
                    {
                        return r => !pLeft.Get(r).IsNull;
                    }
                    return r => pLeft.Get(r).IsNull;
                }

                // [not] like / [not] in
                negate = false;
                if (t.IsWord("not") && (tokens[pos + 1].IsWord("like") || tokens[pos + 1].IsWord("in")))
                {
                    Next();
                    negate = true;
                    t = Peek();
                }

                if (t.IsWord("like"))
                {
                    Next();
                    if (Peek().Kind != TokenKind.String)
                    {
                        throw Error(text, Peek().Position, "like 다음에는 '패턴' 문자열이 와야 합니다.");
                    }
                    re = LikeToRegex(Next().Text);
                    f = r => re.IsMatch(pLeft.Get(r).Text);
                    if (negate)
                    {
                        return r => !f(r);
                    }
                    return f;
                }

                if (t.IsWord("in"))
                {
                    Next();
                    Expect(TokenKind.LParen, "in 다음에 '('");
                    list = new List<Operand>();
                    list.Add(ParseOperand());
                    while (Peek().Kind == TokenKind.Comma)
                    {
                        Next();
                        list.Add(ParseOperand());
                    }
                    Expect(TokenKind.RParen, "')'");
                    f = r => InList(pLeft.Get(r), list, r);
                    if (negate)
                    {
                        return r => !f(r);
                    }
                    return f;
                }

                // 연산자 없음: 필드 값을 bool로
                if (pLeft.FieldName == null)
                {
                    throw Error(text, t.Position, "비교연산자가 와야 합니다.");
                }
                return r => ValueToBool(pLeft.Get(r));
            }

            private Operand ParseOperand()
            {
                Token t;
                Operand o;

                t = Next();
                o = new Operand();

                if (t.Kind == TokenKind.String)
                {
                    o.Literal = FromLiteral(t.Text, false);
                }
                else if (t.Kind == TokenKind.Number)
                {
                    o.Literal = FromLiteral(t.Text, true);
                    if (!o.Literal.NumOk)
                    {
                        throw Error(text, t.Position, "숫자 형식이 올바르지 않습니다: " + t.Text);
                    }
                }
                else if (t.IsWord("true") || t.IsWord("false"))
                {
                    o.Literal = FromLiteral(t.Text.ToLowerInvariant(), false);
                }
                else if (t.IsWord("null"))
                {
                    throw Error(text, t.Position, "null은 'is null' / 'is not null'로 검사합니다.");
                }
                else if (t.Kind == TokenKind.Field || (t.Kind == TokenKind.Identifier && !IsReserved(t)))
                {
                    o.FieldName = t.Text;
                    if (!Fields.Contains(t.Text))
                    {
                        Fields.Add(t.Text);
                    }
                }
                else if (t.Kind == TokenKind.End)
                {
                    throw Error(text, t.Position, "식이 덜 끝났습니다.");
                }
                else
                {
                    throw Error(text, t.Position, "'" + t.Text + "' 자리에는 필드나 값이 와야 합니다. (키워드와 같은 이름의 필드는 [이름]으로)");
                }
                return o;
            }

            private static bool IsReserved(Token t)
            {
                return t.IsWord("and") || t.IsWord("or") || t.IsWord("not") || t.IsWord("like")
                    || t.IsWord("in") || t.IsWord("is") || t.IsWord("null");
            }

            private static bool InList(Value pValue, List<Operand> pList, JsonObject pRec)
            {
                int i;

                for (i = 0; i < pList.Count; i++)
                {
                    if (Compare(pValue, "=", pList[i].Get(pRec)))
                    {
                        return true;
                    }
                }
                return false;
            }

            private static bool ValueToBool(Value v)
            {
                string s;

                if (v.IsNull)
                {
                    return false;
                }
                if (v.IsNumber)
                {
                    return v.Num != 0;
                }
                s = v.Text.Trim().ToLowerInvariant();
                return s == "true" || s == "1" || s == "-1" || s == "y" || s == "yes";
            }
        }
    }
}
