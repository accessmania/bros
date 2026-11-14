<?php

declare(strict_types=1);

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/../db/DbContext.php';

/**
 * 필드감추기 — 로그인한 계정의 권한(tAuth.fieldvisiblecode)에 걸린
 * tfieldvisible 레코드를 읽어 화면에 넘긴다. Access의 init_fieldvisible()을
 * 옮긴 것. 사용법·원리는 FieldVisible.md.
 *
 * 레코드 구성: tfieldvisible(tablename, fieldname, nickname, visiblefalse, parentcode)
 *  - parentcode = tfieldvisible_parent.code = tAuth.fieldvisiblecode
 *  - nickname   = 화면 이름(예: '문의'). 화면은 자기 nickname 레코드만 본다.
 *  - visiblefalse = 참이면 그 필드를 감춘다.
 */
class FieldVisible
{
    /**
     * init — 지금 로그인한 계정의 tfieldvisible 레코드 전부를 배열로 돌려준다.
     * 화면 PHP에서 부르고, 결과를 json_encode로 JS에 넘긴다.
     *
     * 돌려주는 모양: ['success' => bool, 'error' => string, 'recs' => [...]]
     *  - recs 한 줄 = ['tablename', 'fieldname', 'nickname', 'visiblefalse'(bool)]
     *  - 계정에 fieldvisiblecode가 없으면 success=false, error에 이유, recs=[]
     *    (VBA의 "계정에 필드보기권한이 설정되지 않았습니다."와 같은 경우).
     *  - DB 오류도 success=false + error. 화면은 뜨되 감추기만 안 된다.
     *
     * @return array{success: bool, error: string, recs: array<int, array<string, mixed>>}
     */
    public static function init(DbContext $db): array
    {
        $result = ['success' => false, 'error' => '', 'recs' => []];

        $parentCode = Auth::getValue('fieldvisiblecode');
        if ($parentCode === null || $parentCode === '') {
            $result['error'] = '계정에 필드보기권한(tAuth.fieldvisiblecode)이 설정되지 않았습니다.';
            return $result;
        }

        try {
            $stmt = $db->pdo()->prepare(
                'SELECT tablename, fieldname, nickname, visiblefalse FROM tfieldvisible WHERE parentcode = ?'
            );
            $stmt->execute([$parentCode]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result['recs'][] = [
                    'tablename'    => (string) $row['tablename'],
                    'fieldname'    => (string) $row['fieldname'],
                    'nickname'     => (string) $row['nickname'],
                    // bit 컬럼은 드라이버에 따라 '1'/1/true로 온다 — bool로 맞춘다.
                    'visiblefalse' => (int) $row['visiblefalse'] === 1,
                ];
            }
            $result['success'] = true;
        } catch (Throwable $e) {
            $result['error'] = 'tfieldvisible 읽기 실패 — ' . $e->getMessage();
        }

        return $result;
    }
}
