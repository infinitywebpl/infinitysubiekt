<?php

declare(strict_types=1);

namespace InfinitySubiektAgent\Http;

if (PHP_SAPI !== 'cli') {
    exit;
}

/**
 * Zadanie z pollu: {"task": {"id": …, "method": "…", "params": {…}}}.
 */
final readonly class Task
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public int $id,
        public string $method,
        public array $params,
    ) {
    }

    /**
     * @param mixed $data zdekodowane body odpowiedzi 200
     */
    public static function fromResponse(mixed $data): ?self
    {
        $task = is_array($data) ? ($data['task'] ?? null) : null;
        if (!is_array($task) || !is_int($task['id'] ?? null) || !is_string($task['method'] ?? null)) {
            return null;
        }

        $params = $task['params'] ?? [];

        return new self($task['id'], $task['method'], is_array($params) ? $params : []);
    }
}
