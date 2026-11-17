<?php

declare(strict_types=1);

namespace InfinitySubiektAgent\Method;

if (PHP_SAPI !== 'cli') {
    exit;
}

use InfinitySubiektAgent\Subiekt\Sql;
use InfinitySubiektAgent\Text;

/**
 * product/features: słownik cech towarów `sl_CechaTw` do selecta w konfiguracji modułu.
 */
final readonly class ProductFeatures implements Method
{
    public function __construct(private Sql $sql)
    {
    }

    public function execute(array $params): array
    {
        $features = [];
        foreach ($this->sql->rows('SELECT ctw_Id, ctw_Nazwa FROM sl_CechaTw ORDER BY ctw_Nazwa') as $row) {
            $name = trim(Text::utf8((string) $row['ctw_Nazwa']));
            if ($name !== '') {
                $features[] = ['id' => (int) $row['ctw_Id'], 'name' => $name];
            }
        }

        return ['features' => $features];
    }
}
