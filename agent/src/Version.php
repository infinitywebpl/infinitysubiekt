<?php

declare(strict_types=1);

namespace InfinitySubiektAgent;

if (PHP_SAPI !== 'cli') {
    exit;
}

/**
 * Wersja agenta zgłaszana w ping. Wersja demo ma numer 0.0.x, niższy od każdej pełnej wersji,
 * więc „Zaktualizuj agenta” w module po wgraniu pełnej wersji zawsze ją zainstaluje.
 */
final class Version
{
    public const NUMBER = '0.0.1';
}
