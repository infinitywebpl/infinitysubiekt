<?php
/**
 * Wydanie agenta do zdalnej aktualizacji: agent/release.json podpisany
 * przez agent/tools/release.php na komputerze autora. Moduł podpisu nie sprawdza (nie ma klucza i nie musi),
 * tylko podaje manifest i pliki z listy; podpis i sumy sprawdza agent.
 *
 * Lista plików wydania musi być zgodna z agent/tools/release.php.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektAgentRelease
{
    const FILE = 'release.json';

    const EXCLUDED_FILES = ['config.php', 'release.json', 'release-key.txt', 'agent.php'];
    const EXCLUDED_DIRS = ['logs', 'tools', 'releases'];
    const EXTENSIONS = ['php', 'md', 'json', 'txt'];

    /** @var string */
    private $agentDir;

    /** @var array|null|false manifest z release.json (false = jeszcze nie czytany) */
    private $manifest = false;

    /**
     * @param string $agentDir katalog agent/ modułu
     */
    public function __construct($agentDir)
    {
        $this->agentDir = rtrim(str_replace('\\', '/', $agentDir), '/');
    }

    /**
     * Treść release.json dla op=release.
     *
     * @return string|null null = brak pliku
     */
    public function raw()
    {
        $path = $this->agentDir . '/' . self::FILE;

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /**
     * Ścieżka pliku dla op=file: tylko plik z manifestu (bez podglądu innych plików modułu).
     *
     * @param string $path ścieżka względna z manifestu
     *
     * @return string|null
     */
    public function filePath($path)
    {
        $manifest = $this->manifest();
        if ($manifest === null || !isset($manifest['files'][$path]) || strpos($path, '..') !== false) {
            return null;
        }

        $file = $this->agentDir . '/' . $path;

        return is_file($file) ? $file : null;
    }

    /**
     * Stan do zakładki „Agent”.
     *
     * @return array ['exists' => bool, 'version' => string, 'created_at' => string, 'changed' => string[]]
     *               changed: pliki inne niż w manifeście (release.json nieaktualny, agent odrzuci pobranie)
     */
    public function status()
    {
        $manifest = $this->manifest();
        if ($manifest === null) {
            return ['exists' => false, 'version' => '', 'created_at' => '', 'changed' => []];
        }

        $disk = $this->files();
        $changed = [];
        foreach ($manifest['files'] as $path => $hash) {
            if (!isset($disk[$path]) || $disk[$path] !== $hash) {
                $changed[] = $path;
            }
        }
        foreach (array_keys($disk) as $path) {
            if (!isset($manifest['files'][$path])) {
                $changed[] = $path;
            }
        }
        sort($changed);

        return [
            'exists' => true,
            'version' => (string) $manifest['version'],
            'created_at' => (string) $manifest['created_at'],
            'changed' => $changed,
        ];
    }

    /**
     * Manifest z release.json bez sprawdzania podpisu (sprawdza agent).
     *
     * @return array|null ['version' => …, 'created_at' => …, 'files' => [ścieżka => sha256]]
     */
    private function manifest()
    {
        if ($this->manifest !== false) {
            return $this->manifest;
        }

        $this->manifest = null;
        $data = json_decode((string) $this->raw(), true);
        $raw = is_array($data) && isset($data['manifest']) ? base64_decode((string) $data['manifest'], true) : false;
        $manifest = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($manifest) && isset($manifest['version'], $manifest['files']) && is_array($manifest['files'])) {
            $this->manifest = [
                'version' => (string) $manifest['version'],
                'created_at' => isset($manifest['created_at']) ? (string) $manifest['created_at'] : '',
                'files' => $manifest['files'],
            ];
        }

        return $this->manifest;
    }

    /**
     * Pliki wydania na dysku sklepu z sumami SHA-256 (te same reguły co agent/tools/release.php).
     *
     * @return array ścieżka względna => sha256
     */
    private function files()
    {
        if (!is_dir($this->agentDir)) {
            return [];
        }

        clearstatcache();
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->agentDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($this->agentDir) + 1);
            $first = explode('/', $relative)[0];
            if (in_array($relative, self::EXCLUDED_FILES, true) || in_array($first, self::EXCLUDED_DIRS, true)
                || !in_array(Tools::strtolower($file->getExtension()), self::EXTENSIONS, true)
                || strpos($file->getFilename(), '.') === 0
            ) {
                continue;
            }
            $files[$relative] = hash_file('sha256', $file->getPathname());
        }

        return $files;
    }
}
