<?php

declare(strict_types=1);

/**
 * Agent Subiekta GT dla modułu infinitysubiekt. Tylko z linii komend na serwerze Windows:
 *   php agent.php help
 * Plan: docs/plan-agenta.md, instalacja: INSTALACJA.md, aktualizacja: docs/plan-aktualizacji-agenta.md.
 *
 * Launcher: wybiera katalog kodu i uruchamia z niego agenta. Zdalna aktualizacja go NIE podmienia
 * (zapisuje tylko releases\<wersja>\), więc zmiana tego pliku wymaga ręcznego wgrania.
 *   releases\pending.txt  „wersja próby”: wersja na próbie, najwyżej MAX_ATTEMPTS startów run/once
 *   releases\current.txt  wersja potwierdzona pierwszym udanym pollem
 *   brak obu              kod z src\ obok tego pliku (układ z paczki ZIP)
 * Opcja --release=<katalog w releases> wymusza katalog (test nowej wersji przed przełączeniem).
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

if (PHP_VERSION_ID < 80400) {
    fwrite(STDERR, 'Agent wymaga PHP 8.4 (x86), uruchomiono ' . PHP_VERSION . PHP_EOL);
    exit(2);
}

/**
 * @param list<string> $argv
 */
function infinitysubiekt_code_dir(string $root, array $argv): string
{
    $maxAttempts = 3;
    $releases = $root . DIRECTORY_SEPARATOR . 'releases';

    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--release=')) {
            $dir = realpath(substr($arg, 10));
            $base = realpath($releases);
            if ($dir === false || $base === false || !str_starts_with($dir, $base . DIRECTORY_SEPARATOR) || !is_file($dir . '/src/autoload.php')) {
                fwrite(STDERR, 'Niepoprawny katalog --release' . PHP_EOL);
                exit(2);
            }

            return $dir;
        }
    }

    $read = static function (string $name) use ($releases): string {
        $path = $releases . DIRECTORY_SEPARATOR . $name;

        return is_file($path) ? trim((string) file_get_contents($path)) : '';
    };
    $valid = static function (string $version) use ($releases): bool {
        return preg_match('/^\d+(\.\d+)*$/', $version) === 1 && is_file($releases . DIRECTORY_SEPARATOR . $version . '/src/autoload.php');
    };

    $pending = $read('pending.txt');
    if ($pending !== '') {
        [$version, $attempts] = explode(' ', $pending . ' 0');
        $attempts = (int) $attempts;
        if ($valid($version) && $attempts < $maxAttempts) {
            // liczą się tylko starty pracy z kolejką: diag czy call nie zużywają prób
            if (in_array($argv[1] ?? '', ['run', 'once'], true)) {
                file_put_contents($releases . DIRECTORY_SEPARATOR . 'pending.txt', $version . ' ' . ($attempts + 1));
            }

            return $releases . DIRECTORY_SEPARATOR . $version;
        }

        // nowa wersja nie przeszła pierwszego pollu w kolejnych startach: powrót do poprzedniej
        @file_put_contents(
            $releases . DIRECTORY_SEPARATOR . 'update-failed.txt',
            sprintf('%s: wersja %s nie wystartowała (%d prób), powrót do poprzedniej', date('Y-m-d H:i:s'), $version, $attempts)
        );
        @unlink($releases . DIRECTORY_SEPARATOR . 'pending.txt');
    }

    $current = $read('current.txt');

    return $valid($current) ? $releases . DIRECTORY_SEPARATOR . $current : $root;
}

$codeDir = infinitysubiekt_code_dir(__DIR__, $argv);
require $codeDir . '/src/autoload.php';

$args = array_values(array_filter($argv, static fn (string $arg): bool => !str_starts_with($arg, '--release=')));

exit((new InfinitySubiektAgent\Cli(__DIR__, $codeDir))->run($args));
