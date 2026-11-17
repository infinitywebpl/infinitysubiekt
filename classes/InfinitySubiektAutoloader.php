<?php
/**
 * Autoloader klas modułu (PS 1.6 nie ma autoloadu modułów ani namespace'ów).
 * Ładuje klasy z prefiksem InfinitySubiekt z katalogu classes/ i jego podkatalogów;
 * nazwa pliku = nazwa klasy.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektAutoloader
{
    const PREFIX = 'InfinitySubiekt';

    /** @var string|null */
    private static $dir;

    /** @var array|null nazwa klasy => ścieżka pliku */
    private static $map;

    /**
     * @param string $dir katalog classes/
     */
    public static function register($dir)
    {
        if (self::$dir !== null) {
            return;
        }

        self::$dir = rtrim($dir, '/\\');
        spl_autoload_register([__CLASS__, 'load']);
    }

    /**
     * @param string $class
     */
    public static function load($class)
    {
        if (strpos($class, self::PREFIX) !== 0) {
            return;
        }

        if (self::$map === null) {
            self::$map = self::buildMap(self::$dir);
        }

        if (isset(self::$map[$class])) {
            require_once self::$map[$class];
        }
    }

    /**
     * @param string $dir
     *
     * @return array
     */
    private static function buildMap($dir)
    {
        $map = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            $name = $file->getBasename('.php');
            if ($file->getExtension() === 'php' && strpos($name, self::PREFIX) === 0) {
                $map[$name] = $file->getPathname();
            }
        }

        return $map;
    }
}
