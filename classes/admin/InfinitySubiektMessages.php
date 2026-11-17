<?php
/**
 * Wspólne komunikaty wyników wywołań agenta w panelach BO.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektMessages
{
    const TRANSLATION_SOURCE = 'infinitysubiektmessages';

    /** @var Module */
    private $module;

    public function __construct(Module $module)
    {
        $this->module = $module;
    }

    /**
     * @param array $result wynik z kluczem `error`
     *
     * @return string
     */
    public function errorText(array $result)
    {
        $error = is_string($result['error']) ? $result['error'] : InfinitySubiektJson::encode($result['error']);

        switch ($error) {
            case InfinitySubiektClient::ERROR_TIMEOUT:
                return sprintf(
                    $this->l('agent nie odpowiedział w czasie. Zadanie #%d zostaje w kolejce, sprawdź jego wynik na liście zadań.'),
                    isset($result['task_id']) ? (int) $result['task_id'] : 0
                );
            case InfinitySubiektClient::ERROR_SKIPPED:
                return $this->l('pominięty, bo agent nie odpowiada.');
            default:
                return sprintf($this->l('błąd: %s'), $error);
        }
    }

    /**
     * Co sprawdzić, gdy ping z modułu nie doczekał się odpowiedzi. „agent.php ping” w konsoli
     * nie łączy się ze sklepem, więc jego wynik nie mówi nic o kolejce.
     *
     * @param InfinitySubiektAgentStatus $status
     *
     * @return string
     */
    public function pingTimeoutText(InfinitySubiektAgentStatus $status)
    {
        if ($status->isOnline()) {
            return $this->l('Agent pobiera zadania, ale nie odpowiedział w czasie. Może wykonywać inne zadanie: sprawdź listę zadań i log agenta.');
        }

        $lastSeen = $status->lastSeen();
        $state = $lastSeen === ''
            ? $this->l('Agent jeszcze ani razu nie pobrał zadań z tego sklepu.')
            : sprintf($this->l('Agent nie pobiera zadań, ostatni sygnał: %s.'), $lastSeen);

        return $state . ' ' . $this->l('Na serwerze Windows musi działać „agent.php run” (usługa). Sam „agent.php ping” sprawdza tylko Subiekta, bez sklepu. Jeśli agent działa, sprawdź w jego logu błędy pollu (endpoint, token, certyfikat).');
    }

    /**
     * @param array $results lista wyników
     *
     * @return bool
     */
    public function allOk(array $results)
    {
        if (!$results) {
            return false;
        }
        foreach ($results as $result) {
            if (isset($result['error'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $string
     *
     * @return string
     */
    private function l($string)
    {
        return $this->module->l($string, self::TRANSLATION_SOURCE);
    }
}
