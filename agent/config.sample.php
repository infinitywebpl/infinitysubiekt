<?php

/**
 * Wzór ustawień agenta. Skopiuj jako config.php na serwerze Windows i uzupełnij.
 * config.php zawiera hasła: trzymaj go tylko na serwerze z Subiektem.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

return [
    // kolejka w sklepie
    'endpoint' => 'https://sklep.pl/module/infinitysubiekt/agent', // z zakładki „Agent” modułu, bez zmian
    'token' => '',                 // „Token agenta” z zakładki „Ustawienia” modułu
    'poll_wait' => 20,             // s, maks. 20 (moduł i tak przycina)
    'http_timeout' => 35,          // s, co najmniej poll_wait + 15
    'ca_file' => null,             // ścieżka do cacert.pem, gdy nie ma curl.cainfo w php.ini

    // Sfera (InsERT GT)
    'subiekt' => [
        'server' => 'HOST\\INSERTGT',
        'database' => '',
        'authentication' => 0,     // 0 = SQL Server (mieszana), 1 = Windows
        'sql_user' => 'sa',
        'sql_password' => '',
        'operator' => 'Szef',
        'operator_password' => '',
        'codepage' => 'utf8',      // utf8 albo cp1250, gdy polskie znaki w Subiekcie są zepsute
    ],

    // odczyty SQL z bazy podmiotu (stany, wyszukiwanie kontrahentów); dane logowania jak w 'subiekt'
    'sql' => [
        'driver' => 'auto',        // auto (pdo_sqlsrv, a gdy go nie ma ADODB), pdo albo adodb
        'adodb_provider' => 'MSOLEDBSQL',
        'trust_server_certificate' => true, // SQL Server z certyfikatem self-signed (domyślne w instalacjach InsERT)
    ],

    // dokumenty
    'issuer' => '',                // wystawca, gdy zadanie go nie podaje
    'fiscal_printer_id' => 0,      // uko_Id z uf_Konfiguracja dla paragonów; 0 = paragony wyłączone
    'retail_price_level' => 1,     // poziom cen Subiekta z ceną detaliczną brutto (product/save), zwykle 1
    'duplicate_days' => 7,         // ochrona przed duplikatem dokumentu

    // proces
    'log_dir' => __DIR__ . '/logs',
    'log_days' => 30,
    'max_runtime_hours' => 12,     // przy Harmonogramie zadań np. 1 (INSTALACJA.md §5)
    'max_memory_mb' => 256,

    // zdalna aktualizacja z modułu: tylko paczki podpisane kluczem
    // z release-key.txt; ze sklepu nie da się tego włączyć. Wymaga extension=sodium w php.ini.
    'update_enabled' => false,
];
