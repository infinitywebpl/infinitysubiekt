<?php

declare(strict_types=1);

namespace InfinitySubiektAgent;

if (PHP_SAPI !== 'cli') {
    exit;
}

use InfinitySubiektAgent\Method\DemoMethod;
use InfinitySubiektAgent\Subiekt\Sfera;

/**
 * Polecenia agent.php. Kody wyjścia: 0 OK, 1 błąd działania, 2 zła składnia albo konfiguracja.
 */
final class Cli
{
    public const EXIT_OK = 0;
    public const EXIT_ERROR = 1;
    public const EXIT_USAGE = 2;

    // tekst do sprawdzenia polskich znaków w drodze PHP → SQL → PHP
    private const POLISH_SAMPLE = 'zażółć gęślą jaźń ZAŻÓŁĆ GĘŚLĄ JAŹŃ';

    private const REQUIRED_EXTENSIONS = ['com_dotnet', 'curl', 'mbstring', 'openssl', 'iconv'];

    /**
     * @param string $agentDir katalog agenta: config.php, logs\, releases\
     * @param string $codeDir katalog kodu wybrany przez launcher (agentDir albo releases\<wersja>)
     */
    public function __construct(
        private readonly string $agentDir,
        private readonly string $codeDir = '',
    ) {
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $this->consoleUtf8();

        $command = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        if (in_array($command, ['help', '--help', '-h'], true)) {
            $this->out($this->help());

            return self::EXIT_OK;
        }
        if (!in_array($command, ['ping', 'diag', 'schema', 'call', 'run', 'once', 'selftest'], true)) {
            $this->err('Nieznane polecenie: ' . $command . "\n\n" . $this->help());

            return self::EXIT_USAGE;
        }

        try {
            $config = Config::load($this->agentDir);
        } catch (ConfigException $e) {
            $this->err($e->getMessage());

            return self::EXIT_USAGE;
        }

        $release = new Release($this->agentDir, $this->codeDir !== '' ? $this->codeDir : $this->agentDir);
        $services = new Services($config, new Log($config->logDir, $config->logDays, $this->interactive()), $release);

        try {
            return match ($command) {
                'ping' => $this->ping($services),
                'diag' => $this->diag($services),
                'schema' => $this->schema($services),
                'call' => $this->call($services, $args),
                'run' => $this->work($services, false),
                'once' => $this->work($services, true),
                'selftest' => $this->selftest($services),
            };
        } catch (AgentException $e) {
            $services->log->error($e->getMessage());
            if (!$this->interactive()) {
                $this->err($e->getMessage());
            }

            return $e instanceof ConfigException ? self::EXIT_USAGE : self::EXIT_ERROR;
        }
    }

    /**
     * Metoda ping lokalnie, bez kolejki sklepu.
     *
     * @throws AgentException
     */
    private function ping(Services $services): int
    {
        $services->config->requireSubiekt();
        $body = $services->taskRunner()->run('ping', []);
        $this->out(Text::json($body, true));

        return ($body['result']['subiekt'] ?? '') === 'ok' ? self::EXIT_OK : self::EXIT_ERROR;
    }

    /**
     * Sprawdzenie środowiska krok po kroku: PHP, konfiguracja, Sfera, SQL, polskie znaki.
     */
    private function diag(Services $services): int
    {
        $config = $services->config;
        $ok = true;
        $check = function (string $label, bool $passed, string $details = '') use (&$ok): void {
            $ok = $ok && $passed;
            $this->out(sprintf('[%s] %s%s', $passed ? ' OK ' : 'BŁĄD', $label, $details !== '' ? ': ' . $details : ''));
        };

        $this->out('Agent ' . Version::NUMBER);
        $check('PHP ' . PHP_VERSION, version_compare(PHP_VERSION, '8.4.0', '>='), 'wymagane 8.4');
        // Sfera bywa zarejestrowana tylko dla 32-bit: wtedy x64 nie utworzy InsERT.GT (sprawdzenie niżej)
        $this->out(sprintf('[INFO] PHP %s', PHP_INT_SIZE === 4 ? '32-bit (x86)' : '64-bit; gdy InsERT.GT się nie utworzy, użyj PHP x86'));
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $check('rozszerzenie ' . $extension, extension_loaded($extension));
        }
        $this->out(sprintf(
            '[%s] rozszerzenie pdo_sqlsrv%s',
            extension_loaded('pdo_sqlsrv') ? ' OK ' : 'INFO',
            extension_loaded('pdo_sqlsrv') ? '' : ': brak, SQL przez ADODB (' . $config->sql->adodbProvider . ')'
        ));
        $caFile = $config->caFile ?? (string) ini_get('curl.cainfo');
        $check('certyfikaty CA dla HTTPS', $caFile !== '' && is_file($caFile), $caFile !== '' ? $caFile : 'ustaw curl.cainfo w php.ini albo ca_file');

        $this->out('');
        $this->out(sprintf(
            'Konfiguracja: %s / %s, operator %s, logowanie %s, strona kodowa COM %s, SQL %s',
            $config->subiekt->server,
            $config->subiekt->database,
            $config->subiekt->operator,
            $config->subiekt->windowsAuth() ? 'Windows' : 'SQL (' . $config->subiekt->sqlUser . ')',
            $config->subiekt->codepage === 1250 ? 'cp1250' : 'utf8',
            $config->sql->driver
        ));
        $check('endpoint i token', $config->endpoint !== '' && $config->token !== '', $config->endpoint);

        try {
            $config->requireSubiekt();
        } catch (ConfigException $e) {
            $check('ustawienia Subiekta', false, $e->getMessage());

            return self::EXIT_ERROR;
        }

        $this->out('');
        try {
            $start = microtime(true);
            $podmiot = $services->sfera()->podmiot();
            $check('Sfera', true, sprintf('połączono w %.1f s, magazyn %d', microtime(true) - $start, $podmiot['magazyn_id']));
            $this->out('       podmiot: ' . $podmiot['nazwa'] . ($podmiot['nip'] !== '' ? ', NIP ' . $podmiot['nip'] : ''));
            $this->out('       Polskie znaki w nazwie mają wyglądać jak w Subiekcie. Jeśli nie: subiekt.codepage = cp1250.');
        } catch (\Throwable $e) {
            $check('Sfera', false, Sfera::describe($e));
        }

        $this->out('');
        try {
            $sql = $services->sql();
            $version = (string) $sql->value('SELECT @@VERSION');
            $check('SQL Server (' . $sql->driver() . ')', true, strtok($version, "\n") ?: $version);

            $echo = (string) $sql->value('SELECT CAST(? AS NVARCHAR(100)) AS t', [self::POLISH_SAMPLE]);
            $check('polskie znaki przez SQL', $echo === self::POLISH_SAMPLE, $echo);
        } catch (\Throwable $e) {
            $check('SQL Server', false, Sfera::describe($e));
        }

        $this->out('');
        $this->out($ok ? 'Wszystko w porządku.' : 'Są błędy: popraw je przed uruchomieniem usługi (agent/INSTALACJA.md §6).');

        return $ok ? self::EXIT_OK : self::EXIT_ERROR;
    }

    /**
     * Test nowej wersji przed przełączeniem (agent/update): config.php, rozszerzenia i wczytanie wszystkich klas,
     * bez Sfery i SQL. Błąd składni w dowolnej klasie kończy proces kodem ≠ 0.
     */
    private function selftest(Services $services): int
    {
        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, static fn (string $e): bool => !extension_loaded($e)));
        if ($missing) {
            $this->err('Brak rozszerzeń: ' . implode(', ', $missing));

            return self::EXIT_ERROR;
        }

        $src = ($this->codeDir !== '' ? $this->codeDir : $this->agentDir) . DIRECTORY_SEPARATOR . 'src';
        $count = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $name = $file->getFilename();
            if (!$file->isFile() || $file->getExtension() !== 'php' || in_array($name, ['index.php', 'autoload.php'], true)) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($src) + 1, -4);
            $class = 'InfinitySubiektAgent\\' . str_replace(['/', '\\'], '\\', $relative);
            if (!class_exists($class) && !interface_exists($class) && !trait_exists($class) && !enum_exists($class)) {
                $this->err('Brak klasy ' . $class . ' w ' . $file->getPathname());

                return self::EXIT_ERROR;
            }
            $count++;
        }

        $services->registry()->names();
        $this->out(sprintf('selftest %s: OK (%d klas)', Version::NUMBER, $count));

        return self::EXIT_OK;
    }

    /**
     * Sprawdzenie tabel i kolumn SQL używanych przez agenta.
     */
    private function schema(Services $services): int
    {
        // Pełna wersja.
        $this->err(DemoMethod::message('schema'));

        return self::EXIT_ERROR;
    }

    /**
     * Lokalne wykonanie metody bez sklepu: call <metoda> [plik.json | -].
     *
     * @param list<string> $args
     *
     * @throws AgentException
     */
    private function call(Services $services, array $args): int
    {
        $method = $args[0] ?? '';
        if ($method === '') {
            $this->err('Użycie: php agent.php call <metoda> [plik.json | -]' . "\n" . 'Metody: ' . implode(', ', $services->registry()->names()));

            return self::EXIT_USAGE;
        }

        $params = $this->readParams($args[1] ?? null);
        $services->config->requireSubiekt();

        // Sfera nie lubi równoległej pracy: nie obok działającego agenta
        $lock = $services->lock();
        if (!$lock->acquire()) {
            $pid = $lock->holder();

            throw new AgentException('Agent już działa' . ($pid !== '' ? ' (PID ' . $pid . ')' : '') . '. Zatrzymaj usługę przed użyciem call.');
        }

        try {
            $body = $services->taskRunner()->run($method, $params);
        } finally {
            $lock->release();
        }

        $this->out(Text::json($body, true));

        return isset($body['error']) || isset($body['result']['error']) ? self::EXIT_ERROR : self::EXIT_OK;
    }

    /**
     * Praca z kolejką sklepu: run (pętla) albo once (jeden poll i najwyżej jedno zadanie).
     *
     * @throws AgentException
     */
    private function work(Services $services, bool $once): int
    {
        $services->config->requireQueue();
        $services->config->requireSubiekt();

        // drugi agent obok działającego: zwykła sytuacja przy Harmonogramie zadań co 5 min
        $lock = $services->lock();
        if (!$lock->acquire()) {
            $pid = $lock->holder();
            $services->log->info('Agent już działa' . ($pid !== '' ? ' (PID ' . $pid . ')' : '') . ', ten proces kończy pracę.');

            return self::EXIT_OK;
        }

        $services->stop->install(static function () use ($services): void {
            $services->log->info('Zatrzymywanie: bieżące zadanie zostanie dokończone (ponowne Ctrl+C kończy od razu).');
        });

        try {
            return $services->agent()->run($once);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AgentException
     */
    private function readParams(?string $source): array
    {
        if ($source === null) {
            return [];
        }

        $json = $source === '-' ? stream_get_contents(STDIN) : @file_get_contents($source);
        if ($json === false) {
            throw new AgentException('Nie można odczytać ' . $source);
        }

        $params = json_decode($json, true);
        if (!is_array($params)) {
            throw new AgentException('Parametry muszą być obiektem JSON: ' . json_last_error_msg());
        }

        return $params;
    }

    private function help(): string
    {
        return <<<'TXT'
            Agent Subiekta GT dla modułu infinitysubiekt (wersja demo)

            Użycie: php agent.php <polecenie>

              diag                          sprawdzenie PHP, konfiguracji, Sfery, SQL i polskich znaków
              ping                          metoda ping lokalnie (połączenie ze Sferą i SQL), bez sklepu
              schema                        czy tabele i kolumny SQL agenta istnieją w bazie (pełna wersja)
              call <metoda> [plik.json|-]   lokalne wykonanie metody z parametrami z pliku albo stdin
              run                           praca z kolejką sklepu (dla usługi Windows)
              once                          jeden poll i najwyżej jedno zadanie, do testów
              selftest                      wczytanie wszystkich klas i config.php (test przed aktualizacją)
              help                          ten opis

            Ustawienia: config.php (wzór: config.sample.php). Instalacja: INSTALACJA.md.
            TXT;
    }

    private function interactive(): bool
    {
        return stream_isatty(STDOUT);
    }

    /**
     * Konsola Windows domyślnie w CP852: polskie znaki z UTF-8 byłyby nieczytelne.
     */
    private function consoleUtf8(): void
    {
        if (function_exists('sapi_windows_cp_set') && $this->interactive()) {
            sapi_windows_cp_set(65001);
        }
    }

    private function out(string $text): void
    {
        fwrite(STDOUT, $text . PHP_EOL);
    }

    private function err(string $text): void
    {
        fwrite(STDERR, $text . PHP_EOL);
    }
}
