<?php
/**
 * Stany i cena zakupu między Subiektem a PrestaShop.
 *
 * pull: product/info w paczkach dla wskazanych pozycji (BO, MCP), osobno stan (do StockAvailable) i cena zakupu
 * (do wholesale_price). Cron bierze listę z Subiekta (InfinitySubiektCatalogPull) i zapisuje przez applyInfo().
 * push: product/stock_set, czyli dokument PW/RW w Subiekcie, dlatego tylko ręcznie z BO.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektProductStock
{
    // agent przyjmuje 200, ale szukanie po EAN wczytuje towar dodatkowo, a paczka ma 60 s
    const BATCH = 100;

    const PULL_STOCK = 'stock';
    const PULL_PRICE = 'price';
    const PUSH_NOTE = 'korekta stanu z PrestaShop';

    /** @var InfinitySubiektClient */
    private $client;

    /** @var InfinitySubiektRepository */
    private $repository;

    /** @var InfinitySubiektConfig */
    private $config;

    /** @var InfinitySubiektWarehouseStock */
    private $warehouseStock;

    /** @var array id_product => bool */
    private $advancedStock = [];

    public function __construct(
        InfinitySubiektClient $client,
        InfinitySubiektRepository $repository,
        InfinitySubiektConfig $config,
        InfinitySubiektWarehouseStock $warehouseStock
    ) {
        $this->client = $client;
        $this->repository = $repository;
        $this->config = $config;
        $this->warehouseStock = $warehouseStock;
    }

    /**
     * Pobranie stanu albo ceny zakupu dla pozycji katalogu.
     *
     * @param array $items lista [id_product, id_product_attribute]
     * @param int $priority PRIORITY_PANEL z BO, PRIORITY_BACKGROUND z crona
     * @param string $scope PULL_STOCK albo PULL_PRICE
     *
     * @return array raport: updated, missing, skipped (listy tekstów), errors
     */
    public function pull(array $items, $priority, $scope)
    {
        $report = ['updated' => [], 'missing' => [], 'skipped' => [], 'errors' => []];

        // kod + EAN => pozycje (kilka kombinacji może dzielić referencję produktu)
        $groups = [];
        foreach ($this->plan($items, $scope) as $entry) {
            if ($entry['skip'] !== '') {
                $report['skipped'][] = $entry['key'] . ': ' . $entry['skip'];
                continue;
            }
            $key = self::groupKey($entry['code'], $entry['ean']);
            if (!isset($groups[$key])) {
                $groups[$key] = ['code' => $entry['code'], 'ean' => $entry['ean'], 'items' => []];
            }
            $groups[$key]['items'][] = $entry['item'];
        }

        $calls = [];
        foreach (array_chunk(array_keys($groups), self::BATCH) as $batch) {
            $lookups = [];
            foreach ($batch as $key) {
                $lookups[] = ['code' => $groups[$key]['code'], 'ean' => $groups[$key]['ean']];
            }

            if ($this->client->agentDown($calls)) {
                $report['errors'][] = sprintf('pominięto %d kodów, bo agent nie odpowiada', count($lookups));
                continue;
            }

            $result = $this->client->callApi(
                'product/info',
                [
                    'items' => $lookups,
                    'warehouse_id' => (int) $this->config->get(InfinitySubiektConfig::WAREHOUSE_ID),
                    'with_prices' => $scope !== self::PULL_STOCK,
                ],
                InfinitySubiektConfig::TIMEOUT,
                null,
                $priority
            );
            $calls[] = $result;

            if (isset($result['error'])) {
                $report['errors'][] = $this->taskError($result, count($lookups));
                continue;
            }

            $this->applyBatch($result, $groups, $batch, $scope, $report);
        }

        return $report;
    }

    /**
     * @param string $code
     * @param string $ean
     *
     * @return string
     */
    private static function groupKey($code, $ean)
    {
        return Tools::strtoupper($code) . "\n" . $ean;
    }

    /**
     * @param array $group ['code', 'ean', …]
     *
     * @return string kod do raportu
     */
    private static function groupLabel(array $group)
    {
        return $group['code'] !== '' ? $group['code'] : 'EAN ' . $group['ean'];
    }

    /**
     * Liczby dla dry-runu crona, bez wołania agenta.
     *
     * @param array $items
     * @param string $scope PULL_STOCK albo PULL_PRICE
     *
     * @return array ['items' => …, 'codes' => …, 'batches' => …, 'skipped' => …]
     */
    public function count(array $items, $scope)
    {
        $codes = [];
        $skipped = 0;
        foreach ($this->plan($items, $scope) as $entry) {
            if ($entry['skip'] !== '') {
                ++$skipped;
            } else {
                $codes[self::groupKey($entry['code'], $entry['ean'])] = true;
            }
        }

        return [
            'items' => count($items),
            'codes' => count($codes),
            'batches' => (int) ceil(count($codes) / self::BATCH),
            'skipped' => $skipped,
        ];
    }

    /**
     * Wysłanie stanu PrestaShop do Subiekta (dokument PW/RW na różnicę).
     *
     * @param int $idProduct
     * @param int $idAttr
     *
     * @return array wynik product/stock_set albo klucz `error`
     */
    public function push($idProduct, $idAttr)
    {
        $idProduct = (int) $idProduct;
        $idAttr = (int) $idAttr;

        $product = new Product($idProduct);
        if (!Validate::isLoadedObject($product)) {
            return ['error' => sprintf('brak produktu %d', $idProduct)];
        }
        if (!$idAttr && $product->hasAttributes()) {
            return ['error' => 'produkt ma kombinacje, wyślij stan wybranej kombinacji'];
        }
        if ($this->usesAdvancedStock($idProduct)) {
            return ['error' => 'produkt korzysta z zaawansowanego zarządzania magazynem'];
        }

        $key = InfinitySubiektRepository::itemKey($idProduct, $idAttr);
        $codes = $this->repository->productCodes([[$idProduct, $idAttr]]);
        $eans = $this->repository->productEans([[$idProduct, $idAttr]]);
        if ($codes[$key] === '' && $eans[$key] === '') {
            return ['error' => 'brak referencji (kodu towaru) i EAN'];
        }

        $price = $this->purchasePrice($product, $idAttr);

        // ujemny stan (sprzedaż ponad stan) nie ma odpowiednika w Subiekcie
        return $this->client->callApi('product/stock_set', [
            'code' => $codes[$key],
            'ean' => $eans[$key],
            'quantity' => max(0, (int) StockAvailable::getQuantityAvailableByProduct($idProduct, $idAttr)),
            'warehouse_id' => (int) $this->config->get(InfinitySubiektConfig::WAREHOUSE_ID),
            'price' => $price > 0 ? $price : null,
            'note' => self::PUSH_NOTE,
        ], InfinitySubiektConfig::TIMEOUT);
    }

    /**
     * Cena zakupu wysyłana z push: kombinacji, a gdy 0, produktu.
     *
     * @param Product $product
     * @param int $idAttr
     *
     * @return float
     */
    public function purchasePrice(Product $product, $idAttr)
    {
        if ($idAttr) {
            $combination = new Combination((int) $idAttr);
            if ((float) $combination->wholesale_price > 0) {
                return (float) $combination->wholesale_price;
            }
        }

        return (float) $product->wholesale_price;
    }

    /**
     * Czy pobranie może zapisać stan produktu: z zaawansowanym magazynem tylko przy wybranym magazynie sklepu.
     *
     * @param int $idProduct
     *
     * @return bool
     */
    public function canPull($idProduct)
    {
        return !$this->usesAdvancedStock($idProduct) || $this->shopWarehouseId() > 0;
    }

    /**
     * @return int
     */
    private function shopWarehouseId()
    {
        return (int) $this->config->get(InfinitySubiektConfig::PS_WAREHOUSE_ID);
    }

    /**
     * Stan liczy PrestaShop z magazynów (PS 1.6-1.7), więc StockAvailable nie nadpisujemy.
     *
     * @param int $idProduct
     *
     * @return bool
     */
    public function usesAdvancedStock($idProduct)
    {
        $idProduct = (int) $idProduct;
        if (!isset($this->advancedStock[$idProduct])) {
            $this->advancedStock[$idProduct] = method_exists('StockAvailable', 'dependsOnStock')
                && (bool) StockAvailable::dependsOnStock($idProduct);
        }

        return $this->advancedStock[$idProduct];
    }

    /**
     * Linie tekstu z raportu pull (BO i log crona).
     *
     * @param array $report
     * @param int|null $limit maks. linii szczegółów na grupę (null = wszystkie)
     *
     * @return string[]
     */
    public function reportLines(array $report, $limit = null)
    {
        $lines = [sprintf(
            'zaktualizowano %d, brak w Subiekcie %d, pominięto %d, błędy %d',
            count($report['updated']),
            count($report['missing']),
            count($report['skipped']),
            count($report['errors'])
        ) . (isset($report['zeroed']) ? sprintf(', wyzerowano %d', count($report['zeroed'])) : '')];

        $groups = [
            'errors' => 'błąd',
            'missing' => 'brak w Subiekcie',
            'skipped' => 'pominięto',
            'zeroed' => 'wyzerowano',
            'updated' => 'zaktualizowano',
        ];
        foreach ($groups as $group => $label) {
            if (!isset($report[$group])) {
                continue;
            }
            $details = $limit === null ? $report[$group] : array_slice($report[$group], 0, $limit);
            foreach ($details as $detail) {
                $lines[] = $label . ': ' . $detail;
            }
            if (count($details) < count($report[$group])) {
                $lines[] = sprintf('%s: … i %d więcej', $label, count($report[$group]) - count($details));
            }
        }

        return $lines;
    }

    /**
     * Kody pozycji i powody pominięcia.
     *
     * @param array $items
     * @param string $scope PULL_*: magazyn sklepu jest potrzebny tylko do zapisu stanu
     *
     * @return array lista ['item', 'key', 'code', 'ean', 'skip']
     */
    private function plan(array $items, $scope)
    {
        $codes = $this->repository->productCodes($items);
        $eans = $this->repository->productEans($items);

        $plan = [];
        foreach ($items as $item) {
            $key = InfinitySubiektRepository::itemKey($item[0], $item[1]);
            $code = $codes[$key];
            $ean = $eans[$key];
            $skip = '';
            if ($code === '' && $ean === '') {
                $skip = 'brak referencji i EAN';
            } elseif ($scope !== self::PULL_PRICE && !$this->canPull($item[0])) {
                $skip = 'zaawansowane zarządzanie magazynem: wybierz magazyn sklepu w konfiguracji modułu';
            }
            $plan[] = ['item' => [(int) $item[0], (int) $item[1]], 'key' => $key, 'code' => $code, 'ean' => $ean, 'skip' => $skip];
        }

        return $plan;
    }

    /**
     * @param array $result wynik product/info
     * @param array $groups
     * @param string[] $keys klucze grup wysłanych w tej paczce
     * @param string $scope PULL_*
     * @param array $report
     */
    private function applyBatch(array $result, array $groups, array $keys, $scope, array &$report)
    {
        $answered = [];

        foreach (isset($result['items']) && is_array($result['items']) ? $result['items'] : [] as $info) {
            if (!is_array($info) || !isset($info['code'])) {
                continue;
            }
            $key = self::groupKey((string) $info['code'], isset($info['ean']) ? (string) $info['ean'] : '');
            if (!isset($groups[$key])) {
                continue;
            }
            $answered[$key] = true;
            // w raporcie towar, który agent naprawdę znalazł: po EAN może mieć inny symbol
            $label = !empty($info['symbol']) ? (string) $info['symbol'] : self::groupLabel($groups[$key]);
            foreach ($groups[$key]['items'] as $item) {
                try {
                    $report['updated'][] = $this->applyInfo($item, $label, $info, $scope);
                } catch (Exception $e) {
                    $report['errors'][] = InfinitySubiektRepository::itemKey($item[0], $item[1]) . ': ' . $e->getMessage();
                }
            }
        }

        foreach (isset($result['not_found']) && is_array($result['not_found']) ? $result['not_found'] : [] as $lookup) {
            if (!is_array($lookup)) {
                continue;
            }
            $key = self::groupKey(isset($lookup['code']) ? (string) $lookup['code'] : '', isset($lookup['ean']) ? (string) $lookup['ean'] : '');
            if (isset($groups[$key])) {
                $answered[$key] = true;
                $report['missing'][] = self::groupLabel($groups[$key]);
            }
        }

        foreach ($keys as $key) {
            if (!isset($answered[$key])) {
                $report['errors'][] = self::groupLabel($groups[$key]) . ': brak w odpowiedzi agenta';
            }
        }
    }

    /**
     * Zapis stanu albo ceny zakupu jednej pozycji.
     *
     * @param array $item [id_product, id_product_attribute]
     * @param string $code
     * @param array $info pozycja z product/info albo product/list (stock, reserved, purchase_prices)
     * @param string $scope PULL_*
     *
     * @return string opis zmiany
     *
     * @throws InfinitySubiektException
     */
    public function applyInfo(array $item, $code, array $info, $scope)
    {
        list($idProduct, $idAttr) = $item;

        $priceKey = $this->config->get(InfinitySubiektConfig::PURCHASE_SOURCE);
        $price = isset($info['purchase_prices'][$priceKey]) && is_numeric($info['purchase_prices'][$priceKey])
            ? round((float) $info['purchase_prices'][$priceKey], 6)
            : null;

        $changes = [];
        if ($scope !== self::PULL_PRICE) {
            $changes[] = 'stan ' . $this->applyStock($idProduct, $idAttr, $info, $price);
        }
        if ($scope !== self::PULL_STOCK) {
            if ($price !== null) {
                $this->setWholesalePrice($idProduct, $idAttr, $price);
            }
            $changes[] = $price !== null ? 'cena zakupu ' . number_format($price, 2, '.', '') : 'bez ceny zakupu w Subiekcie';
        }

        return sprintf('%s (%s): %s', InfinitySubiektRepository::itemKey($idProduct, $idAttr), $code, implode(', ', $changes));
    }

    /**
     * @param int $idProduct
     * @param int $idAttr
     * @param array $info pozycja z product/info
     * @param float|null $price cena zakupu z Subiekta, gdy była w odpowiedzi
     *
     * @return int zapisana ilość
     *
     * @throws InfinitySubiektException
     */
    private function applyStock($idProduct, $idAttr, array $info, $price)
    {
        $stock = isset($info['stock']) ? (float) $info['stock'] : 0.0;
        $reserved = isset($info['reserved']) ? (float) $info['reserved'] : 0.0;
        $source = $this->config->get(InfinitySubiektConfig::STOCK_SOURCE);
        $qty = max(0, (int) floor($source === InfinitySubiektConfig::STOCK_SOURCE_STOCK ? $stock : $stock - $reserved));

        if ($this->usesAdvancedStock($idProduct)) {
            $this->warehouseStock->setQuantity(
                $idProduct,
                $idAttr,
                $this->shopWarehouseId(),
                $qty,
                $price > 0 ? $price : $this->purchasePrice(new Product($idProduct), $idAttr)
            );
        } else {
            foreach (Product::getShopsByProduct($idProduct) as $shop) {
                StockAvailable::setQuantity($idProduct, $idAttr, $qty, (int) $shop['id_shop']);
            }
        }

        return $qty;
    }

    /**
     * Zapis przez obiekt i update(), żeby zadziałały hooki aktualizacji produktu.
     *
     * @param int $idProduct
     * @param int $idAttr
     * @param float $price
     *
     * @throws InfinitySubiektException
     */
    private function setWholesalePrice($idProduct, $idAttr, $price)
    {
        $object = $idAttr ? new Combination((int) $idAttr) : new Product((int) $idProduct);
        if (!Validate::isLoadedObject($object)) {
            throw new InfinitySubiektException('nie można wczytać produktu lub kombinacji');
        }
        if (abs((float) $object->wholesale_price - $price) < 0.000001) {
            return;
        }

        $object->wholesale_price = $price;
        if (!$object->update()) {
            throw new InfinitySubiektException('nie udało się zapisać ceny zakupu');
        }
    }

    /**
     * @param array $result
     * @param int $count
     *
     * @return string
     */
    private function taskError(array $result, $count)
    {
        $error = is_string($result['error']) ? $result['error'] : InfinitySubiektJson::encode($result['error']);
        if ($error === InfinitySubiektClient::ERROR_TIMEOUT && isset($result['task_id'])) {
            $error .= ' (zadanie #' . (int) $result['task_id'] . ' zostaje w kolejce)';
        }

        return sprintf('paczka %d kodów: %s', $count, $error);
    }
}
