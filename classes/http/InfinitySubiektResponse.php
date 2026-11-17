<?php
/**
 * Wysłanie odpowiedzi i zakończenie żądania (exit).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektResponse
{
    /**
     * @param int $code
     * @param mixed $data
     */
    public function json($code, $data)
    {
        $this->start($code, 'application/json; charset=utf-8');
        echo InfinitySubiektJson::encode($data);
        exit;
    }

    public function noContent()
    {
        $this->start(204, null);
        exit;
    }

    /**
     * @param int $code
     * @param string $body
     */
    public function text($code, $body)
    {
        $this->start($code, 'text/plain; charset=utf-8');
        echo $body;
        exit;
    }

    /**
     * Plik do wyświetlenia w przeglądarce (inline) albo pobrania (attachment).
     *
     * @param string $path
     * @param string $filename nazwa pliku bez cudzysłowów i ukośników
     * @param string $contentType
     * @param string $disposition
     */
    public function file($path, $filename, $contentType, $disposition = 'inline')
    {
        $this->start(200, $contentType);
        header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    /**
     * @param int $code
     * @param string|null $contentType
     */
    private function start($code, $contentType)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code((int) $code);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        if ($contentType !== null) {
            header('Content-Type: ' . $contentType);
        }
    }
}
