<?php

declare(strict_types=1);

namespace InfinitySubiektAgent\Method;

if (PHP_SAPI !== 'cli') {
    exit;
}

use InfinitySubiektAgent\Log;
use InfinitySubiektAgent\Release;
use InfinitySubiektAgent\Subiekt\Sfera;
use InfinitySubiektAgent\Subiekt\Sql;
use InfinitySubiektAgent\Version;

/**
 * ping. Problem z Sferą albo SQL nie jest błędem zadania, tylko treścią pola `subiekt`.
 */
final readonly class Ping implements Method
{
    public function __construct(
        private Sfera $sfera,
        private Sql $sql,
        private Log $log,
        private Release $release,
    ) {
    }

    public function execute(array $params): array
    {
        $status = $this->status();
        if ($status !== 'ok') {
            $this->log->error('ping: ' . $status);
        }

        return [
            'pong' => true,
            'agent_version' => Version::NUMBER,
            'subiekt' => $status,
            // stan zdalnej aktualizacji
            'release' => $this->release->info(),
        ];
    }

    private function status(): string
    {
        try {
            $this->sfera->ensureAlive();
        } catch (\Throwable $e) {
            return 'Sfera: ' . Sfera::describe($e);
        }

        try {
            $this->sql->value('SELECT 1');
        } catch (\Throwable $e) {
            return 'SQL: ' . Sfera::describe($e);
        }

        return 'ok';
    }
}
