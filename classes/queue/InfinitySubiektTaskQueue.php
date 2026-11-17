<?php
/**
 * Kolejka zadań dla agenta.
 *
 * Odczyty statusu zawsze bez cache Db i poza transakcją; jedyna transakcja to claimNext().
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektTaskQueue
{
    const TABLE = 'subiekt_tasks';

    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_DONE = 'done';
    const STATUS_FAILED = 'failed';
    const STATUS_EXPIRED = 'expired';

    /** @var Db */
    private $db;

    /** @var InfinitySubiektClock */
    private $clock;

    /** @var string */
    private $table;

    /**
     * @param Db $db
     * @param InfinitySubiektClock $clock
     * @param string $prefix prefiks tabel PrestaShop
     */
    public function __construct(Db $db, InfinitySubiektClock $clock, $prefix)
    {
        $this->db = $db;
        $this->clock = $clock;
        $this->table = '`' . $prefix . self::TABLE . '`';
    }

    /**
     * @return string[]
     */
    public static function statuses()
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_DONE,
            self::STATUS_FAILED,
            self::STATUS_EXPIRED,
        ];
    }

    /**
     * @return string[]
     */
    public static function finalStatuses()
    {
        return [self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_EXPIRED];
    }

    /**
     * @param string $method
     * @param array $params
     * @param int|null $orderId
     * @param int $priority
     *
     * @return int id zadania (0 przy błędzie zapisu)
     */
    public function create($method, array $params, $orderId = null, $priority = InfinitySubiektConfig::PRIORITY_BACKGROUND)
    {
        $now = $this->clock->now();

        $ok = $this->db->execute(
            'INSERT INTO ' . $this->table
            . ' (`method`, `params`, `status`, `order_id`, `priority`, `attempts`, `expires_at`, `created_at`, `updated_at`)'
            . ' VALUES ('
            . $this->quote($method) . ', '
            . $this->quote(InfinitySubiektJson::encode($params)) . ', '
            . $this->quote(self::STATUS_PENDING) . ', '
            . ($orderId ? (int) $orderId : 'NULL') . ', '
            . (int) $priority . ', 0, '
            . $this->quote($this->clock->at(InfinitySubiektConfig::TASK_TTL * 60)) . ', '
            . $this->quote($now) . ', '
            . $this->quote($now) . ')',
            false
        );

        return $ok ? (int) $this->db->Insert_ID() : 0;
    }

    /**
     * Świeży odczyt zadania (bez cache).
     *
     * @param int $id
     *
     * @return array|null
     */
    public function find($id)
    {
        $row = $this->db->getRow('SELECT * FROM ' . $this->table . ' WHERE `id` = ' . (int) $id, false);

        return $row ?: null;
    }

    /**
     * Czeka na stan końcowy zadania. Zawsze zwraca tablicę; błąd = klucz `error`.
     *
     * @param int $id
     * @param int $timeout sekundy
     *
     * @return array
     */
    public function waitForResult($id, $timeout)
    {
        @set_time_limit((int) $timeout + 30);
        $deadline = microtime(true) + (int) $timeout;

        do {
            $task = $this->find($id);
            if ($task === null) {
                return ['error' => 'task not found', 'task_id' => (int) $id];
            }
            if (in_array($task['status'], self::finalStatuses(), true)) {
                return $this->finalResult($task);
            }
            usleep(500000);
        } while (microtime(true) < $deadline);

        return ['error' => 'timeout', 'task_id' => (int) $id];
    }

    /**
     * Atomowe wydanie najstarszego zadania o najwyższym priorytecie.
     * Db::getRow() dokleja " LIMIT 1", więc SELECT … FOR UPDATE idzie przez executeS().
     *
     * @return array|null ['id', 'method', 'params']
     */
    public function claimNext()
    {
        $now = $this->clock->now();

        $this->db->execute('START TRANSACTION', false);
        try {
            $rows = $this->db->executeS(
                'SELECT `id`, `method`, `params` FROM ' . $this->table
                . ' WHERE `status` = ' . $this->quote(self::STATUS_PENDING)
                . ' AND (`expires_at` IS NULL OR `expires_at` > ' . $this->quote($now) . ')'
                . ' ORDER BY `priority` DESC, `id` ASC'
                . ' LIMIT 1 FOR UPDATE',
                true,
                false
            );

            if (!$rows) {
                $this->db->execute('COMMIT', false);

                return null;
            }

            $row = $rows[0];
            $this->db->execute(
                'UPDATE ' . $this->table . ' SET'
                . ' `status` = ' . $this->quote(self::STATUS_PROCESSING) . ','
                . ' `locked_at` = ' . $this->quote($now) . ','
                . ' `attempts` = `attempts` + 1,'
                . ' `updated_at` = ' . $this->quote($now)
                . ' WHERE `id` = ' . (int) $row['id'],
                false
            );
            $this->db->execute('COMMIT', false);
        } catch (Throwable $e) {
            $this->db->execute('ROLLBACK', false);
            throw $e;
        }

        $params = InfinitySubiektJson::decode($row['params']);

        return [
            'id' => (int) $row['id'],
            'method' => $row['method'],
            'params' => $params === null ? [] : $params,
        ];
    }

    /**
     * Porządki przy każdym pollu i w cronie.
     *
     * @return array ['requeued' => int, 'failed' => int, 'expired' => int]
     */
    public function maintenance()
    {
        $stats = ['requeued' => 0, 'failed' => 0, 'expired' => 0];
        $now = $this->clock->now();

        foreach ($this->stuckTasks() as $task) {
            $error = $this->stuckError($task);
            if ($error !== null) {
                if ($this->markFailed((int) $task['id'], $error)) {
                    ++$stats['failed'];
                }
                continue;
            }

            $this->db->execute(
                'UPDATE ' . $this->table . ' SET'
                . ' `status` = ' . $this->quote(self::STATUS_PENDING) . ','
                . ' `locked_at` = NULL,'
                . ' `updated_at` = ' . $this->quote($now)
                . ' WHERE `id` = ' . (int) $task['id']
                . ' AND `status` = ' . $this->quote(self::STATUS_PROCESSING),
                false
            );
            $stats['requeued'] += (int) $this->db->Affected_Rows();
        }

        $this->db->execute(
            'UPDATE ' . $this->table . ' SET'
            . ' `status` = ' . $this->quote(self::STATUS_EXPIRED) . ','
            . ' `updated_at` = ' . $this->quote($now)
            . ' WHERE `status` = ' . $this->quote(self::STATUS_PENDING)
            . ' AND `expires_at` IS NOT NULL'
            . ' AND `expires_at` < ' . $this->quote($now),
            false
        );
        $stats['expired'] = (int) $this->db->Affected_Rows();

        return $stats;
    }

    /**
     * To samo co maintenance(), ale tylko liczy (dry-run crona).
     *
     * @return array ['requeued' => int, 'failed' => int, 'expired' => int]
     */
    public function previewMaintenance()
    {
        $stuck = ['requeued' => 0, 'failed' => 0];
        foreach ($this->stuckTasks() as $task) {
            ++$stuck[$this->stuckError($task) === null ? 'requeued' : 'failed'];
        }

        $expired = $this->db->getValue(
            'SELECT COUNT(*) FROM ' . $this->table
            . ' WHERE `status` = ' . $this->quote(self::STATUS_PENDING)
            . ' AND `expires_at` IS NOT NULL'
            . ' AND `expires_at` < ' . $this->quote($this->clock->now()),
            false
        );

        return [
            'requeued' => $stuck['requeued'],
            'failed' => $stuck['failed'],
            'expired' => (int) $expired,
        ];
    }

    /**
     * Zadania processing bez wyniku dłużej niż STUCK_AFTER.
     *
     * @return array wiersze id, method, attempts, params
     */
    private function stuckTasks()
    {
        return (array) $this->db->executeS(
            'SELECT `id`, `method`, `attempts`, `params` FROM ' . $this->table
            . ' WHERE `status` = ' . $this->quote(self::STATUS_PROCESSING)
            . ' AND `locked_at` < ' . $this->quote($this->clock->at(-InfinitySubiektConfig::STUCK_AFTER * 60)),
            true,
            false
        );
    }

    /**
     * Czy zawieszone zadanie kończy się błędem zamiast wrócić do kolejki.
     * order/add z duplicate_check = false nie wraca: agent mógł zapisać dokument przed awarią,
     * a bez podtytułu z numerem zamówienia nie rozpozna go i wystawiłby drugi.
     *
     * @param array $task wiersz z stuckTasks()
     *
     * @return string|null treść błędu; null = ponowna próba
     */
    private function stuckError(array $task)
    {
        if ((int) $task['attempts'] >= InfinitySubiektConfig::MAX_ATTEMPTS) {
            return 'max attempts (' . InfinitySubiektConfig::MAX_ATTEMPTS . ') - agent nie odesłał wyniku';
        }

        if ($task['method'] === InfinitySubiektClient::METHOD_ORDER_ADD) {
            $params = InfinitySubiektJson::decode($task['params']);
            if (is_array($params) && isset($params['duplicate_check']) && $params['duplicate_check'] === false) {
                return 'agent nie odesłał wyniku; dokument mógł powstać w Subiekcie, sprawdź to przed ponownym wystawieniem';
            }
        }

        return null;
    }

    /**
     * Przyjęcie wyniku od agenta, idempotentnie.
     *
     * @param int $id
     * @param array $body {"result": {...}} albo {"error": "..."}
     *
     * @return array [kod HTTP, dane odpowiedzi]
     */
    public function saveResult($id, array $body)
    {
        $task = $this->find($id);
        if ($task === null) {
            return [404, ['error' => 'not found']];
        }
        if (in_array($task['status'], self::finalStatuses(), true)) {
            return [200, ['status' => $task['status']]];
        }
        if ($task['status'] !== self::STATUS_PROCESSING) {
            return [409, ['status' => $task['status']]];
        }

        $error = $this->errorText(isset($body['error']) ? $body['error'] : null);
        if ($error !== '') {
            $saved = $this->markFailed((int) $id, $error);
            $status = self::STATUS_FAILED;
        } else {
            $result = isset($body['result']) && is_array($body['result']) ? $body['result'] : [];
            $this->db->execute(
                'UPDATE ' . $this->table . ' SET'
                . ' `status` = ' . $this->quote(self::STATUS_DONE) . ','
                . ' `result` = ' . $this->quote(InfinitySubiektJson::encodeObject($result)) . ','
                . ' `updated_at` = ' . $this->quote($this->clock->now())
                . ' WHERE `id` = ' . (int) $id
                . ' AND `status` = ' . $this->quote(self::STATUS_PROCESSING),
                false
            );
            $saved = (int) $this->db->Affected_Rows() > 0;
            $status = self::STATUS_DONE;
        }

        if ($saved) {
            return [200, ['status' => $status]];
        }

        // w międzyczasie maintenance zmienił stan zadania
        $task = $this->find($id);
        if ($task !== null && in_array($task['status'], self::finalStatuses(), true)) {
            return [200, ['status' => $task['status']]];
        }

        return [409, ['status' => $task === null ? 'not found' : $task['status']]];
    }

    /**
     * Ręczne zakończenie zadania, które czeka albo wisi: stan końcowy failed, więc nie wróci do kolejki.
     * Późny wynik agenta saveResult() przyjmie z 200 i pominie. Działającego agenta to nie zatrzymuje.
     *
     * @param int $id
     * @param string $reason
     *
     * @return bool false, gdy zadanie jest już zakończone
     */
    public function cancel($id, $reason)
    {
        return $this->markFailed($id, $reason, [self::STATUS_PENDING, self::STATUS_PROCESSING]);
    }

    /**
     * Zadanie w toku dla zamówienia.
     *
     * @param int $orderId
     * @param string $method
     *
     * @return array|null
     */
    public function findActive($orderId, $method)
    {
        $row = $this->db->getRow(
            'SELECT * FROM ' . $this->table
            . ' WHERE `order_id` = ' . (int) $orderId
            . ' AND `method` = ' . $this->quote($method)
            . ' AND `status` IN (' . $this->quote(self::STATUS_PENDING) . ', ' . $this->quote(self::STATUS_PROCESSING) . ')'
            . ' ORDER BY `id` DESC',
            false
        );

        return $row ?: null;
    }

    /**
     * Ostatnie udane zadanie dla zamówienia z ostatnich $days dni.
     *
     * @param int $orderId
     * @param string $method
     * @param int $days
     *
     * @return array|null wiersz z dodatkowym kluczem `result_data` (zdekodowany wynik)
     */
    public function findLastDone($orderId, $method, $days)
    {
        $row = $this->db->getRow(
            'SELECT * FROM ' . $this->table
            . ' WHERE `order_id` = ' . (int) $orderId
            . ' AND `method` = ' . $this->quote($method)
            . ' AND `created_at` >= ' . $this->quote($this->clock->at(-(int) $days * 86400))
            . ' ORDER BY `id` DESC',
            false
        );

        if (!$row || $row['status'] !== self::STATUS_DONE) {
            return null;
        }

        $row['result_data'] = InfinitySubiektJson::decode($row['result']);

        return $row;
    }

    /**
     * @param int $days
     *
     * @return int liczba usuniętych zadań
     */
    public function cleanup($days)
    {
        $this->db->execute(
            'DELETE FROM ' . $this->table
            . ' WHERE `created_at` < ' . $this->quote($this->clock->at(-(int) $days * 86400)),
            false
        );

        return (int) $this->db->Affected_Rows();
    }

    /**
     * Ile zadań usunąłby cleanup($days) (dry-run crona).
     *
     * @param int $days
     *
     * @return int
     */
    public function countOlderThan($days)
    {
        return (int) $this->db->getValue(
            'SELECT COUNT(*) FROM ' . $this->table
            . ' WHERE `created_at` < ' . $this->quote($this->clock->at(-(int) $days * 86400)),
            false
        );
    }

    /**
     * @param int $limit
     * @param string|null $status
     * @param int|null $orderId
     *
     * @return array
     */
    public function getList($limit, $status = null, $orderId = null)
    {
        $where = [];
        if ($status !== null && in_array($status, self::statuses(), true)) {
            $where[] = '`status` = ' . $this->quote($status);
        }
        if ($orderId) {
            $where[] = '`order_id` = ' . (int) $orderId;
        }

        $rows = $this->db->executeS(
            'SELECT `id`, `method`, `status`, `order_id`, `priority`, `attempts`, `error`, `created_at`, `updated_at`,'
            . ' ' . $this->durationSql() . ' AS `duration`'
            . ' FROM ' . $this->table
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY `id` DESC'
            . ' LIMIT ' . max(1, (int) $limit),
            true,
            false
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Czas wykonania per metoda: tylko zadania done (failed bywa anulowaniem albo zawieszeniem
     * uciętym przez maintenance, więc zafałszowałoby czasy). Z tabeli, czyli z okresu retencji cleanup().
     *
     * @return array wiersze ['method', 'count', 'min', 'max', 'avg'] w sekundach
     */
    public function getDurationStats()
    {
        $duration = $this->durationSql();
        $rows = $this->db->executeS(
            'SELECT `method`, COUNT(*) AS `count`, MIN(' . $duration . ') AS `min`,'
            . ' MAX(' . $duration . ') AS `max`, AVG(' . $duration . ') AS `avg`'
            . ' FROM ' . $this->table
            . ' WHERE `status` = ' . $this->quote(self::STATUS_DONE)
            . ' AND `locked_at` IS NOT NULL AND `updated_at` >= `locked_at`'
            . ' GROUP BY `method`'
            . ' ORDER BY `method` ASC',
            true,
            false
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Czas wykonania w sekundach: od pobrania przez agenta (`locked_at`, claimNext) do zapisu wyniku
     * (`updated_at`). NULL dla zadań niezakończonych i takich, których agent nie pobrał.
     *
     * @return string wyrażenie SQL
     */
    private function durationSql()
    {
        return 'CASE WHEN `status` IN (' . $this->quote(self::STATUS_DONE) . ', ' . $this->quote(self::STATUS_FAILED) . ')'
            . ' AND `locked_at` IS NOT NULL AND `updated_at` >= `locked_at`'
            . ' THEN TIMESTAMPDIFF(SECOND, `locked_at`, `updated_at`) END';
    }

    /**
     * failed: błąd także w `result` jako {"error": …}.
     *
     * @param int $id
     * @param string $error
     * @param string[] $fromStatuses
     *
     * @return bool
     */
    private function markFailed($id, $error, array $fromStatuses = [self::STATUS_PROCESSING])
    {
        $from = array_map([$this, 'quote'], $fromStatuses);

        $this->db->execute(
            'UPDATE ' . $this->table . ' SET'
            . ' `status` = ' . $this->quote(self::STATUS_FAILED) . ','
            . ' `error` = ' . $this->quote($error) . ','
            . ' `result` = ' . $this->quote(InfinitySubiektJson::encode(['error' => $error])) . ','
            . ' `updated_at` = ' . $this->quote($this->clock->now())
            . ' WHERE `id` = ' . (int) $id
            . ' AND `status` IN (' . implode(', ', $from) . ')',
            false
        );

        return (int) $this->db->Affected_Rows() > 0;
    }

    /**
     * done → wynik metody (także pusty); failed / expired → wynik z kluczem error.
     *
     * @param array $task
     *
     * @return array
     */
    private function finalResult(array $task)
    {
        $result = InfinitySubiektJson::decode($task['result']);

        if ($task['status'] === self::STATUS_DONE) {
            return $result === null ? [] : $result;
        }

        if ($result && isset($result['error'])) {
            return $result;
        }

        return ['error' => $task['error'] ? $task['error'] : $task['status'], 'task_id' => (int) $task['id']];
    }

    /**
     * @param mixed $error
     *
     * @return string
     */
    private function errorText($error)
    {
        if ($error === null || $error === false) {
            return '';
        }

        return trim(is_scalar($error) ? (string) $error : InfinitySubiektJson::encode($error));
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private function quote($value)
    {
        return '\'' . pSQL((string) $value, true) . '\'';
    }
}
