<?php
/**
 * Czas aplikacji. Wszystkie daty liczy PHP (strefa PS_TIMEZONE), w SQL nie używamy NOW().
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektClock
{
    const FORMAT = 'Y-m-d H:i:s';

    /**
     * @return string
     */
    public function now()
    {
        return date(self::FORMAT);
    }

    /**
     * @param int $seconds przesunięcie względem teraz (ujemne = w przeszłości)
     *
     * @return string
     */
    public function at($seconds)
    {
        return date(self::FORMAT, time() + (int) $seconds);
    }

    /**
     * @return string
     */
    public function today()
    {
        return date('Y-m-d');
    }
}
