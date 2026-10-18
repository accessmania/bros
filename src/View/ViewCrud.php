<?php
declare(strict_types=1);

require_once __DIR__ . '/View.php';
require_once __DIR__ . '/../Model/Crud/Crud.php';
require_once __DIR__ . '/../Model/Board/BoardConfig.php';

/**
 * 모델이 Crud(단일 레코드)인 View. 예전 BoardControllerView와 책임이 동일하다 —
 * PRG 패턴의 flash(에러/입력값 복원) 처리 + code로 레코드 1건 조회.
 *
 * $crud는 이미 init()까지 끝난 상태로 전달받는다(초기화는 이 클래스의 책임이
 * 아니다). 생성자가 하는 일은 그 crud로 selectRecord()를 호출해 값을 읽어오는
 * 것뿐 — crud 자체는 프로퍼티로 남기지 않고 생성자 안에서만 쓰고 버린다.
 */
class ViewCrud extends View
{
    /** @var BoardConfig */
    private $config;

    /** @var string|null */
    private $flashError = null;

    /** @var array<string, mixed> */
    private $values;

    /** @var string */
    private $returnRaw;

    public function __construct(Crud $crud, BoardConfig $config, string $code, string $returnRaw, string $templateFile)
    {
        $this->config = $config;
        $this->returnRaw = $returnRaw;
        $this->templateFile = $templateFile;
        $this->values = array_fill_keys($config->fields, '');

        // 1) 플래시(직전 Action 실패)를 먼저 읽고 즉시 지운다(PRG 패턴).
        if (isset($_SESSION[$config->flashErrorKey])) {
            $this->flashError = $_SESSION[$config->flashErrorKey];
            unset($_SESSION[$config->flashErrorKey]);
        }
        $flashOld = null;
        if (isset($_SESSION[$config->flashOldKey])) {
            $flashOld = $_SESSION[$config->flashOldKey];
            unset($_SESSION[$config->flashOldKey]);
        }

        // 2) 플래시 에러가 없고 code가 있을 때만 DB에서 조회한다.
        if ($this->flashError === null && $code !== '') {
            if ($crud->selectRecord($code)) {
                foreach ($config->fields as $field) {
                    $this->values[$field] = $crud->getValue($field);
                }
            } else {
                $this->flashError = '레코드를 찾을 수 없습니다: ' . $code;
            }
        }

        // 3) 플래시 old값이 있으면(저장 실패로 되돌아온 경우) 입력값을 복원한다.
        if ($flashOld !== null) {
            foreach ($config->fields as $field) {
                if (isset($flashOld[$field])) {
                    $this->values[$field] = $flashOld[$field];
                }
            }
        }
    }

    public function getConfig(): BoardConfig
    {
        return $this->config;
    }

    /** @return array<string, mixed> */
    public function getValues(): array
    {
        return $this->values;
    }

    public function getFlashError(): ?string
    {
        return $this->flashError;
    }

    public function getReturnRaw(): string
    {
        return $this->returnRaw;
    }
}
