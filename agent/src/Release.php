<?php

declare(strict_types=1);

namespace InfinitySubiektAgent;

if (PHP_SAPI !== 'cli') {
    exit;
}

/**
 * Wersje agenta w releases\. Pliki stanu czyta też launcher agent.php,
 * więc ich format jest stały: current.txt i previous.txt = wersja, pending.txt = „wersja liczba_startów”.
 */
final readonly class Release
{
    public const DIR = 'releases';
    public const CURRENT = 'current.txt';
    public const PREVIOUS = 'previous.txt';
    public const PENDING = 'pending.txt';
    public const FAILED = 'update-failed.txt';

    // klucz publiczny Ed25519 (base64) w katalogu głównym agenta; aktualizacja go nie podmienia
    public const KEY_FILE = 'release-key.txt';

    /**
     * @param string $root katalog agenta (config.php, logs\, releases\)
     * @param string $codeDir katalog, z którego działa kod (root albo releases\<wersja>)
     */
    public function __construct(
        private string $root,
        private string $codeDir,
    ) {
    }

    public static function validVersion(string $version): bool
    {
        return preg_match('/^\d+(\.\d+)*$/', $version) === 1;
    }

    public function dir(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . self::DIR;
    }

    public function path(string $version): string
    {
        return $this->dir() . DIRECTORY_SEPARATOR . $version;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function current(): string
    {
        return $this->read(self::CURRENT);
    }

    public function previous(): string
    {
        return $this->read(self::PREVIOUS);
    }

    public function pending(): string
    {
        return explode(' ', $this->read(self::PENDING))[0];
    }

    /**
     * Czy kod działa z katalogu wydania (a nie z src\ obok launchera).
     */
    public function fromRelease(): bool
    {
        return dirname($this->codeDir) === $this->dir();
    }

    /**
     * Stan do wyniku ping i agent/update.
     *
     * @return array<string, string|bool>
     */
    public function info(): array
    {
        return [
            'running' => Version::NUMBER,
            'from_release' => $this->fromRelease(),
            'current' => $this->current(),
            'previous' => $this->previous(),
            'pending' => $this->pending(),
            'failed' => $this->read(self::FAILED),
        ];
    }

    /**
     * Klucz publiczny do sprawdzenia podpisu paczki.
     *
     * @throws AgentException
     */
    public function publicKey(): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . self::KEY_FILE;
        $key = is_file($path) ? base64_decode(trim((string) file_get_contents($path)), true) : false;
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new AgentException('brak klucza publicznego ' . self::KEY_FILE . ' w katalogu agenta (wgraj go ręcznie z paczki ZIP)');
        }

        return $key;
    }

    /**
     * Wersja na próbę: launcher uruchomi ją przy następnym starcie.
     */
    public function setPending(string $version): void
    {
        $this->write(self::PENDING, $version . ' 0');
    }

    /**
     * Powrót do kodu z src\ obok launchera (gdy wcześniej nie było żadnego wydania).
     */
    public function useRoot(): void
    {
        @unlink($this->dir() . DIRECTORY_SEPARATOR . self::CURRENT);
        @unlink($this->dir() . DIRECTORY_SEPARATOR . self::PENDING);
    }

    /**
     * Pierwszy udany poll wersji na próbie: zostaje bieżącą, poprzednia zostaje na powrót, starsze są usuwane.
     */
    public function confirm(Log $log): void
    {
        $pending = $this->pending();
        if ($pending === '' || $pending !== Version::NUMBER || $this->codeDir !== $this->path($pending)) {
            return;
        }

        $current = $this->current();
        $this->write(self::PREVIOUS, $current !== $pending ? $current : $this->previous());
        $this->write(self::CURRENT, $pending);
        @unlink($this->dir() . DIRECTORY_SEPARATOR . self::PENDING);
        @unlink($this->dir() . DIRECTORY_SEPARATOR . self::FAILED);
        $log->info('Aktualizacja: wersja ' . $pending . ' potwierdzona');

        $this->cleanup([$pending, $this->previous()]);
    }

    /**
     * Usuwa katalogi wydań poza zachowanymi (i porzucone *.tmp).
     *
     * @param list<string> $keep
     */
    private function cleanup(array $keep): void
    {
        foreach ((array) glob($this->dir() . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $dir) {
            if (!in_array(basename((string) $dir), $keep, true)) {
                self::removeDir((string) $dir);
            }
        }
    }

    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    private function read(string $name): string
    {
        $path = $this->dir() . DIRECTORY_SEPARATOR . $name;

        return is_file($path) ? trim((string) file_get_contents($path)) : '';
    }

    private function write(string $name, string $value): void
    {
        if (!is_dir($this->dir())) {
            @mkdir($this->dir(), 0775, true);
        }
        file_put_contents($this->dir() . DIRECTORY_SEPARATOR . $name, $value);
    }
}
