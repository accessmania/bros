<?php
declare(strict_types=1);

require_once __DIR__ . '/Action.php';
require_once __DIR__ . '/../Model/Crud/Crud.php';

/**
 * ["진짜 인라인 편집 + 일괄저장" SG용 — 2026-08-15 최초 구현, 이전엔 뼈대만
 * 있었음] 그리드(ScrollGrid, src/js/ScrollGrid.js)에서 여러 레코드를 한 번에
 * 고친 뒤 "저장" 한 번으로 일괄 반영하는 Action. ActionCrud와 달리 요청
 * 본문이 $_POST(폼 필드 하나짜리)가 아니라 **JSON body**(바뀐 행 배열 +
 * 삭제할 PK 배열)라서 입력 파싱부터 다르다.
 *
 * Action::handle()의 "처리하고 리다이렉트로 알린다"는 기본 계약과 달리,
 * 화면 이동 없이 그 자리에서 JSON으로 행별 결과를 응답하고 끝난다
 * (JsonActionResponder와 성격은 비슷하지만, "행 하나=응답 하나"를 전제하는
 * ActionResponder 인터페이스에는 이 다건 결과가 안 맞아 그 추상화는 쓰지
 * 않는다 — 설계 근거는 md/Board_ai.md "진짜 인라인 편집 + 일괄저장 SG" 참고).
 */
class ActionBulk extends Action
{
    /** @var Crud */
    private $crud;

    public function __construct(Crud $crud, BoardConfig $config)
    {
        parent::__construct($config);
        $this->crud = $crud;
    }

    public function handle(): void
    {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        $rows = isset($payload['rows']) && is_array($payload['rows']) ? $payload['rows'] : [];
        $deletedCodes = isset($payload['deletedCodes']) && is_array($payload['deletedCodes']) ? $payload['deletedCodes'] : [];

        $results = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $originalCode = isset($row[$this->config->primaryKey]) ? (string) $row[$this->config->primaryKey] : '';
            try {
                // 행마다 스키마/값 상태를 깨끗이 되돌린다(직전 행의 값이 이번
                // 행으로 새지 않도록) — Crud::init()이 내부적으로 값을 리셋한다.
                $this->crud->init($this->config->tableName);
                $this->crud->loadArray($row);
                $newCode = $this->crud->save();
                $results[] = [
                    'code' => $originalCode,
                    'success' => true,
                    'newCode' => (string) $newCode,
                ];
            } catch (Throwable $e) {
                $results[] = [
                    'code' => $originalCode,
                    'success' => false,
                    'message' => $e->getMessage(),
                ];
            }
        }

        $deleteResults = [];
        foreach ($deletedCodes as $code) {
            $code = (string) $code;
            try {
                $this->crud->init($this->config->tableName);
                $this->crud->deleteRecord($code);
                $deleteResults[] = ['code' => $code, 'success' => true];
            } catch (Throwable $e) {
                $deleteResults[] = ['code' => $code, 'success' => false, 'message' => $e->getMessage()];
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'results' => $results,
            'deleteResults' => $deleteResults,
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        exit;
    }
}
