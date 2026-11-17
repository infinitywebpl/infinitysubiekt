<?php

declare(strict_types=1);

/**
 * Podpis wydania agenta do zdalnej aktualizacji. Tylko na komputerze autora:
 *
 *   php -d extension=sodium agent/tools/release.php keygen C:\klucze\infinitysubiekt-agent.key
 *       raz: para kluczy Ed25519; prywatny do podanego pliku (poza modułem), publiczny do agent/release-key.txt
 *
 *   php -d extension=sodium agent/tools/release.php sign C:\klucze\infinitysubiekt-agent.key
 *       po każdej zmianie agenta (i podbiciu src/Version.php): agent/release.json z sumami SHA-256 plików i podpisem
 *
 * Klucz prywatny nigdy nie trafia na sklep ani na FTP. Kto go ma, może wgrać dowolny kod na serwer z Subiektem.
 * Lista plików wydania musi być zgodna z InfinitySubiektAgentRelease::files() w module.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

const AGENT_DIR = __DIR__ . '/..';

// poza wydaniem: hasła, logi, narzędzia autora, pliki aktualizacji i launcher (aktualizowany tylko ręcznie)
const EXCLUDED_FILES = ['config.php', 'release.json', 'release-key.txt', 'agent.php'];
const EXCLUDED_DIRS = ['logs', 'tools', 'releases'];
const EXTENSIONS = ['php', 'md', 'json', 'txt'];

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

/**
 * @return array<string, string> ścieżka względna => SHA-256, posortowane
 */
function releaseFiles(): array
{
    $root = realpath(AGENT_DIR);
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $first = explode('/', $relative)[0];
        if (in_array($relative, EXCLUDED_FILES, true) || in_array($first, EXCLUDED_DIRS, true)
            || !in_array(strtolower($file->getExtension()), EXTENSIONS, true) || str_starts_with($file->getFilename(), '.')
        ) {
            continue;
        }
        $files[$relative] = hash_file('sha256', $file->getPathname());
    }
    ksort($files);

    return $files;
}

function version(): string
{
    $source = (string) file_get_contents(AGENT_DIR . '/src/Version.php');
    if (!preg_match("/NUMBER\\s*=\\s*'(\\d+(?:\\.\\d+)*)'/", $source, $m)) {
        fail('Nie można odczytać wersji z src/Version.php');
    }

    return $m[1];
}

function keyPath(?string $path, bool $mustExist): string
{
    if ($path === null || $path === '') {
        fail('Podaj ścieżkę pliku klucza prywatnego (poza katalogiem modułu).');
    }
    $module = realpath(AGENT_DIR . '/..');
    $dir = realpath(dirname($path));
    if ($dir === false) {
        fail('Katalog klucza nie istnieje: ' . dirname($path));
    }
    if (str_starts_with(strtolower($dir . DIRECTORY_SEPARATOR), strtolower($module . DIRECTORY_SEPARATOR))) {
        fail('Klucz prywatny nie może leżeć w katalogu modułu (trafiłby na FTP): ' . $path);
    }
    if ($mustExist && !is_file($path)) {
        fail('Brak pliku klucza: ' . $path . '. Najpierw: keygen');
    }

    return $path;
}

if (!extension_loaded('sodium')) {
    fail('Brak rozszerzenia sodium. Uruchom: php -d extension=sodium ' . implode(' ', $argv));
}

$command = $argv[1] ?? '';
switch ($command) {
    case 'keygen':
        $path = keyPath($argv[2] ?? null, false);
        if (is_file($path)) {
            fail('Plik klucza już istnieje: ' . $path . '. Nowy klucz wymaga ręcznej podmiany release-key.txt na serwerze agenta.');
        }
        $pair = sodium_crypto_sign_keypair();
        file_put_contents($path, base64_encode($pair));
        file_put_contents(AGENT_DIR . '/release-key.txt', base64_encode(sodium_crypto_sign_publickey($pair)) . PHP_EOL);
        echo "Klucz prywatny: {$path} (zrób kopię zapasową, nie wgrywaj na sklep)" . PHP_EOL;
        echo 'Klucz publiczny: agent/release-key.txt (wgraj ręcznie na serwer agenta, do katalogu głównego)' . PHP_EOL;
        exit(0);

    case 'sign':
        $pair = base64_decode(trim((string) file_get_contents(keyPath($argv[2] ?? null, true))), true);
        if ($pair === false || strlen($pair) !== SODIUM_CRYPTO_SIGN_KEYPAIRBYTES) {
            fail('Niepoprawny plik klucza prywatnego.');
        }
        $public = trim((string) @file_get_contents(AGENT_DIR . '/release-key.txt'));
        if ($public !== base64_encode(sodium_crypto_sign_publickey($pair))) {
            fail('agent/release-key.txt nie pasuje do tego klucza prywatnego.');
        }

        $version = version();
        $files = releaseFiles();
        $manifest = json_encode(
            ['version' => $version, 'created_at' => date('Y-m-d H:i:s'), 'files' => $files],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
        $signature = sodium_crypto_sign_detached($manifest, sodium_crypto_sign_secretkey($pair));

        file_put_contents(AGENT_DIR . '/release.json', json_encode([
            'version' => $version,
            'manifest' => base64_encode($manifest),
            'signature' => base64_encode($signature),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);

        echo sprintf('agent/release.json: wersja %s, %d plików. Wgraj katalog agent/ na FTP.', $version, count($files)) . PHP_EOL;
        echo 'Pamiętaj: agent przyjmuje tylko wersję wyższą niż działająca (src/Version.php).' . PHP_EOL;
        exit(0);

    default:
        fail("Użycie:\n  php -d extension=sodium agent/tools/release.php keygen <plik-klucza>\n  php -d extension=sodium agent/tools/release.php sign <plik-klucza>");
}
