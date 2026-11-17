<?php

declare(strict_types=1);

namespace InfinitySubiektAgent;

if (PHP_SAPI !== 'cli') {
    exit;
}

/**
 * Błąd z komunikatem dla człowieka (konfiguracja, dane, Subiekt). Do modułu trafia
 * jako {"error": komunikat}, bez śladu stosu.
 */
class AgentException extends \RuntimeException
{
}
