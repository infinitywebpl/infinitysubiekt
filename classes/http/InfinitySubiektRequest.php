<?php
/**
 * Odczyt żądania HTTP dla endpointów bez kontekstu PrestaShop (agent, cron).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektRequest
{
    /** @var string|null */
    private $rawBody;

    /**
     * @return string
     */
    public function method()
    {
        return isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
    }

    /**
     * Parametr z query stringa (nie z POST).
     *
     * @param string $name
     * @param mixed $default
     *
     * @return mixed
     */
    public function query($name, $default = null)
    {
        return isset($_GET[$name]) && is_scalar($_GET[$name]) ? (string) $_GET[$name] : $default;
    }

    /**
     * Nagłówek bez rozróżniania wielkości liter. FastCGI/CGI potrafi go przekazać
     * tylko jako REDIRECT_HTTP_* albo wcale (wtedy getallheaders()).
     *
     * @param string $name
     *
     * @return string|null
     */
    public function header($name)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        foreach ([$key, 'REDIRECT_' . $key] as $serverKey) {
            if (isset($_SERVER[$serverKey]) && $_SERVER[$serverKey] !== '') {
                return (string) $_SERVER[$serverKey];
            }
        }

        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $header => $value) {
                if (strcasecmp($header, $name) === 0) {
                    return (string) $value;
                }
            }
        }

        return null;
    }

    /**
     * @return string
     */
    public function rawBody()
    {
        if ($this->rawBody === null) {
            $body = file_get_contents('php://input');
            $this->rawBody = $body === false ? '' : $body;
        }

        return $this->rawBody;
    }

    /**
     * @return array|null null, gdy body nie jest obiektem JSON
     */
    public function jsonBody()
    {
        return InfinitySubiektJson::decode($this->rawBody());
    }
}
