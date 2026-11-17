<?php

declare(strict_types=1);

namespace InfinitySubiektAgent\Subiekt;

if (PHP_SAPI !== 'cli') {
    exit;
}

use InfinitySubiektAgent\AgentException;
use InfinitySubiektAgent\Log;
use InfinitySubiektAgent\SqlConfig;
use InfinitySubiektAgent\SubiektConfig;
use InfinitySubiektAgent\Text;

/**
 * Odczyty z bazy podmiotu, których Sfera nie daje. Tylko SELECT: zapisy idą przez Sferę.
 * Sterownik: pdo_sqlsrv, a gdy go nie ma, ADODB przez COM.
 * Parametry jako ? w zapytaniu.
 */
final class Sql
{
    // ADO: CommandTypeEnum, ParameterDirectionEnum, DataTypeEnum
    private const AD_CMD_TEXT = 1;
    private const AD_PARAM_INPUT = 1;
    private const AD_INTEGER = 3;
    private const AD_DOUBLE = 5;
    private const AD_BOOLEAN = 11;
    private const AD_VAR_WCHAR = 202;

    // VARENUM
    private const VT_DATE = 7;

    private ?\PDO $pdo = null;

    private ?\variant $ado = null;

    public function __construct(
        private readonly SubiektConfig $subiekt,
        private readonly SqlConfig $config,
        private readonly Log $log,
    ) {
    }

    /**
     * @param list<scalar|null> $params
     * @param int|null $timeout s, po których SQL Server przerywa zapytanie (null = domyślny sterownika)
     *
     * @return list<array<string, mixed>>
     *
     * @throws AgentException
     */
    public function rows(string $sql, array $params = [], ?int $timeout = null): array
    {
        if (!preg_match('/^\s*(SELECT|WITH)\b/i', $sql)) {
            throw new AgentException('Sql: dozwolone tylko zapytania SELECT.');
        }

        $this->connect();

        try {
            return $this->pdo !== null ? $this->pdoRows($sql, $params, $timeout) : $this->adoRows($sql, $params, $timeout);
        } catch (\PDOException | \com_exception $e) {
            throw new AgentException('Błąd zapytania SQL: ' . Sfera::describe($e), 0, $e);
        }
    }

    /**
     * @param list<scalar|null> $params
     *
     * @return array<string, mixed>|null
     *
     * @throws AgentException
     */
    public function row(string $sql, array $params = []): ?array
    {
        return $this->rows($sql, $params)[0] ?? null;
    }

    /**
     * Pierwsza kolumna pierwszego wiersza.
     *
     * @param list<scalar|null> $params
     *
     * @throws AgentException
     */
    public function value(string $sql, array $params = []): mixed
    {
        $row = $this->row($sql, $params);

        return $row === null ? null : reset($row);
    }

    /**
     * Sterownik użyty do połączenia: pdo albo adodb ('' przed połączeniem).
     */
    public function driver(): string
    {
        return match (true) {
            $this->pdo !== null => SqlConfig::DRIVER_PDO,
            $this->ado !== null => SqlConfig::DRIVER_ADODB,
            default => '',
        };
    }

    public function disconnect(): void
    {
        $this->pdo = null;
        if ($this->ado !== null) {
            try {
                $this->ado->Close();
            } catch (\com_exception) {
            }
            $this->ado = null;
        }
    }

    /**
     * @throws AgentException
     */
    private function connect(): void
    {
        if ($this->pdo !== null || $this->ado !== null) {
            return;
        }

        $hasPdo = extension_loaded('pdo_sqlsrv');
        $driver = $this->config->driver;
        if ($driver === SqlConfig::DRIVER_AUTO) {
            $driver = $hasPdo ? SqlConfig::DRIVER_PDO : SqlConfig::DRIVER_ADODB;
        }

        if ($driver === SqlConfig::DRIVER_PDO) {
            if (!$hasPdo) {
                throw new AgentException('Brak rozszerzenia pdo_sqlsrv (sql.driver = pdo). Dodaj je w php.ini albo ustaw sql.driver = adodb.');
            }
            $this->connectPdo();
        } else {
            $this->connectAdo();
        }

        $this->log->info('Połączono z SQL Server przez ' . $this->driver());
    }

    /**
     * @throws AgentException
     */
    private function connectPdo(): void
    {
        $dsn = 'sqlsrv:Server=' . $this->subiekt->server
            . ';Database=' . $this->subiekt->database
            . ';LoginTimeout=10'
            . ($this->config->trustServerCertificate ? ';TrustServerCertificate=1' : '');

        try {
            // logowanie Windows: bez użytkownika i hasła
            $this->pdo = $this->subiekt->windowsAuth()
                ? new \PDO($dsn, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION])
                : new \PDO($dsn, $this->subiekt->sqlUser, $this->subiekt->sqlPassword, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (\PDOException $e) {
            throw new AgentException('Nie udało się połączyć z SQL Server (pdo_sqlsrv): ' . Text::utf8($e->getMessage()), 0, $e);
        }
    }

    /**
     * @throws AgentException
     */
    private function connectAdo(): void
    {
        if (!class_exists(\COM::class)) {
            throw new AgentException('Brak pdo_sqlsrv i com_dotnet: SQL niedostępny.');
        }

        $parts = [
            'Provider=' . $this->config->adodbProvider,
            'Data Source=' . $this->subiekt->server,
            'Initial Catalog=' . $this->subiekt->database,
            'Connect Timeout=10',
        ];
        if ($this->subiekt->windowsAuth()) {
            $parts[] = 'Integrated Security=SSPI';
        } else {
            $parts[] = 'User ID=' . $this->subiekt->sqlUser;
            $parts[] = 'Password=' . $this->subiekt->sqlPassword;
        }
        if ($this->config->trustServerCertificate) {
            $parts[] = 'TrustServerCertificate=True';
        }

        try {
            $connection = new \COM('ADODB.Connection', null, CP_UTF8);
            $connection->Open(implode(';', $parts));
        } catch (\com_exception $e) {
            throw new AgentException(
                'Nie udało się połączyć z SQL Server (ADODB, ' . $this->config->adodbProvider . '): ' . Sfera::describe($e),
                0,
                $e
            );
        }

        $this->ado = $connection;
    }

    /**
     * @param list<scalar|null> $params
     *
     * @return list<array<string, mixed>>
     */
    private function pdoRows(string $sql, array $params, ?int $timeout): array
    {
        $options = $timeout !== null && defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT') ? [\PDO::SQLSRV_ATTR_QUERY_TIMEOUT => $timeout] : [];
        $statement = $this->pdo->prepare($sql, $options);
        $statement->execute($params);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @param list<scalar|null> $params
     *
     * @return list<array<string, mixed>>
     */
    private function adoRows(string $sql, array $params, ?int $timeout): array
    {
        $command = new \COM('ADODB.Command', null, CP_UTF8);
        $command->ActiveConnection = $this->ado;
        $command->CommandType = self::AD_CMD_TEXT;
        $command->CommandText = $sql;
        if ($timeout !== null) {
            $command->CommandTimeout = $timeout;
        }

        foreach ($params as $value) {
            [$type, $size] = match (true) {
                is_int($value) => [self::AD_INTEGER, 4],
                is_float($value) => [self::AD_DOUBLE, 8],
                is_bool($value) => [self::AD_BOOLEAN, 1],
                default => [self::AD_VAR_WCHAR, max(1, mb_strlen((string) $value))],
            };
            $command->Parameters->Append($command->CreateParameter('', $type, self::AD_PARAM_INPUT, $size, $value));
        }

        $recordset = $command->Execute();

        $rows = [];
        while (!$recordset->EOF) {
            $row = [];
            $count = $recordset->Fields->Count;
            for ($i = 0; $i < $count; $i++) {
                $field = $recordset->Fields->Item($i);
                $row[(string) $field->Name] = self::adoValue($field->Value);
            }
            $rows[] = $row;
            $recordset->MoveNext();
        }
        $recordset->Close();

        return $rows;
    }

    /**
     * Wartości ADO, których PHP nie zamienia sam: daty jako tekst, kwoty jako float
     * (tekst z variant zależy od przecinka dziesiętnego w Windows).
     */
    private static function adoValue(mixed $value): mixed
    {
        if (!$value instanceof \variant) {
            return $value;
        }

        if (variant_get_type($value) === self::VT_DATE) {
            return date('Y-m-d H:i:s', variant_date_to_timestamp($value));
        }
        if (Value::isNumericVariant($value)) {
            return Value::float($value);
        }

        return (string) $value;
    }
}
