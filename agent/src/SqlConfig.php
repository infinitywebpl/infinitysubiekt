<?php

declare(strict_types=1);

namespace InfinitySubiektAgent;

if (PHP_SAPI !== 'cli') {
    exit;
}

/**
 * Odczyty SQL z bazy podmiotu (sekcja 'sql' config.php).
 */
final readonly class SqlConfig
{
    public const DRIVER_AUTO = 'auto';
    public const DRIVER_PDO = 'pdo';
    public const DRIVER_ADODB = 'adodb';

    public function __construct(
        public string $driver,
        public string $adodbProvider,
        public bool $trustServerCertificate,
    ) {
    }
}
