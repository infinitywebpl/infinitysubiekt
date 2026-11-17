<?php
/**
 * Heartbeat agenta: zapis przy każdym pollu, status online/offline.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektAgentStatus
{
    /** @var InfinitySubiektConfig */
    private $config;

    /** @var InfinitySubiektClock */
    private $clock;

    public function __construct(InfinitySubiektConfig $config, InfinitySubiektClock $clock)
    {
        $this->config = $config;
        $this->clock = $clock;
    }

    /**
     * @param string|null $userAgent nagłówek polla, np. InfinitySubiektAgent/0.4.0
     */
    public function touch($userAgent = null)
    {
        $this->config->set(InfinitySubiektConfig::AGENT_LAST_SEEN, $this->clock->now());

        if ($userAgent !== null && preg_match('#InfinitySubiektAgent/(\d+(?:\.\d+)*)#', $userAgent, $m)
            && $m[1] !== $this->version()
        ) {
            $this->config->set(InfinitySubiektConfig::AGENT_VERSION, $m[1]);
        }
    }

    /**
     * @return string wersja agenta z ostatniego polla ('' = nieznana)
     */
    public function version()
    {
        return $this->config->get(InfinitySubiektConfig::AGENT_VERSION);
    }

    /**
     * Czy agent ma co najmniej daną wersję. Nieznana wersja = tak (agent jeszcze nie pollował
     * albo pośrednik zmienia User-Agent), żeby brak informacji nie blokował pracy.
     *
     * @param string $version
     *
     * @return bool
     */
    public function atLeast($version)
    {
        $current = $this->version();

        return $current === '' || version_compare($current, $version, '>=');
    }

    /**
     * @return string pusty, gdy agent nigdy nie pollował
     */
    public function lastSeen()
    {
        return $this->config->get(InfinitySubiektConfig::AGENT_LAST_SEEN);
    }

    /**
     * Mówi tylko, że agent żyje i polluje tę instalację.
     *
     * @return bool
     */
    public function isOnline()
    {
        $lastSeen = $this->lastSeen();
        if ($lastSeen === '') {
            return false;
        }

        $time = strtotime($lastSeen);

        return $time !== false && $time >= time() - InfinitySubiektConfig::ONLINE_THRESHOLD;
    }
}
