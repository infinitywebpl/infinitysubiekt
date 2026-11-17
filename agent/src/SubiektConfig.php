<?php

declare(strict_types=1);

namespace InfinitySubiektAgent;

if (PHP_SAPI !== 'cli') {
    exit;
}

/**
 * Połączenie ze Sferą i bazą podmiotu (sekcja 'subiekt' config.php).
 */
final readonly class SubiektConfig
{
    public const AUTH_SQL = 0;
    public const AUTH_WINDOWS = 1;

    public function __construct(
        public string $server,
        public string $database,
        public int $authentication,
        public string $sqlUser,
        public string $sqlPassword,
        public string $operator,
        public string $operatorPassword,
        public int $codepage,
    ) {
    }

    public function windowsAuth(): bool
    {
        return $this->authentication === self::AUTH_WINDOWS;
    }
}
