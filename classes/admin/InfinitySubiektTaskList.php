<?php
/**
 * Lista ostatnich zadań kolejki na stronie ustawień: panel z filtrem i tabela (także przez AJAX).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektTaskList
{
    const LIMIT = 50;
    const ERROR_LENGTH = 300;
    // params i result w popupie: dłuższy JSON (np. wynik product/list) jest przycinany, żeby nie zawiesić przeglądarki
    const DETAILS_JSON_LIMIT = 524288;

    /** @var InfinitySubiektTaskQueue */
    private $queue;

    /** @var InfinitySubiektView */
    private $view;

    /** @var Context */
    private $context;

    public function __construct(InfinitySubiektTaskQueue $queue, InfinitySubiektView $view, Context $context)
    {
        $this->queue = $queue;
        $this->view = $view;
        $this->context = $context;
    }

    /**
     * @return string
     */
    public function renderPanel()
    {
        return $this->view->render('admin/tasks_panel.tpl', [
            'statuses' => InfinitySubiektTaskQueue::statuses(),
            'limit' => self::LIMIT,
            'table_html' => $this->renderTable(null),
            'stats' => $this->durationStats(),
        ]);
    }

    /**
     * Szczegóły jednego zadania do popupu (akcja taskDetails): wszystkie pola, params i result jako sformatowany JSON.
     *
     * @param array $task wiersz z InfinitySubiektTaskQueue::find()
     *
     * @return string
     */
    public function renderDetails(array $task)
    {
        $finished = in_array($task['status'], InfinitySubiektTaskQueue::finalStatuses(), true);

        return $this->view->render('admin/task_details.tpl', [
            'task' => $task,
            'order_url' => $task['order_id']
                ? $this->context->link->getAdminLink('AdminOrders') . '&id_order=' . (int) $task['order_id'] . '&vieworder'
                : '',
            'wait' => self::formatDuration(self::secondsBetween($task['created_at'], $task['locked_at'])),
            'duration' => $finished && $task['status'] !== InfinitySubiektTaskQueue::STATUS_EXPIRED
                ? self::formatDuration(self::secondsBetween($task['locked_at'], $task['updated_at']))
                : '',
            'params' => $this->jsonBlock($task['params']),
            'result' => $this->jsonBlock($task['result']),
            'limit_kb' => (int) (self::DETAILS_JSON_LIMIT / 1024),
        ]);
    }

    /**
     * @param string|null $json
     *
     * @return array ['text', 'size_kb', 'truncated']
     */
    private function jsonBlock($json)
    {
        if ($json === null || $json === '') {
            return ['text' => '', 'size_kb' => 0, 'truncated' => false];
        }

        // obiekty jako stdClass, żeby pusty obiekt {} nie stał się listą []
        $data = json_decode($json);
        $text = json_last_error() === JSON_ERROR_NONE
            ? json_encode($data, InfinitySubiektJson::FLAGS | JSON_PRETTY_PRINT)
            : false;
        if ($text === false) {
            $text = $json;
        }

        $truncated = strlen($text) > self::DETAILS_JSON_LIMIT;
        if ($truncated) {
            // mb_strcut nie tnie znaku UTF-8 w połowie
            $text = function_exists('mb_strcut')
                ? mb_strcut($text, 0, self::DETAILS_JSON_LIMIT, 'UTF-8')
                : substr($text, 0, self::DETAILS_JSON_LIMIT);
        }

        return [
            'text' => $text,
            'size_kb' => (int) ceil(strlen($json) / 1024),
            'truncated' => $truncated,
        ];
    }

    /**
     * @param string|null $from
     * @param string|null $to
     *
     * @return int|null
     */
    private static function secondsBetween($from, $to)
    {
        if (!$from || !$to) {
            return null;
        }
        $seconds = strtotime($to) - strtotime($from);

        return $seconds >= 0 ? $seconds : null;
    }

    /**
     * @return array
     */
    private function durationStats()
    {
        $stats = [];
        foreach ($this->queue->getDurationStats() as $row) {
            $stats[] = [
                'method' => $row['method'],
                'count' => (int) $row['count'],
                'min' => self::formatDuration($row['min']),
                'max' => self::formatDuration($row['max']),
                'avg' => self::formatDuration($row['avg']),
            ];
        }

        return $stats;
    }

    /**
     * @param mixed $seconds null = brak pomiaru
     *
     * @return string np. "4 s", "2,5 s", "2 min 05 s", "1 h 03 min"
     */
    private static function formatDuration($seconds)
    {
        if ($seconds === null || $seconds === '') {
            return '';
        }

        $seconds = (float) $seconds;
        if ($seconds < 60) {
            $rounded = round($seconds, 1);
            $text = $rounded == floor($rounded) ? (string) (int) $rounded : str_replace('.', ',', (string) $rounded);

            return $text . ' s';
        }

        $total = (int) round($seconds);
        if ($total < 3600) {
            return sprintf('%d min %02d s', (int) floor($total / 60), $total % 60);
        }

        return sprintf('%d h %02d min', (int) floor($total / 3600), (int) floor(($total % 3600) / 60));
    }

    /**
     * @param string|null $status null = wszystkie
     * @param int|null $orderId tylko zadania zamówienia (karta zamówienia)
     *
     * @return string
     */
    public function renderTable($status, $orderId = null)
    {
        if ($status !== null && !in_array($status, InfinitySubiektTaskQueue::statuses(), true)) {
            $status = null;
        }

        $orderLink = $this->context->link->getAdminLink('AdminOrders');
        $tasks = [];
        foreach ($this->queue->getList(self::LIMIT, $status, $orderId) as $row) {
            $row['order_url'] = $row['order_id'] ? $orderLink . '&id_order=' . (int) $row['order_id'] . '&vieworder' : '';
            $row['error'] = $row['error'] !== null ? Tools::substr($row['error'], 0, self::ERROR_LENGTH) : '';
            $row['duration'] = self::formatDuration($row['duration']);
            $tasks[] = $row;
        }

        return $this->view->render('admin/tasks_table.tpl', ['tasks' => $tasks]);
    }
}
