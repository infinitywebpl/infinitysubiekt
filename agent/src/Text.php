<?php

declare(strict_types=1);

namespace InfinitySubiektAgent;

if (PHP_SAPI !== 'cli') {
    exit;
}

/**
 * Tekst i JSON. Komunikaty błędów COM i Windows przychodzą zwykle w CP1250, a wszystko,
 * co wychodzi z agenta (log, wynik zadania), ma być w UTF-8.
 */
final class Text
{
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * Tekst w UTF-8: poprawny zostaje bez zmian, inny jest traktowany jako CP1250.
     */
    public static function utf8(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $converted = @iconv('CP1250', 'UTF-8//IGNORE', $text);

        return $converted === false ? mb_scrub($text, 'UTF-8') : $converted;
    }

    /**
     * @param mixed $data
     */
    public static function json($data, bool $pretty = false): string
    {
        $json = json_encode($data, self::JSON_FLAGS | ($pretty ? JSON_PRETTY_PRINT : 0));

        return $json === false ? '{"error":"json_encode: ' . json_last_error_msg() . '"}' : $json;
    }
}
