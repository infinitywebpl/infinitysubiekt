<?php
/**
 * Kodowanie JSON wspólne dla kolejki i odpowiedzi HTTP.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektJson
{
    const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * @param mixed $data
     *
     * @return string
     */
    public static function encode($data)
    {
        $json = json_encode($data, self::FLAGS);

        return $json === false ? 'null' : $json;
    }

    /**
     * Pusta tablica jako obiekt ({}), nie lista ([]).
     *
     * @param array $data
     *
     * @return string
     */
    public static function encodeObject(array $data)
    {
        return $data ? self::encode($data) : '{}';
    }

    /**
     * @param string|null $json
     *
     * @return array|null null, gdy to nie jest obiekt ani tablica JSON
     */
    public static function decode($json)
    {
        if ($json === null || $json === '') {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }
}
