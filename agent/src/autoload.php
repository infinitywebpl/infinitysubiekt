<?php

declare(strict_types=1);

/**
 * Autoloader PSR-4: InfinitySubiektAgent\Foo\Bar → src/Foo/Bar.php (bez Composera).
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'InfinitySubiektAgent\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
