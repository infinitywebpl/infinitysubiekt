<?php

declare(strict_types=1);

namespace InfinitySubiektAgent\Method;

if (PHP_SAPI !== 'cli') {
    exit;
}

use InfinitySubiektAgent\AgentException;
use InfinitySubiektAgent\Config;
use InfinitySubiektAgent\Http\QueueClient;
use InfinitySubiektAgent\Http\QueueException;
use InfinitySubiektAgent\Log;
use InfinitySubiektAgent\Release;
use InfinitySubiektAgent\Restart;
use InfinitySubiektAgent\Version;

/**
 * agent/update: podpisany manifest ze sklepu → pliki do
 * releases\<wersja>.tmp z kontrolą SHA-256 → test w osobnym procesie → releases\<wersja> i pending.txt → restart.
 *
 * Sklep jest tylko kurierem: bez ważnego podpisu kluczem z release-key.txt nic nie trafia na dysk jako kod.
 * Żaden błąd nie zmienia działającej wersji.
 */
final readonly class AgentUpdate implements Method
{
    // rozszerzenia plików, które mogą przyjść w paczce
    private const EXTENSIONS = ['php', 'md', 'json', 'txt'];

    // pliki, bez których wydanie nie wystartuje
    private const REQUIRED = ['src/autoload.php', 'src/Version.php', 'src/Cli.php'];

    private const SELFTEST_TIMEOUT = 60;

    public function __construct(
        private Config $config,
        private Release $release,
        private QueueClient $queue,
        private Restart $restart,
        private Log $log,
    ) {
    }

    public function execute(array $params): array
    {
        if (!$this->config->updateEnabled) {
            throw new AgentException('zdalna aktualizacja jest wyłączona: ustaw update_enabled => true w config.php agenta');
        }
        if (!extension_loaded('sodium')) {
            throw new AgentException('brak rozszerzenia sodium: włącz extension=sodium w php.ini serwera agenta');
        }

        $manifest = $this->manifest($this->release->publicKey());
        $version = $manifest['version'];

        $compare = version_compare($version, Version::NUMBER);
        if ($compare === 0) {
            return ['status' => 'current', 'version' => $version, 'release' => $this->release->info()];
        }
        if ($compare < 0) {
            throw new AgentException(sprintf('na sklepie jest wersja %s, starsza niż działająca %s', $version, Version::NUMBER));
        }

        $target = $this->release->path($version);
        $tmp = $target . '.tmp';
        Release::removeDir($tmp);

        try {
            $this->log->info(sprintf('agent/update: pobieranie %s (%d plików)', $version, count($manifest['files'])));
            $this->download($manifest['files'], $tmp);
            $this->checkVersionFile($tmp, $version);
            $this->selftest($tmp);

            Release::removeDir($target);
            if (!@rename($tmp, $target)) {
                throw new AgentException('nie można przenieść ' . $tmp . ' do ' . $target);
            }
        } finally {
            Release::removeDir($tmp);
        }

        $this->release->setPending($version);
        $this->restart->request('aktualizacja do ' . $version);
        $this->log->info(sprintf('agent/update: wersja %s gotowa, restart po wysłaniu wyniku', $version));

        return ['status' => 'installed', 'version' => $version, 'files' => count($manifest['files'])];
    }

    /**
     * op=release → {"manifest": base64, "signature": base64}; podpis Ed25519 nad zdekodowanym manifestem.
     *
     * @return array{version: string, files: array<string, string>}
     *
     * @throws AgentException
     */
    private function manifest(string $publicKey): array
    {
        $response = $this->get(['op' => 'release']);
        $data = $response->json();
        $raw = is_array($data) && is_string($data['manifest'] ?? null) ? base64_decode($data['manifest'], true) : false;
        $signature = is_array($data) && is_string($data['signature'] ?? null) ? base64_decode($data['signature'], true) : false;
        if ($raw === false || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new AgentException('niepoprawny release.json na sklepie');
        }
        if (!sodium_crypto_sign_verify_detached($signature, $raw, $publicKey)) {
            throw new AgentException('podpis release.json nie pasuje do klucza agenta: paczka nie pochodzi od autora albo była zmieniana');
        }

        $manifest = json_decode($raw, true);
        $version = is_array($manifest) ? (string) ($manifest['version'] ?? '') : '';
        $files = is_array($manifest) && is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
        if (!Release::validVersion($version) || !$files) {
            throw new AgentException('manifest bez wersji albo plików');
        }

        foreach ($files as $path => $hash) {
            $this->checkPath((string) $path);
            if (!is_string($hash) || !preg_match('/^[0-9a-f]{64}$/', $hash)) {
                throw new AgentException('manifest: zła suma pliku ' . $path);
            }
        }
        foreach (self::REQUIRED as $path) {
            if (!isset($files[$path])) {
                throw new AgentException('manifest bez pliku ' . $path);
            }
        }

        return ['version' => $version, 'files' => $files];
    }

    /**
     * Ścieżka względna bez wyjścia poza katalog wydania, bez config.php i logów.
     *
     * @throws AgentException
     */
    private function checkPath(string $path): void
    {
        $segments = explode('/', $path);
        $ok = $path !== '' && $path !== 'config.php' && $segments[0] !== 'logs'
            && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
        foreach ($segments as $segment) {
            $ok = $ok && preg_match('/^[A-Za-z0-9_-][A-Za-z0-9_.-]*$/', $segment) === 1 && !str_contains($segment, '..');
        }
        if (!$ok) {
            throw new AgentException('manifest: niedozwolona ścieżka ' . $path);
        }
    }

    /**
     * @param array<string, string> $files
     *
     * @throws AgentException
     */
    private function download(array $files, string $dir): void
    {
        foreach ($files as $path => $hash) {
            $body = $this->get(['op' => 'file', 'path' => $path])->body;
            if (!hash_equals($hash, hash('sha256', $body))) {
                throw new AgentException('plik ' . $path . ' ma inną sumę niż w podpisanym manifeście');
            }

            $file = $dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0775, true) && !is_dir(dirname($file))) {
                throw new AgentException('nie można utworzyć katalogu ' . dirname($file));
            }
            if (@file_put_contents($file, $body) !== strlen($body)) {
                throw new AgentException('nie można zapisać ' . $file);
            }
        }
    }

    /**
     * Wersja w kodzie musi być wersją z manifestu: po niej pierwszy poll potwierdza wydanie.
     *
     * @throws AgentException
     */
    private function checkVersionFile(string $dir, string $version): void
    {
        $source = (string) @file_get_contents($dir . '/src/Version.php');
        if (!preg_match("/NUMBER\\s*=\\s*'([^']+)'/", $source, $m) || $m[1] !== $version) {
            throw new AgentException('src/Version.php w paczce nie ma wersji ' . $version);
        }
    }

    /**
     * Nowy kod w osobnym procesie: wczytanie wszystkich klas, config.php, rozszerzenia (Cli selftest).
     *
     * @throws AgentException
     */
    private function selftest(string $dir): void
    {
        // wyjście do pliku: na Windows potoki proc_open nie mają trybu nieblokującego, a test ma limit czasu
        $outFile = $this->config->logDir . DIRECTORY_SEPARATOR . 'selftest.log';
        $command = [PHP_BINARY, $this->release->root() . DIRECTORY_SEPARATOR . 'agent.php', 'selftest', '--release=' . $dir];
        $process = proc_open(
            $command,
            [0 => ['file', 'NUL', 'r'], 1 => ['file', $outFile, 'w'], 2 => ['file', $outFile, 'a']],
            $pipes,
            $this->release->root(),
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new AgentException('nie można uruchomić testu nowej wersji');
        }

        $deadline = time() + self::SELFTEST_TIMEOUT;
        do {
            $status = proc_get_status($process);
            if ($status['running']) {
                usleep(200000);
            }
        } while ($status['running'] && time() < $deadline);

        if ($status['running']) {
            proc_terminate($process);
            proc_close($process);

            throw new AgentException('test nowej wersji przekroczył ' . self::SELFTEST_TIMEOUT . ' s');
        }
        proc_close($process);
        $output = (string) @file_get_contents($outFile);

        if ($status['exitcode'] !== 0) {
            throw new AgentException('test nowej wersji nie przeszedł: ' . mb_substr(trim($output), 0, 500));
        }
    }

    /**
     * @param array<string, scalar> $query
     *
     * @throws AgentException
     */
    private function get(array $query): \InfinitySubiektAgent\Http\Response
    {
        try {
            $response = $this->queue->download($query);
        } catch (QueueException $e) {
            throw new AgentException('pobieranie ' . $query['op'] . ': ' . $e->getMessage(), 0, $e);
        }
        if ($response->status !== 200) {
            throw new AgentException(sprintf(
                'pobieranie %s%s: HTTP %d %s',
                $query['op'],
                isset($query['path']) ? ' ' . $query['path'] : '',
                $response->status,
                $response->excerpt()
            ));
        }

        return $response;
    }
}
