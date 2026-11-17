<?php
/**
 * Wejście CLI crona. Plik leży w katalogu dostępnym z WWW, więc poza CLI daje 403.
 *
 *   php modules/infinitysubiekt/cron.php [--jobs=maintenance,cleanup,stock_pull,price_pull,price_pull_full] [--dry-run] [--help]
 *
 * Kod wyjścia: 0 OK, 1 przebieg w toku albo błąd zadania, 2 błąd uruchomienia.
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

$options = getopt('', ['jobs:', 'dry-run', 'help']);

if (isset($options['help'])) {
    echo "Użycie: php cron.php [--jobs=nazwa1,nazwa2] [--dry-run]\n"
        . "  --jobs     tylko wskazane zadania (domyślnie wszystkie)\n"
        . "  --dry-run  tylko policz, nic nie zmieniaj i nie zapisuj logu\n";
    exit(0);
}

// rdzeń PS zakłada żądanie HTTP; wartości neutralne zamiast notice
foreach (['HTTP_HOST' => 'localhost', 'REQUEST_URI' => '/', 'REMOTE_ADDR' => '127.0.0.1', 'SERVER_NAME' => 'localhost'] as $key => $value) {
    if (!isset($_SERVER[$key])) {
        $_SERVER[$key] = $value;
    }
}

require __DIR__ . '/../../config/config.inc.php';

if (!Module::isInstalled('infinitysubiekt') || !Module::isEnabled('infinitysubiekt')) {
    fwrite(STDERR, "Moduł infinitysubiekt nie jest zainstalowany albo jest wyłączony.\n");
    exit(2);
}

/** @var InfinitySubiekt|false $module */
$module = Module::getInstanceByName('infinitysubiekt');
if (!$module) {
    fwrite(STDERR, "Nie można załadować modułu infinitysubiekt.\n");
    exit(2);
}

@set_time_limit(0);

$result = $module->createCron()->run(
    InfinitySubiektCron::parseJobs(isset($options['jobs']) ? (string) $options['jobs'] : ''),
    isset($options['dry-run']),
    InfinitySubiektCron::SOURCE_CLI
);

echo implode("\n", $result['lines']) . "\n";

$codes = [
    InfinitySubiektCron::STATUS_OK => 0,
    InfinitySubiektCron::STATUS_FAILED => 1,
    InfinitySubiektCron::STATUS_BUSY => 1,
    InfinitySubiektCron::STATUS_INVALID => 2,
];
exit($codes[$result['status']]);
