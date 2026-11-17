<?php

declare(strict_types=1);

namespace InfinitySubiektAgent;

if (PHP_SAPI !== 'cli') {
    exit;
}

/**
 * Jeden agent na maszynę: flock na logs/agent.lock. System zwalnia blokadę
 * sam, gdy proces się kończy (także po awarii), więc nie ma blokad „wiszących”.
 * PID jest w osobnym agent.pid, bo na Windows zablokowanego pliku nie da się czytać.
 */
final class ProcessLock
{
    public const FILE = 'agent.lock';
    public const PID_FILE = 'agent.pid';

    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $dir)
    {
    }

    /**
     * @return bool false, gdy blokadę trzyma inny proces
     *
     * @throws AgentException
     */
    public function acquire(): bool
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new AgentException('Nie można utworzyć katalogu ' . $this->dir);
        }

        $handle = @fopen($this->path(), 'c+');
        if ($handle === false) {
            throw new AgentException('Nie można otworzyć pliku blokady ' . $this->path());
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->handle = $handle;
        @file_put_contents($this->dir . DIRECTORY_SEPARATOR . self::PID_FILE, (string) getmypid());

        return true;
    }

    /**
     * PID procesu, który trzyma blokadę (do komunikatu), albo ''.
     */
    public function holder(): string
    {
        $pid = @file_get_contents($this->dir . DIRECTORY_SEPARATOR . self::PID_FILE);

        return $pid === false ? '' : trim($pid);
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        @unlink($this->dir . DIRECTORY_SEPARATOR . self::PID_FILE);
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    private function path(): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . self::FILE;
    }
}
