<?php

declare(strict_types=1);

namespace InfinitySubiektAgent\Method;

if (PHP_SAPI !== 'cli') {
    exit;
}

/**
 * Metoda kontraktu. Błąd biznesowy: AgentException
 * albo wynik z kluczem `error`; moduł obsługuje oba tak samo.
 */
interface Method
{
    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function execute(array $params): array;
}
