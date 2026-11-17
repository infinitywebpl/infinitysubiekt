<?php
/**
 * Paczka ZIP z katalogiem agent/ do pobrania z zakładki „Agent”.
 * Budowana od nowa przy każdym pobraniu, więc zawiera to, co jest teraz na dysku sklepu.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektAgentPackage
{
    const ROOT = 'infinitysubiekt-agent';
    const VERSION_FILE = 'src/Version.php';

    /** @var string */
    private $agentDir;

    /**
     * @param string $agentDir katalog agent/ modułu
     */
    public function __construct($agentDir)
    {
        $this->agentDir = rtrim(str_replace('\\', '/', $agentDir), '/');
    }

    /**
     * Wersja i data najnowszego pliku: po nich widać, czy na sklepie jest aktualny agent.
     *
     * @return array ['version' => string, 'updated_at' => string]
     */
    public function info()
    {
        $newest = 0;
        foreach ($this->files() as $path) {
            $newest = max($newest, (int) filemtime($path));
        }

        return [
            'version' => $this->version(),
            'updated_at' => $newest ? date('Y-m-d H:i:s', $newest) : '',
        ];
    }

    /**
     * @return string nazwa pliku z wersją i datą najnowszego pliku
     */
    public function filename()
    {
        $info = $this->info();
        $stamp = $info['updated_at'] !== '' ? date('Ymd-His', (int) strtotime($info['updated_at'])) : date('Ymd-His');

        return self::ROOT . ($info['version'] !== '' ? '-' . $info['version'] : '') . '-' . $stamp . '.zip';
    }

    /**
     * @return string ścieżka tymczasowego pliku ZIP (do usunięcia przez wołającego)
     *
     * @throws InfinitySubiektException
     */
    public function build()
    {
        if (!class_exists('ZipArchive')) {
            throw new InfinitySubiektException('serwer nie ma rozszerzenia PHP zip');
        }

        $files = $this->files();
        if (!$files) {
            throw new InfinitySubiektException('brak katalogu agent/ w module, wgraj go na serwer sklepu');
        }

        $path = tempnam(_PS_CACHE_DIR_, 'infsubiekt');
        if ($path === false) {
            throw new InfinitySubiektException('nie można utworzyć pliku tymczasowego w katalogu cache');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            @unlink($path);

            throw new InfinitySubiektException('nie można utworzyć pliku ZIP');
        }

        foreach ($files as $relative => $file) {
            $zip->addFile($file, self::ROOT . '/' . $relative);
        }
        if (!$zip->close()) {
            @unlink($path);

            throw new InfinitySubiektException('nie można zapisać pliku ZIP');
        }

        return $path;
    }

    /**
     * Pliki agenta bez config.php (hasła) i logów, żeby rozpakowanie nie nadpisało niczego na serwerze Windows.
     *
     * @return array ścieżka względna => ścieżka na dysku, posortowane
     */
    private function files()
    {
        if (!is_dir($this->agentDir)) {
            return [];
        }

        // wynik filemtime() nie może pochodzić sprzed wgrania nowych plików w tym samym procesie PHP
        clearstatcache();

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->agentDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $relative = substr($path, strlen($this->agentDir) + 1);
            // tools/ to narzędzie autora (podpis wydań), releases/ i release.json dotyczą tylko zdalnej aktualizacji
            if ($relative === 'config.php' || $relative === 'release.json'
                || (strpos($relative, 'logs/') === 0 && $relative !== 'logs/index.php')
                || strpos($relative, 'tools/') === 0 || strpos($relative, 'releases/') === 0
            ) {
                continue;
            }

            $files[$relative] = $path;
        }
        ksort($files);

        return $files;
    }

    /**
     * @return string pusty, gdy nie da się odczytać
     */
    private function version()
    {
        $path = $this->agentDir . '/' . self::VERSION_FILE;
        $source = is_file($path) ? (string) file_get_contents($path) : '';

        return preg_match("/NUMBER\\s*=\\s*'([^']+)'/", $source, $match) ? $match[1] : '';
    }
}
