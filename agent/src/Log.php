<?php

declare(strict_types=1);

namespace InfinitySubiektAgent;

if (PHP_SAPI !== 'cli') {
    exit;
}

/**
 * Log dzienny w log_dir (agent-RRRR-MM-DD.log), usuwanie plików starszych niż log_days.
 * W konsoli (test ręczny) linie idą też na ekran. W usłudze NSSM stdout trafia do
 * service.log, więc tam echo jest wyłączone, żeby nie dublować wpisów.
 */
final class Log
{
    private const PREFIX = 'agent-';

    private bool $cleaned = false;

    public function __construct(
        private readonly string $dir,
        private readonly int $days,
        private readonly bool $echo,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function write(string $level, string $message, array $context): void
    {
        $line = date('Y-m-d H:i:s') . ' ' . $level . ' ' . Text::utf8($message)
            . ($context ? ' ' . Text::json($context) : '') . PHP_EOL;

        if ($this->echo) {
            fwrite($level === 'ERROR' ? STDERR : STDOUT, $line);
        }

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return;
        }

        @file_put_contents($this->dir . DIRECTORY_SEPARATOR . self::PREFIX . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
        $this->cleanOld();
    }

    private function cleanOld(): void
    {
        if ($this->cleaned || $this->days < 1) {
            return;
        }
        $this->cleaned = true;

        $limit = time() - $this->days * 86400;
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . self::PREFIX . '*.log') ?: [] as $file) {
            if (filemtime($file) < $limit) {
                @unlink($file);
            }
        }
    }
}
