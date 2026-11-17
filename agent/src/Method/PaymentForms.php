<?php

declare(strict_types=1);

namespace InfinitySubiektAgent\Method;

if (PHP_SAPI !== 'cli') {
    exit;
}

use InfinitySubiektAgent\Subiekt\Sql;
use InfinitySubiektAgent\Text;

/**
 * payment/forms: terminy płatności (kredyt kupiecki) i rodzaje kart z `sl_FormaPlatnosci`.
 * `fp_Typ` według dokumentacji Sfery: 0 = termin płatności (PlatnoscKredytId), 1 = karta (PlatnoscKartaId).
 *
 * Dokumentacja nie podaje kolumny z nazwą, więc agent bierze `fp_Nazwa`, a gdy jej brak, pierwszą kolumnę
 * z „Nazwa” w nazwie. `columns` w wyniku pokazuje faktyczne kolumny tabeli (diagnostyka).
 */
final readonly class PaymentForms implements Method
{
    // fp_Typ → payment w params order/add
    private const TYPES = [0 => 'credit', 1 => 'card'];

    public function __construct(private Sql $sql)
    {
    }

    public function execute(array $params): array
    {
        $rows = $this->sql->rows(
            'SELECT * FROM sl_FormaPlatnosci WHERE fp_Typ IN (' . implode(', ', array_keys(self::TYPES)) . ') ORDER BY fp_Typ, fp_Id'
        );

        $columns = $rows ? array_keys($rows[0]) : [];
        $nameColumn = self::nameColumn($columns);

        $forms = [];
        foreach ($rows as $row) {
            $id = (int) $row['fp_Id'];
            $name = $nameColumn === null ? '' : trim(Text::utf8((string) $row[$nameColumn]));
            $forms[] = [
                'id' => $id,
                'type' => self::TYPES[(int) $row['fp_Typ']],
                'name' => $name !== '' ? $name : '#' . $id,
            ];
        }

        return ['forms' => $forms, 'columns' => $columns];
    }

    /**
     * @param list<string> $columns
     */
    private static function nameColumn(array $columns): ?string
    {
        if (in_array('fp_Nazwa', $columns, true)) {
            return 'fp_Nazwa';
        }
        foreach ($columns as $column) {
            if (stripos($column, 'nazwa') !== false) {
                return $column;
            }
        }

        return null;
    }
}
