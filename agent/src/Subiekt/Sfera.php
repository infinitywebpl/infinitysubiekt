<?php

declare(strict_types=1);

namespace InfinitySubiektAgent\Subiekt;

if (PHP_SAPI !== 'cli') {
    exit;
}

use InfinitySubiektAgent\AgentException;
use InfinitySubiektAgent\Log;
use InfinitySubiektAgent\SubiektConfig;
use InfinitySubiektAgent\Text;

/**
 * Połączenie z Subiektem GT przez Sferę (COM InsERT.GT), jedno na cały czas życia procesu.
 *
 * Strona kodowa COM z konfiguracji: przy utf8 PHP sam zamienia UTF-8 ↔ BSTR,
 * a toCom()/fromCom() nic nie robią; przy cp1250 konwertują każdy tekst.
 */
final class Sfera
{
    // UruchomDopasujEnum::gtaUruchomDopasuj, UruchomEnum::gtaUruchomWTle
    private const MATCH_RUNNING = 0;
    private const RUN_IN_BACKGROUND = 4;

    // GT.Produkt: Subiekt GT
    private const PRODUCT_SUBIEKT = 1;

    // HRESULT zerwanego połączenia z procesem Sfery: trzeba połączyć się od nowa
    private const CONNECTION_LOST = [
        0x800706BA, // serwer RPC jest niedostępny
        0x800706BE, // zdalne wywołanie procedury nie powiodło się
        0x80010108, // wywołany obiekt rozłączył się od swoich klientów
    ];

    // HRESULT licencji z dokumentacji GT.Uruchom
    private const LICENSE_ERRORS = [
        0x800411F2 => 'brak ważnej licencji Sfery w podmiocie',
        0x80041248 => 'licencja Sfery wygasła',
    ];

    // po nieudanym połączeniu kolejna próba dopiero po tylu sekundach, wcześniej od razu ostatni błąd:
    // łączenie z nieosiągalnym serwerem trwa kilkadziesiąt sekund, a ping z modułu czeka 15 s
    private const RETRY_AFTER = 60;

    private ?AgentException $lastFailure = null;

    private int $lastFailureAt = 0;

    // obiekt GT żyje tak długo jak połączenie, które z niego powstało
    private ?\variant $gt = null;

    private ?\variant $subiekt = null;

    public function __construct(
        private readonly SubiektConfig $config,
        private readonly Log $log,
    ) {
    }

    /**
     * Obiekt Subiekt (połączenie przy pierwszym użyciu).
     *
     * @throws AgentException
     */
    public function subiekt(): \variant
    {
        if ($this->subiekt !== null) {
            return $this->subiekt;
        }

        $wait = $this->lastFailureAt + self::RETRY_AFTER - time();
        if ($this->lastFailure !== null && $wait > 0) {
            throw new AgentException($this->lastFailure->getMessage() . sprintf(' (ponowna próba za %d s)', $wait), 0, $this->lastFailure);
        }

        try {
            $this->subiekt = $this->connect();
        } catch (AgentException $e) {
            $this->lastFailure = $e;
            $this->lastFailureAt = time();

            throw $e;
        }

        $this->lastFailure = null;

        return $this->subiekt;
    }

    public function isConnected(): bool
    {
        return $this->subiekt !== null;
    }

    /**
     * Sprawdza żywe połączenie lekkim odczytem; zerwane zamyka, żeby subiekt() połączył się od nowa.
     *
     * @throws AgentException gdy nie da się połączyć
     */
    public function ensureAlive(): void
    {
        if ($this->subiekt !== null) {
            try {
                $this->subiekt->MagazynId;

                return;
            } catch (\com_exception $e) {
                $this->log->error('Połączenie ze Sferą zerwane, łączę ponownie', ['error' => self::describe($e)]);
                $this->disconnect();
            }
        }

        $this->subiekt();
    }

    public function disconnect(): void
    {
        $this->subiekt = null;
        $this->gt = null;
        gc_collect_cycles();
    }

    /**
     * Nazwa i NIP podmiotu (diagnostyka i test polskich znaków).
     *
     * @return array{nazwa: string, nip: string, magazyn_id: int}
     *
     * @throws AgentException
     */
    public function podmiot(): array
    {
        $subiekt = $this->subiekt();
        $dane = $subiekt->DanePodmiotu;

        return [
            'nazwa' => $this->fromCom((string) $dane->Nazwa),
            'nip' => $this->fromCom((string) $dane->NIP),
            'magazyn_id' => (int) $subiekt->MagazynId,
        ];
    }

    /**
     * Tekst z PHP (UTF-8) do COM.
     */
    public function toCom(string $text): string
    {
        if ($this->config->codepage !== 1250) {
            return $text;
        }

        $converted = @iconv('UTF-8', 'CP1250//TRANSLIT', $text);

        return $converted === false ? $text : $converted;
    }

    /**
     * Tekst z COM do PHP (UTF-8).
     */
    public function fromCom(string $text): string
    {
        if ($this->config->codepage !== 1250) {
            return $text;
        }

        $converted = @iconv('CP1250', 'UTF-8//IGNORE', $text);

        return $converted === false ? Text::utf8($text) : $converted;
    }

    /**
     * Opis błędu dla człowieka: komunikat w UTF-8 i kod HRESULT.
     */
    public static function describe(\Throwable $e): string
    {
        $message = trim(Text::utf8($e->getMessage()));
        if (!$e instanceof \com_exception) {
            return $message;
        }

        $code = self::hresult($e);

        // COM bez opisu daje „Source: Unknown / Description: Unknown”: to szum, nie informacja
        $message = preg_replace('/\s*\R\s*/u', '; ', $message) ?? $message;
        if (preg_match('/^Source: Unknown; Description: Unknown$/', $message)) {
            $message = 'błąd bez opisu';
        }

        $license = self::LICENSE_ERRORS[$code] ?? null;

        return ($license !== null ? $license . ': ' : '') . $message . sprintf(' (0x%08X)', $code);
    }

    /**
     * Czy błąd oznacza zerwane połączenie z procesem Sfery (wtedy disconnect() i nowe połączenie).
     */
    public static function isConnectionLost(\Throwable $e): bool
    {
        return $e instanceof \com_exception && in_array(self::hresult($e), self::CONNECTION_LOST, true);
    }

    /**
     * @throws AgentException
     */
    private function connect(): \variant
    {
        if (!class_exists(\COM::class)) {
            throw new AgentException('Brak rozszerzenia com_dotnet: dodaj extension=com_dotnet w php.ini.');
        }

        $start = microtime(true);
        $config = $this->config;

        try {
            $gt = new \COM('InsERT.GT', null, $config->codepage);
            $dodatki = new \COM('InsERT.Dodatki', null, $config->codepage);
        } catch (\com_exception $e) {
            $hint = PHP_INT_SIZE === 8 ? ' To PHP 64-bit: spróbuj PHP x86 (32-bit).' : ' Sprawdź instalację Sfery na tym koncie Windows.';

            throw new AgentException('Nie można utworzyć obiektu InsERT.GT: ' . self::describe($e) . '.' . $hint, 0, $e);
        }

        try {
            $gt->Produkt = self::PRODUCT_SUBIEKT;
            $gt->Serwer = $this->toCom($config->server);
            $gt->Baza = $this->toCom($config->database);
            $gt->Autentykacja = $config->authentication;
            if (!$config->windowsAuth()) {
                $gt->Uzytkownik = $this->toCom($config->sqlUser);
                $gt->UzytkownikHaslo = $dodatki->Szyfruj($this->toCom($config->sqlPassword));
            }
            $gt->Operator = $this->toCom($config->operator);
            $gt->OperatorHaslo = $dodatki->Szyfruj($this->toCom($config->operatorPassword));

            $subiekt = $gt->Uruchom(self::MATCH_RUNNING, self::RUN_IN_BACKGROUND);
        } catch (\com_exception $e) {
            throw new AgentException(
                'Nie udało się połączyć z Subiektem GT: ' . self::describe($e)
                . '. Sprawdź serwer SQL, bazę, użytkownika SQL, operatora i hasła w config.php.',
                0,
                $e
            );
        }

        if (!$subiekt instanceof \variant) {
            throw new AgentException('Nie udało się połączyć z Subiektem GT: Uruchom() nie zwrócił obiektu.');
        }

        $this->gt = $gt;
        $this->log->info(sprintf(
            'Połączono ze Sferą (%s / %s, operator %s) w %.1f s',
            $config->server,
            $config->database,
            $config->operator,
            microtime(true) - $start
        ));

        return $subiekt;
    }

    private static function hresult(\com_exception $e): int
    {
        return $e->getCode() & 0xFFFFFFFF;
    }
}
