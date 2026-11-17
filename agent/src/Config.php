<?php

declare(strict_types=1);

namespace InfinitySubiektAgent;

if (PHP_SAPI !== 'cli') {
    exit;
}

/**
 * Ustawienia z config.php (wzór: config.sample.php). Brak klucza = wartość domyślna,
 * zły typ albo wartość = ConfigException z listą wszystkich problemów naraz.
 */
final readonly class Config
{
    public const FILE = 'config.php';

    // strony kodowe COM
    private const CODEPAGES = ['utf8' => 65001, 'cp1250' => 1250];

    public function __construct(
        public string $endpoint,
        public string $token,
        public int $pollWait,
        public int $httpTimeout,
        public ?string $caFile,
        public SubiektConfig $subiekt,
        public SqlConfig $sql,
        public string $issuer,
        public int $fiscalPrinterId,
        public int $retailPriceLevel,
        public int $duplicateDays,
        public string $logDir,
        public int $logDays,
        public int $maxRuntimeHours,
        public int $maxMemoryMb,
        public bool $updateEnabled,
    ) {
    }

    /**
     * @throws ConfigException
     */
    public static function load(string $agentDir): self
    {
        $file = $agentDir . DIRECTORY_SEPARATOR . self::FILE;
        if (!is_file($file)) {
            throw new ConfigException('Brak pliku ' . $file . '. Skopiuj config.sample.php jako config.php i uzupełnij.');
        }

        $data = require $file;
        if (!is_array($data)) {
            throw new ConfigException(self::FILE . ' musi zwracać tablicę (return [...]).');
        }

        return self::fromArray($data, $agentDir);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws ConfigException
     */
    public static function fromArray(array $data, string $agentDir): self
    {
        $errors = [];
        $subiekt = is_array($data['subiekt'] ?? null) ? $data['subiekt'] : [];
        $sql = is_array($data['sql'] ?? null) ? $data['sql'] : [];

        $codepageName = self::str($subiekt, 'codepage', 'utf8', $errors, 'subiekt.');
        if (!isset(self::CODEPAGES[$codepageName])) {
            $errors[] = 'subiekt.codepage: utf8 albo cp1250';
        }

        $authentication = self::int($subiekt, 'authentication', SubiektConfig::AUTH_SQL, $errors, 'subiekt.');
        if (!in_array($authentication, [SubiektConfig::AUTH_SQL, SubiektConfig::AUTH_WINDOWS], true)) {
            $errors[] = 'subiekt.authentication: 0 (SQL Server) albo 1 (Windows)';
        }

        $driver = self::str($sql, 'driver', SqlConfig::DRIVER_AUTO, $errors, 'sql.');
        if (!in_array($driver, [SqlConfig::DRIVER_AUTO, SqlConfig::DRIVER_PDO, SqlConfig::DRIVER_ADODB], true)) {
            $errors[] = 'sql.driver: auto, pdo albo adodb';
        }

        $pollWait = self::int($data, 'poll_wait', 20, $errors);
        $httpTimeout = self::int($data, 'http_timeout', 35, $errors);
        if ($pollWait < 1 || $pollWait > 20) {
            $errors[] = 'poll_wait: 1-20 s';
        }
        if ($httpTimeout < $pollWait + 15) {
            $errors[] = 'http_timeout: co najmniej poll_wait + 15 s';
        }

        $caFile = $data['ca_file'] ?? null;
        if ($caFile !== null && (!is_string($caFile) || !is_file($caFile))) {
            $errors[] = 'ca_file: ścieżka do istniejącego pliku albo null';
        }

        $config = new self(
            endpoint: self::str($data, 'endpoint', '', $errors),
            token: self::str($data, 'token', '', $errors),
            pollWait: $pollWait,
            httpTimeout: $httpTimeout,
            caFile: is_string($caFile) ? $caFile : null,
            subiekt: new SubiektConfig(
                server: self::str($subiekt, 'server', '', $errors, 'subiekt.'),
                database: self::str($subiekt, 'database', '', $errors, 'subiekt.'),
                authentication: $authentication,
                sqlUser: self::str($subiekt, 'sql_user', '', $errors, 'subiekt.'),
                sqlPassword: self::str($subiekt, 'sql_password', '', $errors, 'subiekt.', false),
                operator: self::str($subiekt, 'operator', '', $errors, 'subiekt.'),
                operatorPassword: self::str($subiekt, 'operator_password', '', $errors, 'subiekt.', false),
                codepage: self::CODEPAGES[$codepageName] ?? self::CODEPAGES['utf8'],
            ),
            sql: new SqlConfig(
                driver: $driver,
                adodbProvider: self::str($sql, 'adodb_provider', 'MSOLEDBSQL', $errors, 'sql.'),
                trustServerCertificate: self::bool($sql, 'trust_server_certificate', true, $errors, 'sql.'),
            ),
            issuer: self::str($data, 'issuer', '', $errors),
            fiscalPrinterId: self::int($data, 'fiscal_printer_id', 0, $errors),
            retailPriceLevel: max(1, self::int($data, 'retail_price_level', 1, $errors)),
            duplicateDays: self::int($data, 'duplicate_days', 7, $errors),
            logDir: self::str($data, 'log_dir', $agentDir . DIRECTORY_SEPARATOR . 'logs', $errors),
            logDays: self::int($data, 'log_days', 30, $errors),
            maxRuntimeHours: self::int($data, 'max_runtime_hours', 12, $errors),
            maxMemoryMb: self::int($data, 'max_memory_mb', 256, $errors),
            updateEnabled: self::bool($data, 'update_enabled', false, $errors),
        );

        if ($errors) {
            throw new ConfigException("Błędy w " . self::FILE . ":\n- " . implode("\n- ", $errors));
        }

        return $config;
    }

    /**
     * Ustawienia potrzebne do połączenia z Subiektem (ping, call, diag, run).
     *
     * @throws ConfigException
     */
    public function requireSubiekt(): void
    {
        $errors = [];
        foreach (['server' => $this->subiekt->server, 'database' => $this->subiekt->database, 'operator' => $this->subiekt->operator] as $key => $value) {
            if ($value === '') {
                $errors[] = 'subiekt.' . $key . ' jest pusty';
            }
        }
        if (!$this->subiekt->windowsAuth() && $this->subiekt->sqlUser === '') {
            $errors[] = 'subiekt.sql_user jest pusty (albo ustaw authentication = 1)';
        }

        if ($errors) {
            throw new ConfigException("Błędy w " . self::FILE . ":\n- " . implode("\n- ", $errors));
        }
    }

    /**
     * Ustawienia potrzebne do pracy z kolejką sklepu (run, once).
     *
     * @throws ConfigException
     */
    public function requireQueue(): void
    {
        $errors = [];
        if (!preg_match('#^https?://#i', $this->endpoint)) {
            $errors[] = 'endpoint: pełny adres z zakładki „Agent” modułu';
        }
        if ($this->token === '') {
            $errors[] = 'token jest pusty';
        }

        if ($errors) {
            throw new ConfigException("Błędy w " . self::FILE . ":\n- " . implode("\n- ", $errors));
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param string[] $errors
     * @param bool $trim hasła bez przycinania (spacja może być częścią hasła)
     */
    private static function str(array $data, string $key, string $default, array &$errors, string $prefix = '', bool $trim = true): string
    {
        $value = $data[$key] ?? $default;
        if (!is_string($value)) {
            $errors[] = $prefix . $key . ': oczekiwany tekst';

            return $default;
        }

        return $trim ? trim($value) : $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param string[] $errors
     */
    private static function int(array $data, string $key, int $default, array &$errors, string $prefix = ''): int
    {
        $value = $data[$key] ?? $default;
        if (!is_int($value) || $value < 0) {
            $errors[] = $prefix . $key . ': oczekiwana liczba całkowita ≥ 0';

            return $default;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param string[] $errors
     */
    private static function bool(array $data, string $key, bool $default, array &$errors, string $prefix = ''): bool
    {
        $value = $data[$key] ?? $default;
        if (!is_bool($value)) {
            $errors[] = $prefix . $key . ': oczekiwane true albo false';

            return $default;
        }

        return $value;
    }
}
