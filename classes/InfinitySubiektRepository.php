<?php
/**
 * Tabele modułu z danymi synchronizacji i odczyty katalogu PrestaShop
 * potrzebne do kodów towarów.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektRepository
{
    const ORDER_TABLE = 'infinitysubiekt_order';
    const CUSTOMER_TABLE = 'infinitysubiekt_customer';
    const PRODUCT_TABLE = 'infinitysubiekt_product';

    /** @var Db */
    private $db;

    /** @var InfinitySubiektClock */
    private $clock;

    /** @var string */
    private $prefix;

    /**
     * @param Db $db
     * @param InfinitySubiektClock $clock
     * @param string $prefix prefiks tabel PrestaShop
     */
    public function __construct(Db $db, InfinitySubiektClock $clock, $prefix)
    {
        $this->db = $db;
        $this->clock = $clock;
        $this->prefix = $prefix;
    }

    /**
     * @param int $idOrder
     *
     * @return array|null
     */
    public function findOrder($idOrder)
    {
        $row = $this->db->getRow(
            'SELECT * FROM ' . $this->table(self::ORDER_TABLE) . ' WHERE `id_order` = ' . (int) $idOrder,
            false
        );

        return $row ?: null;
    }

    /**
     * Blokada zamówienia na czas wystawiania: atomowy UPDATE, wygasa po $minutes.
     *
     * @param int $idOrder
     * @param int $minutes
     *
     * @return bool false, gdy blokadę trzyma inne żądanie
     */
    public function lockOrder($idOrder, $minutes)
    {
        $this->db->execute(
            'INSERT IGNORE INTO ' . $this->table(self::ORDER_TABLE) . ' (`id_order`) VALUES (' . (int) $idOrder . ')',
            false
        );

        $this->db->execute(
            'UPDATE ' . $this->table(self::ORDER_TABLE)
            . ' SET `locked_at` = ' . $this->quote($this->clock->now())
            . ' WHERE `id_order` = ' . (int) $idOrder
            . ' AND (`locked_at` IS NULL OR `locked_at` < ' . $this->quote($this->clock->at(-(int) $minutes * 60)) . ')',
            false
        );

        return (int) $this->db->Affected_Rows() === 1;
    }

    /**
     * @param int $idOrder
     *
     * @return bool
     */
    public function unlockOrder($idOrder)
    {
        return (bool) $this->db->execute(
            'UPDATE ' . $this->table(self::ORDER_TABLE) . ' SET `locked_at` = NULL WHERE `id_order` = ' . (int) $idOrder,
            false
        );
    }

    /**
     * Numer dokumentu wystawionego (albo adoptowanego) dla zamówienia. Wiersz istnieje po lockOrder().
     *
     * @param int $idOrder
     * @param string $number NumerPelny
     * @param string|null $documentType np. DodajFS
     *
     * @return bool
     */
    public function saveInvoice($idOrder, $number, $documentType)
    {
        $this->db->execute(
            'UPDATE ' . $this->table(self::ORDER_TABLE) . ' SET'
            . ' `invoice_number` = ' . $this->quote($number)
            . ', `document_type` = ' . ($documentType !== null && $documentType !== '' ? $this->quote($documentType) : 'NULL')
            . ', `issued_at` = ' . $this->quote($this->clock->now())
            . ' WHERE `id_order` = ' . (int) $idOrder,
            false
        );

        return (int) $this->db->Affected_Rows() === 1;
    }

    /**
     * Zamówienia, które mają już ten numer dokumentu (przypisanie z Subiekta, jeden dokument = jedno zamówienie).
     *
     * @param string $number NumerPelny
     *
     * @return int[] id_order
     */
    public function ordersWithInvoice($number)
    {
        $rows = $this->db->executeS(
            'SELECT `id_order` FROM ' . $this->table(self::ORDER_TABLE)
            . ' WHERE `invoice_number` = ' . $this->quote($number),
            true,
            false
        );

        return $rows ? array_map('intval', array_column($rows, 'id_order')) : [];
    }

    /**
     * @param int $idOrder
     *
     * @return bool
     */
    public function savePdfAt($idOrder)
    {
        return (bool) $this->db->execute(
            'UPDATE ' . $this->table(self::ORDER_TABLE)
            . ' SET `pdf_at` = ' . $this->quote($this->clock->now())
            . ' WHERE `id_order` = ' . (int) $idOrder,
            false
        );
    }

    /**
     * @param int $idAddress
     *
     * @return array|null
     */
    public function findCustomer($idAddress)
    {
        $row = $this->db->getRow(
            'SELECT * FROM ' . $this->table(self::CUSTOMER_TABLE) . ' WHERE `id_address` = ' . (int) $idAddress,
            false
        );

        return $row ?: null;
    }

    /**
     * @param int[] $idAddresses
     *
     * @return array id_address => wiersz
     */
    public function findCustomers(array $idAddresses)
    {
        $ids = array_filter(array_map('intval', $idAddresses));
        if (!$ids) {
            return [];
        }

        $rows = $this->db->executeS(
            'SELECT * FROM ' . $this->table(self::CUSTOMER_TABLE) . ' WHERE `id_address` IN (' . implode(',', $ids) . ')',
            true,
            false
        );

        $byAddress = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $byAddress[(int) $row['id_address']] = $row;
        }

        return $byAddress;
    }

    /**
     * Zapis wyniku customer/save. Symbol z odpowiedzi agenta (w trybie match może być obcy).
     *
     * @param int $idAddress
     * @param int $idCustomer
     * @param string $symbol
     * @param int|null $subiektId
     * @param string|null $matchedBy
     *
     * @return bool
     */
    public function saveCustomer($idAddress, $idCustomer, $symbol, $subiektId, $matchedBy)
    {
        return (bool) $this->db->execute(
            'INSERT INTO ' . $this->table(self::CUSTOMER_TABLE)
            . ' (`id_address`, `id_customer`, `symbol`, `subiekt_id`, `matched_by`, `synced_at`)'
            . ' VALUES (' . (int) $idAddress
            . ', ' . (int) $idCustomer
            . ', ' . $this->quote($symbol)
            . ', ' . ($subiektId ? (int) $subiektId : 'NULL')
            . ', ' . ($matchedBy !== null && $matchedBy !== '' ? $this->quote($matchedBy) : 'NULL')
            . ', ' . $this->quote($this->clock->now()) . ')'
            . ' ON DUPLICATE KEY UPDATE'
            . ' `id_customer` = VALUES(`id_customer`), `symbol` = VALUES(`symbol`), `subiekt_id` = VALUES(`subiekt_id`),'
            . ' `matched_by` = VALUES(`matched_by`), `synced_at` = VALUES(`synced_at`)',
            false
        );
    }

    /**
     * Symbol kontrahenta z wyniku order/add (customer_symbol); id i dopasowanie zostają bez zmian.
     *
     * @param int $idAddress
     * @param int $idCustomer
     * @param string $symbol
     *
     * @return bool
     */
    public function saveCustomerSymbol($idAddress, $idCustomer, $symbol)
    {
        return (bool) $this->db->execute(
            'INSERT INTO ' . $this->table(self::CUSTOMER_TABLE)
            . ' (`id_address`, `id_customer`, `symbol`, `synced_at`)'
            . ' VALUES (' . (int) $idAddress
            . ', ' . (int) $idCustomer
            . ', ' . $this->quote($symbol)
            . ', ' . $this->quote($this->clock->now()) . ')'
            . ' ON DUPLICATE KEY UPDATE `symbol` = VALUES(`symbol`), `synced_at` = VALUES(`synced_at`)',
            false
        );
    }

    /**
     * Klucz pozycji katalogu (produkt + kombinacja) w raportach i wynikach.
     *
     * @param int $idProduct
     * @param int $idAttr
     *
     * @return string
     */
    public static function itemKey($idProduct, $idAttr)
    {
        return (int) $idProduct . '-' . (int) $idAttr;
    }

    /**
     * Pozycje produktu: kombinacje albo sam produkt (kombinacja 0).
     *
     * @param int $idProduct
     *
     * @return array lista [id_product, id_product_attribute]
     */
    public function productItems($idProduct)
    {
        $rows = $this->db->executeS(
            'SELECT `id_product_attribute` FROM ' . $this->table('product_attribute')
            . ' WHERE `id_product` = ' . (int) $idProduct
            . ' ORDER BY `id_product_attribute`',
            true,
            false
        );

        if (!$rows) {
            return [[(int) $idProduct, 0]];
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = [(int) $idProduct, (int) $row['id_product_attribute']];
        }

        return $items;
    }

    /**
     * Pozycje produktów aktywnych w co najmniej jednym sklepie (cron stock_pull i price_pull).
     *
     * @return array lista [id_product, id_product_attribute]
     */
    public function catalogItems()
    {
        $rows = $this->db->executeS(
            'SELECT p.`id_product`, IFNULL(pa.`id_product_attribute`, 0) AS `id_product_attribute`'
            . ' FROM ' . $this->table('product') . ' p'
            . ' LEFT JOIN ' . $this->table('product_attribute') . ' pa ON pa.`id_product` = p.`id_product`'
            . ' WHERE EXISTS (SELECT 1 FROM ' . $this->table('product_shop') . ' ps'
            . ' WHERE ps.`id_product` = p.`id_product` AND ps.`active` = 1)'
            . ' ORDER BY p.`id_product`, `id_product_attribute`',
            true,
            false
        );

        $items = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $items[] = [(int) $row['id_product'], (int) $row['id_product_attribute']];
        }

        return $items;
    }

    /**
     * Pozycje katalogu, których EAN albo kod (jak w productCodes()) jest na liście z Subiekta (cron, product/list).
     * Produkt z kombinacjami = jego kombinacje; kombinacja nie dziedziczy EAN produktu (jak productEans()).
     *
     * @param string[] $eans
     * @param string[] $codes symbole towarów
     *
     * @return array lista ['item' => [id_product, id_product_attribute], 'code' => …, 'ean' => …]
     */
    public function itemsByCodes(array $eans, array $codes)
    {
        $eans = array_values(array_unique(array_filter(array_map('trim', $eans), 'strlen')));
        $codes = array_values(array_unique(array_filter(array_map('trim', $codes), 'strlen')));

        $found = [];
        foreach ($this->codeChunks($eans, $codes) as $chunk) {
            $eanIn = $chunk['eans'] ? implode(',', array_map([$this, 'quote'], $chunk['eans'])) : '';
            $codeIn = $chunk['codes'] ? implode(',', array_map([$this, 'quote'], $chunk['codes'])) : '';

            $productWhere = [];
            $attrWhere = [];
            if ($eanIn !== '') {
                $productWhere[] = 'p.`ean13` IN (' . $eanIn . ')';
                $attrWhere[] = 'pa.`ean13` IN (' . $eanIn . ')';
            }
            if ($codeIn !== '') {
                $productWhere[] = 'p.`reference` IN (' . $codeIn . ')';
                $attrWhere[] = 'pa.`reference` IN (' . $codeIn . ')';
                $attrWhere[] = '(pa.`reference` = \'\' AND p.`reference` IN (' . $codeIn . '))';
            }

            $rows = $this->db->executeS(
                'SELECT p.`id_product`, 0 AS `id_product_attribute`, p.`reference` AS `code`, p.`ean13` AS `ean`'
                . ' FROM ' . $this->table('product') . ' p'
                . ' WHERE NOT EXISTS (SELECT 1 FROM ' . $this->table('product_attribute') . ' pa0 WHERE pa0.`id_product` = p.`id_product`)'
                . ' AND (' . implode(' OR ', $productWhere) . ')'
                . ' UNION ALL'
                . ' SELECT pa.`id_product`, pa.`id_product_attribute`, IF(pa.`reference` <> \'\', pa.`reference`, p.`reference`) AS `code`, pa.`ean13` AS `ean`'
                . ' FROM ' . $this->table('product_attribute') . ' pa'
                . ' INNER JOIN ' . $this->table('product') . ' p ON p.`id_product` = pa.`id_product`'
                . ' WHERE ' . implode(' OR ', $attrWhere),
                true,
                false
            );
            foreach (is_array($rows) ? $rows : [] as $row) {
                $item = [(int) $row['id_product'], (int) $row['id_product_attribute']];
                $found[self::itemKey($item[0], $item[1])] = [
                    'item' => $item,
                    'code' => trim((string) $row['code']),
                    'ean' => trim((string) $row['ean']),
                ];
            }
        }

        return array_values($found);
    }

    /**
     * @param string[] $eans
     * @param string[] $codes
     *
     * @return array lista ['eans' => …, 'codes' => …], najwyżej 500 wartości każda
     */
    private function codeChunks(array $eans, array $codes)
    {
        $eanChunks = array_chunk($eans, 500);
        $codeChunks = array_chunk($codes, 500);
        $chunks = [];
        for ($i = 0, $n = max(count($eanChunks), count($codeChunks)); $i < $n; ++$i) {
            $chunks[] = [
                'eans' => isset($eanChunks[$i]) ? $eanChunks[$i] : [],
                'codes' => isset($codeChunks[$i]) ? $codeChunks[$i] : [],
            ];
        }

        return $chunks;
    }

    /**
     * Które z produktów są zestawami (paczkami PrestaShop).
     *
     * @param int[] $productIds
     *
     * @return int[]
     */
    public function packIds(array $productIds)
    {
        return $this->productIdsIn('pack', 'id_product_pack', $productIds);
    }

    /**
     * Które z produktów mają kombinacje.
     *
     * @param int[] $productIds
     *
     * @return int[]
     */
    public function productsWithCombinations(array $productIds)
    {
        return $this->productIdsIn('product_attribute', 'id_product', $productIds);
    }

    /**
     * @param string $table
     * @param string $column
     * @param int[] $productIds
     *
     * @return int[] id z $productIds, które mają wiersz w tabeli
     */
    private function productIdsIn($table, $column, array $productIds)
    {
        $found = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $productIds))), 1000) as $chunk) {
            $rows = $this->db->executeS(
                'SELECT DISTINCT `' . $column . '` AS `id` FROM ' . $this->table($table)
                . ' WHERE `' . $column . '` IN (' . implode(',', $chunk) . ')',
                true,
                false
            );
            foreach (is_array($rows) ? $rows : [] as $row) {
                $found[] = (int) $row['id'];
            }
        }

        return $found;
    }

    /**
     * Pozycje ze stanem w magazynie sklepu (zaawansowane zarządzanie magazynem), do zerowania w stock_pull.
     *
     * @param int $idWarehouse
     *
     * @return array lista [id_product, id_product_attribute]
     */
    public function warehouseItemsInStock($idWarehouse)
    {
        $rows = $this->db->executeS(
            'SELECT `id_product`, `id_product_attribute` FROM ' . $this->table('stock')
            . ' WHERE `id_warehouse` = ' . (int) $idWarehouse
            . ' GROUP BY `id_product`, `id_product_attribute`'
            . ' HAVING SUM(`physical_quantity`) > 0 OR SUM(`usable_quantity`) > 0',
            true,
            false
        );

        $items = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $items[] = [(int) $row['id_product'], (int) $row['id_product_attribute']];
        }

        return $items;
    }

    /**
     * Kod towaru w Subiekcie: referencja kombinacji, a gdy pusta, referencja produktu.
     * Kombinacja spoza produktu albo brak referencji = ''.
     *
     * @param array $items lista [id_product, id_product_attribute]
     *
     * @return array itemKey => kod
     */
    public function productCodes(array $items)
    {
        $productIds = [];
        $attrIds = [];
        foreach ($items as $item) {
            $productIds[(int) $item[0]] = (int) $item[0];
            if ((int) $item[1]) {
                $attrIds[(int) $item[1]] = (int) $item[1];
            }
        }

        $productRefs = [];
        if ($productIds) {
            $rows = $this->db->executeS(
                'SELECT `id_product`, `reference` FROM ' . $this->table('product')
                . ' WHERE `id_product` IN (' . implode(',', $productIds) . ')',
                true,
                false
            );
            foreach (is_array($rows) ? $rows : [] as $row) {
                $productRefs[(int) $row['id_product']] = trim((string) $row['reference']);
            }
        }

        $attrs = [];
        if ($attrIds) {
            $rows = $this->db->executeS(
                'SELECT `id_product_attribute`, `id_product`, `reference` FROM ' . $this->table('product_attribute')
                . ' WHERE `id_product_attribute` IN (' . implode(',', $attrIds) . ')',
                true,
                false
            );
            foreach (is_array($rows) ? $rows : [] as $row) {
                $attrs[(int) $row['id_product_attribute']] = $row;
            }
        }

        $codes = [];
        foreach ($items as $item) {
            $idProduct = (int) $item[0];
            $idAttr = (int) $item[1];
            $code = '';
            if (!$idAttr) {
                $code = isset($productRefs[$idProduct]) ? $productRefs[$idProduct] : '';
            } elseif (isset($attrs[$idAttr]) && (int) $attrs[$idAttr]['id_product'] === $idProduct) {
                $code = trim((string) $attrs[$idAttr]['reference']);
                if ($code === '' && isset($productRefs[$idProduct])) {
                    $code = $productRefs[$idProduct];
                }
            }
            $codes[self::itemKey($idProduct, $idAttr)] = $code;
        }

        return $codes;
    }

    /**
     * EAN13 pozycji: kombinacji albo, bez kombinacji, produktu. Kombinacja nie dziedziczy kodu produktu,
     * bo w Subiekcie kod kreskowy wskazuje jeden towar.
     *
     * @param array $items lista [id_product, id_product_attribute]
     *
     * @return array itemKey => EAN albo ''
     */
    public function productEans(array $items)
    {
        $productIds = [];
        $attrIds = [];
        foreach ($items as $item) {
            if ((int) $item[1]) {
                $attrIds[(int) $item[1]] = (int) $item[1];
            } else {
                $productIds[(int) $item[0]] = (int) $item[0];
            }
        }

        $productEans = [];
        if ($productIds) {
            $rows = $this->db->executeS(
                'SELECT `id_product`, `ean13` FROM ' . $this->table('product')
                . ' WHERE `id_product` IN (' . implode(',', $productIds) . ')',
                true,
                false
            );
            foreach (is_array($rows) ? $rows : [] as $row) {
                $productEans[(int) $row['id_product']] = trim((string) $row['ean13']);
            }
        }

        $attrEans = [];
        if ($attrIds) {
            $rows = $this->db->executeS(
                'SELECT `id_product_attribute`, `id_product`, `ean13` FROM ' . $this->table('product_attribute')
                . ' WHERE `id_product_attribute` IN (' . implode(',', $attrIds) . ')',
                true,
                false
            );
            foreach (is_array($rows) ? $rows : [] as $row) {
                $attrEans[(int) $row['id_product_attribute']] = $row;
            }
        }

        $eans = [];
        foreach ($items as $item) {
            $idProduct = (int) $item[0];
            $idAttr = (int) $item[1];
            $ean = '';
            if (!$idAttr) {
                $ean = isset($productEans[$idProduct]) ? $productEans[$idProduct] : '';
            } elseif (isset($attrEans[$idAttr]) && (int) $attrEans[$idAttr]['id_product'] === $idProduct) {
                $ean = trim((string) $attrEans[$idAttr]['ean13']);
            }
            $eans[self::itemKey($idProduct, $idAttr)] = $ean;
        }

        return $eans;
    }

    /**
     * @param int $idProduct
     *
     * @return array id_product_attribute => wiersz
     */
    public function findProducts($idProduct)
    {
        $rows = $this->db->executeS(
            'SELECT * FROM ' . $this->table(self::PRODUCT_TABLE) . ' WHERE `id_product` = ' . (int) $idProduct,
            true,
            false
        );

        $byAttr = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $byAttr[(int) $row['id_product_attribute']] = $row;
        }

        return $byAttr;
    }

    /**
     * Zapis wyniku product/save.
     *
     * @param int $idProduct
     * @param int $idAttr
     * @param string $code
     * @param int|null $subiektId
     *
     * @return bool
     */
    public function saveProduct($idProduct, $idAttr, $code, $subiektId)
    {
        return (bool) $this->db->execute(
            'INSERT INTO ' . $this->table(self::PRODUCT_TABLE)
            . ' (`id_product`, `id_product_attribute`, `code`, `subiekt_id`, `synced_at`)'
            . ' VALUES (' . (int) $idProduct
            . ', ' . (int) $idAttr
            . ', ' . $this->quote($code)
            . ', ' . ($subiektId ? (int) $subiektId : 'NULL')
            . ', ' . $this->quote($this->clock->now()) . ')'
            . ' ON DUPLICATE KEY UPDATE'
            . ' `code` = VALUES(`code`), `subiekt_id` = VALUES(`subiekt_id`), `synced_at` = VALUES(`synced_at`)',
            false
        );
    }

    /**
     * @param string $name
     *
     * @return string
     */
    private function table($name)
    {
        return '`' . $this->prefix . $name . '`';
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private function quote($value)
    {
        return '\'' . pSQL((string) $value, true) . '\'';
    }
}
