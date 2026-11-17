<?php

declare(strict_types=1);

namespace InfinitySubiektAgent\Subiekt;

if (PHP_SAPI !== 'cli') {
    exit;
}

/**
 * Wartości z COM i ADO. Kwoty Subiekta (Currency, Decimal) przychodzą jako obiekt variant,
 * a ich zamiana na tekst zależy od ustawień regionalnych Windows (przecinek dziesiętny).
 */
final class Value
{
    // VARENUM
    private const VT_R4 = 4;
    private const VT_R8 = 5;
    private const VT_CY = 6;
    private const VT_DECIMAL = 14;

    public static function float(mixed $value): float
    {
        if ($value instanceof \variant) {
            $value = (string) $value;
        }
        if (is_string($value)) {
            $value = str_replace([',', ' ', "\u{00A0}"], ['.', '', ''], $value);
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * Kwota albo null, gdy Subiekt jej nie ma (0 = brak ceny).
     */
    public static function price(mixed $value): ?float
    {
        $price = round(self::float($value), 4);

        return $price > 0 ? $price : null;
    }

    public static function isNumericVariant(\variant $value): bool
    {
        return in_array(variant_get_type($value), [self::VT_R4, self::VT_R8, self::VT_CY, self::VT_DECIMAL], true);
    }
}
