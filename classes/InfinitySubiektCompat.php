<?php
/**
 * Różnice między wersjami PrestaShop.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektCompat
{
    /**
     * PS 1.7.7+: nowa karta zamówienia (displayAdminOrderMain), Bootstrap 4, utf8mb4.
     *
     * @return bool
     */
    public static function isPs177()
    {
        return version_compare(_PS_VERSION_, '1.7.7.0', '>=');
    }

    /**
     * PS 1.7+: karta produktu na Symfony (Bootstrap 4).
     *
     * @return bool
     */
    public static function isPs17()
    {
        return version_compare(_PS_VERSION_, '1.7.0.0', '>=');
    }

    /**
     * PS 1.7.6+: karta klienta na Symfony (Bootstrap 4).
     *
     * @return bool
     */
    public static function isPs176()
    {
        return version_compare(_PS_VERSION_, '1.7.6.0', '>=');
    }

    /**
     * @return string
     */
    public static function dbCharset()
    {
        return self::isPs177() ? 'utf8mb4' : 'utf8';
    }
}
