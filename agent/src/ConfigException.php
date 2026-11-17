<?php

declare(strict_types=1);

namespace InfinitySubiektAgent;

if (PHP_SAPI !== 'cli') {
    exit;
}

/**
 * Błąd w config.php (kod wyjścia 2, jak zła składnia polecenia).
 */
final class ConfigException extends AgentException
{
}
