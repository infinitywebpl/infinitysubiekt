<?php
/**
 * Sprawdzenie tokenu z konfiguracji (agent, cron). Pusty token w konfiguracji = dostęp wyłączony.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektTokenAuth
{
    /** @var InfinitySubiektConfig */
    private $config;

    /** @var string */
    private $key;

    /**
     * @param InfinitySubiektConfig $config
     * @param string $key klucz konfiguracji z tokenem
     */
    public function __construct(InfinitySubiektConfig $config, $key)
    {
        $this->config = $config;
        $this->key = $key;
    }

    /**
     * @param string|null $token
     *
     * @return bool
     */
    public function isValid($token)
    {
        $expected = $this->config->get($this->key);

        return $expected !== ''
            && is_string($token)
            && $token !== ''
            && hash_equals($expected, $token);
    }
}
