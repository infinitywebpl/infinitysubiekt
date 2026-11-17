<?php

declare(strict_types=1);

namespace InfinitySubiektAgent;

if (PHP_SAPI !== 'cli') {
    exit;
}

/**
 * Prośba metody o zakończenie procesu po dostarczeniu wyniku (agent/update, agent/rollback).
 * Pętla kończy pracę z kodem 0, a usługa (NSSM) albo Harmonogram zadań uruchamia launcher od nowa.
 */
final class Restart
{
    private ?string $reason = null;

    public function request(string $reason): void
    {
        $this->reason = $reason;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}
