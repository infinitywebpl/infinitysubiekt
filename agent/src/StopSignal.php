<?php

declare(strict_types=1);

namespace InfinitySubiektAgent;

if (PHP_SAPI !== 'cli') {
    exit;
}

/**
 * Łagodne zatrzymanie: Ctrl+C w konsoli albo zatrzymanie usługi NSSM (wysyła Ctrl+C).
 * Bieżące zadanie zawsze się kończy i jego wynik trafia do sklepu; czekający poll
 * przerywa się od razu. Drugie Ctrl+C kończy proces natychmiast.
 */
final class StopSignal
{
    private bool $requested = false;

    private ?\Closure $onRequest = null;

    public function install(\Closure $onRequest): void
    {
        $this->onRequest = $onRequest;

        if (function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(function (int $event): void {
                $this->request();
            });
        } elseif (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, fn () => $this->request());
            pcntl_signal(SIGTERM, fn () => $this->request());
        }
    }

    public function requested(): bool
    {
        return $this->requested;
    }

    public function request(): void
    {
        if ($this->requested) {
            exit(1);
        }

        $this->requested = true;
        if ($this->onRequest !== null) {
            ($this->onRequest)();
        }
    }

    /**
     * Czekanie przerywane zatrzymaniem (backoff).
     */
    public function sleep(int $seconds): void
    {
        for ($i = 0; $i < $seconds && !$this->requested; $i++) {
            sleep(1);
        }
    }
}
