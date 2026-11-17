<?php
/**
 * Pobieranie z crona według listy z Subiekta (product/list), a nie całego katalogu sklepu.
 *
 * Stany: towary ze stanem > 0 w magazynie Subiekta, a gdy konfiguracja podaje cechę, towary z tą cechą (także ze
 * stanem 0). Po pełnej liście pozycje magazynu sklepu ze stanem spoza listy dostają 0 (gdy włączone w konfiguracji).
 *
 * Ceny zakupu, dwa etapy: lista towarów z dokumentami zakupu (sam SQL) i dopasowanie do sklepu, potem ceny przez
 * product/info tylko dla dopasowanych towarów (Sfera wczytuje każdy towar, więc to jest drogie). Zakupy od dnia
 * ostatniego udanego przebiegu minus PRICE_PULL_OVERLAP; pierwszy przebieg i przebieg „full” biorą wszystkie.
 *
 * Dopasowanie towaru do pozycji sklepu: EAN pozycji wśród kodów kreskowych towaru, potem kod pozycji równy symbolowi.
 * Zestawów (paczek PrestaShop) moduł nie dotyka: ich dostępność liczy sklep. Pozycji „produkt bez kombinacji” produktu,
 * który ma kombinacje (stary wpis stanu), też nie.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektCatalogPull
{
    const METHOD = 'product/list';
    const PRICE_METHOD = 'product/info';
    const AGENT_VERSION = '0.5.2';

    // towarów na stronę listy (sam SQL, maks. agenta)
    const PAGE_LIST = 1000;
    // towarów na zadanie cen: Sfera wczytuje każdy, a zadanie czeka najwyżej TIMEOUT
    const PRICE_BATCH = 50;

    // dry-run z BO musi zmieścić się w limicie proxy (Cloudflare 100-120 s) dla wszystkich zadań razem
    const PREVIEW_SECONDS = 25;
    const PREVIEW_TIMEOUT = 20;

    // ochrona przed pętlą, gdyby agent zwracał w kółko ten sam kursor
    const MAX_PAGES = 1000;

    /** @var InfinitySubiektClient */
    private $client;

    /** @var InfinitySubiektRepository */
    private $repository;

    /** @var InfinitySubiektProductStock */
    private $productStock;

    /** @var InfinitySubiektWarehouseStock */
    private $warehouseStock;

    /** @var InfinitySubiektConfig */
    private $config;

    /** @var InfinitySubiektClock */
    private $clock;

    /** @var callable|null woła się po każdym zadaniu agenta (cron odświeża wtedy swoją blokadę) */
    private $heartbeat;

    public function __construct(
        InfinitySubiektClient $client,
        InfinitySubiektRepository $repository,
        InfinitySubiektProductStock $productStock,
        InfinitySubiektWarehouseStock $warehouseStock,
        InfinitySubiektConfig $config,
        InfinitySubiektClock $clock
    ) {
        $this->client = $client;
        $this->repository = $repository;
        $this->productStock = $productStock;
        $this->warehouseStock = $warehouseStock;
        $this->config = $config;
        $this->clock = $clock;
    }

    /**
     * @param callable|null $heartbeat
     */
    public function setHeartbeat($heartbeat)
    {
        $this->heartbeat = $heartbeat;
    }

    // ---------------------------------------------------------------- stany

    /**
     * @return array raport jak InfinitySubiektProductStock::pull() plus `zeroed`
     */
    public function pullStock()
    {
        return $this->stock(false);
    }

    /**
     * Dry-run stanów: lista z Subiekta (sam SQL) i dopasowanie do sklepu, bez zapisu.
     *
     * @return string[]
     */
    public function previewStock()
    {
        $lines = array_merge([$this->stockLabel()], $this->compareFeature());
        $report = $this->stock(true);
        $lines = array_merge($lines, $this->previewLines($report));
        if ($report['listed'] === null || $report['listed'] === 0 || !empty($report['partial'])) {
            if (!empty($report['partial'])) {
                $lines[] = 'pozycje do wyzerowania dry-run policzy tylko przy pełnej liście w limicie czasu';
            }

            return $lines;
        }

        if (!$this->zeroEnabled()) {
            $lines[] = 'zerowanie wyłączone';
        } else {
            $lines[] = sprintf(
                'wyzerowałby %d pozycji magazynu sklepu %d%s',
                count($report['zeroed']),
                $this->shopWarehouseId(),
                $report['zeroed'] ? ', np. ' . implode(', ', array_slice($report['zeroed'], 0, 10)) : ''
            );
        }

        return $lines;
    }

    /**
     * @param bool $dryRun
     *
     * @return array raport z `listed` (null = lista niepełna) i `zeroed`
     */
    private function stock($dryRun)
    {
        $report = ['updated' => [], 'missing' => [], 'skipped' => [], 'errors' => [], 'zeroed' => []];
        $seen = [];

        $listed = $this->walk($this->stockParams(), $dryRun, $report, function (array $items) use ($dryRun, &$report, &$seen) {
            foreach ($this->match($items, $report) as $match) {
                list($idProduct, $idAttr) = $match['item'];
                $key = InfinitySubiektRepository::itemKey($idProduct, $idAttr);
                $seen[$key] = true;
                if (!$this->productStock->canPull($idProduct)) {
                    $report['skipped'][] = $key . ': zaawansowane zarządzanie magazynem bez magazynu sklepu w konfiguracji modułu';
                    continue;
                }
                if ($dryRun) {
                    $report['updated'][] = $key;
                    continue;
                }

                try {
                    $report['updated'][] = $this->productStock->applyInfo($match['item'], $match['symbol'], $match['info'], InfinitySubiektProductStock::PULL_STOCK);
                } catch (Exception $e) {
                    $report['errors'][] = $key . ': ' . $e->getMessage();
                }
            }
        });
        $report['listed'] = $listed;

        if ($listed === null) {
            if ($this->zeroEnabled()) {
                $report['errors'][] = 'lista z Subiekta niepełna, zerowanie pominięte';
            }

            return $report;
        }
        if ($listed === 0) {
            $report['errors'][] = sprintf('Subiekt nie zwrócił żadnego towaru (%s), zerowanie pominięte', $this->stockLabel());

            return $report;
        }
        if ($this->zeroEnabled() && empty($report['partial'])) {
            $this->zeroMissing($seen, $report, $dryRun);
        }

        return $report;
    }

    /**
     * Stan 0 w magazynie sklepu dla pozycji spoza listy; bez zestawów i starych wpisów produktu z kombinacjami.
     *
     * @param array $seen itemKey => true
     * @param array $report
     * @param bool $dryRun
     */
    private function zeroMissing(array $seen, array &$report, $dryRun)
    {
        $warehouse = $this->shopWarehouseId();
        $candidates = [];
        foreach ($this->repository->warehouseItemsInStock($warehouse) as $item) {
            if (!isset($seen[InfinitySubiektRepository::itemKey($item[0], $item[1])])) {
                $candidates[] = $item;
            }
        }

        $ids = array_map(function (array $item) {
            return $item[0];
        }, $candidates);
        $packs = array_flip($this->repository->packIds($ids));
        $withCombinations = array_flip($this->repository->productsWithCombinations($ids));

        $packCount = 0;
        $staleCount = 0;
        foreach ($candidates as $item) {
            $key = InfinitySubiektRepository::itemKey($item[0], $item[1]);
            if (isset($packs[$item[0]])) {
                ++$packCount;
                continue;
            }
            if ($item[1] === 0 && isset($withCombinations[$item[0]])) {
                ++$staleCount;
                continue;
            }
            if ($dryRun) {
                $report['zeroed'][] = $key;
                continue;
            }

            try {
                $this->warehouseStock->setQuantity($item[0], $item[1], $warehouse, 0, 0.0);
                $report['zeroed'][] = $key;
            } catch (Exception $e) {
                $report['errors'][] = $key . ': zerowanie: ' . $e->getMessage();
            }
        }

        if ($packCount) {
            $report['skipped'][] = sprintf('%d zestawów w magazynie sklepu: bez zmian (dostępność zestawu liczy sklep)', $packCount);
        }
        if ($staleCount) {
            $report['skipped'][] = sprintf('%d pozycji „bez kombinacji” produktów z kombinacjami: bez zmian', $staleCount);
        }
    }

    /**
     * Parametry listy stanów: cecha z konfiguracji albo towary ze stanem > 0.
     *
     * @return array
     */
    private function stockParams()
    {
        $feature = trim($this->config->get(InfinitySubiektConfig::STOCK_FEATURE));

        return ($feature !== '' ? ['feature' => $feature] : ['in_stock' => true])
            + ['warehouse_id' => (int) $this->config->get(InfinitySubiektConfig::WAREHOUSE_ID)];
    }

    /**
     * @return string
     */
    private function stockLabel()
    {
        $feature = trim($this->config->get(InfinitySubiektConfig::STOCK_FEATURE));
        $warehouse = (int) $this->config->get(InfinitySubiektConfig::WAREHOUSE_ID);

        return sprintf(
            '%s, %s',
            $feature !== '' ? sprintf('towary z cechą „%s”', $feature) : 'towary ze stanem > 0',
            $warehouse > 0 ? sprintf('magazyn Subiekta %d', $warehouse) : 'magazyn domyślny agenta'
        );
    }

    /**
     * Dry-run: czy cecha z konfiguracji różni się od samego stanu > 0 (trzy sumy z agenta, bez list).
     *
     * @return string[]
     */
    private function compareFeature()
    {
        $feature = trim($this->config->get(InfinitySubiektConfig::STOCK_FEATURE));
        if ($feature === '') {
            return [];
        }

        $warehouse = ['warehouse_id' => (int) $this->config->get(InfinitySubiektConfig::WAREHOUSE_ID)];
        $counts = [];
        foreach ([
            'feature' => ['feature' => $feature],
            'stock' => ['in_stock' => true],
            'both' => ['feature' => $feature, 'in_stock' => true],
        ] as $name => $filter) {
            $result = $this->call(self::METHOD, $filter + $warehouse + ['limit' => 1, 'with_total' => true], true);
            if (isset($result['error']) || !isset($result['total'])) {
                return [sprintf('porównanie cechy ze stanem wymaga agenta %s', self::AGENT_VERSION)];
            }
            $counts[$name] = (int) $result['total'];
        }

        return [
            sprintf('porównanie: %d z cechą, %d ze stanem > 0, %d z cechą i stanem > 0', $counts['feature'], $counts['stock'], $counts['both']),
            sprintf(
                'cecha bez stanu: %d (dostaną 0 tak czy inaczej), stan bez cechy: %d (tylko te różnią obie metody)',
                $counts['feature'] - $counts['both'],
                $counts['stock'] - $counts['both']
            ),
        ];
    }

    // ---------------------------------------------------------------- ceny zakupu

    /**
     * @param bool $full true = wszystkie kupowane towary, bez daty
     *
     * @return array raport jak InfinitySubiektProductStock::pull()
     */
    public function pullPrices($full)
    {
        $startedOn = $this->clock->today();
        $report = $this->prices($full, false);
        if ($report['listed'] !== null && !empty($report['complete'])) {
            $this->config->set(InfinitySubiektConfig::PRICE_PULL_DATE, $startedOn);
        }

        return $report;
    }

    /**
     * Dry-run cen: lista kupowanych towarów (bez Sfery) i dopasowanie do sklepu, bez zapisu i bez cen.
     *
     * @param bool $full
     *
     * @return string[]
     */
    public function previewPrices($full)
    {
        $since = $this->priceSince($full);
        $report = $this->prices($full, true);

        $lines = array_merge(
            [$since === null ? 'towary z dokumentami zakupu, wszystkie (pełny przebieg)' : sprintf('towary z dokumentami zakupu od %s', $since)],
            $this->previewLines($report)
        );
        if ($report['listed'] !== null) {
            $lines[] = sprintf(
                '%sceny przez Sferę wczytałby dla %d towarów (%d zadań po %d)',
                empty($report['partial']) ? '' : 'z tej części: ',
                $report['to_price'],
                (int) ceil($report['to_price'] / self::PRICE_BATCH),
                self::PRICE_BATCH
            );
        }

        return $lines;
    }

    /**
     * @param bool $full
     * @param bool $dryRun
     *
     * @return array raport z `listed`, `to_price` i `complete`
     */
    private function prices($full, $dryRun)
    {
        $report = ['updated' => [], 'missing' => [], 'skipped' => [], 'errors' => []];
        $params = ['purchased' => true];
        $since = $this->priceSince($full);
        if ($since !== null) {
            $params['purchased_since'] = $since;
        }

        // etap 1: symbol => pozycje sklepu
        $targets = [];
        $listed = $this->walk($params, $dryRun, $report, function (array $items) use (&$report, &$targets) {
            foreach ($this->match($items, $report) as $match) {
                $targets[$match['symbol']][] = $match['item'];
            }
        });
        $report['listed'] = $listed;
        $report['to_price'] = count($targets);
        if ($listed === null) {
            return $report;
        }
        if ($dryRun) {
            foreach ($targets as $items) {
                foreach ($items as $item) {
                    $report['updated'][] = InfinitySubiektRepository::itemKey($item[0], $item[1]);
                }
            }

            return $report;
        }

        // etap 2: ceny tylko dopasowanych towarów
        $report['complete'] = $this->applyPrices($targets, $report);

        return $report;
    }

    /**
     * @param array $targets symbol => lista [id_product, id_product_attribute]
     * @param array $report
     *
     * @return bool czy wszystkie paczki przeszły
     */
    private function applyPrices(array $targets, array &$report)
    {
        $complete = true;
        $calls = [];
        foreach (array_chunk(array_keys($targets), self::PRICE_BATCH) as $symbols) {
            if ($this->client->agentDown($calls)) {
                $report['errors'][] = sprintf('pominięto %d towarów, bo agent nie odpowiada', count($symbols));
                $complete = false;
                continue;
            }

            $lookups = [];
            foreach ($symbols as $symbol) {
                $lookups[] = ['code' => (string) $symbol, 'ean' => ''];
            }
            $result = $this->call(self::PRICE_METHOD, [
                'items' => $lookups,
                'warehouse_id' => (int) $this->config->get(InfinitySubiektConfig::WAREHOUSE_ID),
                'with_prices' => true,
            ], false);
            $calls[] = $result;
            if (isset($result['error'])) {
                $report['errors'][] = sprintf('ceny %d towarów: %s', count($symbols), $this->errorText($result));
                $complete = false;
                continue;
            }

            $byCode = [];
            foreach ($symbols as $symbol) {
                $byCode[Tools::strtoupper((string) $symbol)] = (string) $symbol;
            }
            foreach (isset($result['items']) && is_array($result['items']) ? $result['items'] : [] as $info) {
                $code = is_array($info) && isset($info['code']) ? Tools::strtoupper((string) $info['code']) : '';
                if (!isset($byCode[$code])) {
                    continue;
                }
                $symbol = $byCode[$code];
                unset($byCode[$code]);
                foreach ($targets[$symbol] as $item) {
                    try {
                        $report['updated'][] = $this->productStock->applyInfo($item, $symbol, $info, InfinitySubiektProductStock::PULL_PRICE);
                    } catch (Exception $e) {
                        $report['errors'][] = InfinitySubiektRepository::itemKey($item[0], $item[1]) . ': ' . $e->getMessage();
                    }
                }
            }
            foreach ($byCode as $symbol) {
                $report['errors'][] = $symbol . ': brak w odpowiedzi agenta';
            }
        }

        return $complete;
    }

    /**
     * Czy zwykły price_pull i tak weźmie wszystkie kupowane towary (nie było jeszcze udanego przebiegu).
     *
     * @return bool
     */
    public function pricePullIsFull()
    {
        return $this->priceSince(false) === null;
    }

    /**
     * @param bool $full
     *
     * @return string|null RRRR-MM-DD albo null = bez daty
     */
    private function priceSince($full)
    {
        $last = $this->config->get(InfinitySubiektConfig::PRICE_PULL_DATE);
        if ($full || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $last)) {
            return null;
        }

        return date('Y-m-d', strtotime($last . ' -' . InfinitySubiektConfig::PRICE_PULL_OVERLAP . ' days'));
    }

    // ---------------------------------------------------------------- wspólne

    /**
     * Strony product/list po kolei; $onPage dostaje towary każdej strony.
     *
     * @param array $params
     * @param bool $dryRun najwyżej PREVIEW_SECONDS, potem `partial` = true; `total` = suma z agenta, gdy ją podał
     * @param array $report
     * @param callable $onPage
     *
     * @return int|null liczba towarów z Subiekta, null = lista niepełna (błąd agenta)
     */
    private function walk(array $params, $dryRun, array &$report, $onPage)
    {
        $listed = 0;
        $afterId = 0;
        $deadline = time() + self::PREVIEW_SECONDS;
        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            if ($dryRun && $page > 0 && time() >= $deadline) {
                $report['partial'] = true;

                return $listed;
            }

            $result = $this->call(
                self::METHOD,
                $params + ['after_id' => $afterId, 'limit' => self::PAGE_LIST] + ($dryRun && $page === 0 ? ['with_total' => true] : []),
                $dryRun
            );
            if (isset($result['error'])) {
                $report['errors'][] = sprintf('lista z Subiekta po tw_Id %d: %s', $afterId, $this->errorText($result));

                return null;
            }
            if (isset($result['total'])) {
                $report['total'] = (int) $result['total'];
            }

            $items = isset($result['items']) && is_array($result['items']) ? array_values($result['items']) : [];
            $listed += count($items);
            call_user_func($onPage, $items);

            $next = isset($result['next_after_id']) ? (int) $result['next_after_id'] : 0;
            if ($next <= $afterId) {
                return $listed;
            }
            $afterId = $next;
        }

        $report['errors'][] = sprintf('przerwano po %d stronach listy z Subiekta', self::MAX_PAGES);

        return null;
    }

    /**
     * Dopasowanie towarów strony do pozycji sklepu. Zestawy pomija (raport `skipped`), towary bez pozycji
     * dopisuje do `missing`.
     *
     * @param array $items towary z product/list
     * @param array $report
     *
     * @return array lista ['item' => [id_product, id_product_attribute], 'symbol' => …, 'info' => towar]
     */
    private function match(array $items, array &$report)
    {
        $byEan = [];
        $bySymbol = [];
        $eans = [];
        $symbols = [];
        foreach ($items as $index => $info) {
            if (!is_array($info) || !isset($info['symbol'])) {
                continue;
            }
            $symbol = trim((string) $info['symbol']);
            if ($symbol !== '') {
                $bySymbol[Tools::strtoupper($symbol)] = $index;
                $symbols[] = $symbol;
            }
            foreach (isset($info['eans']) && is_array($info['eans']) ? $info['eans'] : [] as $ean) {
                $ean = trim((string) $ean);
                if ($ean !== '') {
                    $byEan[$ean] = $index;
                    $eans[] = $ean;
                }
            }
        }

        $rows = $this->repository->itemsByCodes($eans, $symbols);
        $packs = array_flip($this->repository->packIds(array_map(function (array $row) {
            return $row['item'][0];
        }, $rows)));

        $matches = [];
        $matched = [];
        foreach ($rows as $row) {
            if ($row['ean'] !== '' && isset($byEan[$row['ean']])) {
                $index = $byEan[$row['ean']];
            } elseif ($row['code'] !== '' && isset($bySymbol[Tools::strtoupper($row['code'])])) {
                $index = $bySymbol[Tools::strtoupper($row['code'])];
            } else {
                continue;
            }
            $matched[$index] = true;
            if (isset($packs[$row['item'][0]])) {
                $report['skipped'][] = InfinitySubiektRepository::itemKey($row['item'][0], $row['item'][1]) . ': zestaw, bez zmian';
                continue;
            }
            $matches[] = ['item' => $row['item'], 'symbol' => trim((string) $items[$index]['symbol']), 'info' => $items[$index]];
        }

        foreach ($items as $index => $info) {
            if (is_array($info) && isset($info['symbol']) && !isset($matched[$index])) {
                $report['missing'][] = 'brak w sklepie: ' . $info['symbol'];
            }
        }

        return $matches;
    }

    /**
     * Zadanie agenta; po nim heartbeat (blokada crona żyje, dopóki przebieg robi postęp).
     *
     * @param string $method
     * @param array $params
     * @param bool $dryRun krótszy limit czekania i priorytet panelu
     *
     * @return array
     */
    private function call($method, array $params, $dryRun)
    {
        $result = $this->client->callApi(
            $method,
            $params,
            $dryRun ? self::PREVIEW_TIMEOUT : InfinitySubiektConfig::TIMEOUT,
            null,
            $dryRun ? InfinitySubiektConfig::PRIORITY_PANEL : InfinitySubiektConfig::PRIORITY_BACKGROUND
        );
        if ($this->heartbeat !== null) {
            call_user_func($this->heartbeat);
        }

        return $result;
    }

    /**
     * @param array $report
     *
     * @return string[]
     */
    private function previewLines(array $report)
    {
        if ($report['listed'] === null) {
            return $report['errors'];
        }

        $listed = (int) $report['listed'];
        $partial = !empty($report['partial']);
        $lines = [];
        if ($partial) {
            $lines[] = sprintf(
                'w Subiekcie %s; dry-run sprawdził pierwsze %d (limit %d s, pełna lista nie zmieści się w limicie proxy)',
                isset($report['total']) ? (string) (int) $report['total'] : 'nie wiadomo ile',
                $listed,
                self::PREVIEW_SECONDS
            );
        }

        $packs = 0;
        $other = 0;
        foreach ($report['skipped'] as $line) {
            if (strpos($line, 'zestaw') !== false) {
                ++$packs;
            } else {
                ++$other;
            }
        }
        $lines[] = sprintf(
            '%s: dopasowanych pozycji sklepu %d, towarów bez pozycji w sklepie %d%s%s',
            $partial ? sprintf('z pierwszych %d', $listed) : sprintf('w Subiekcie %d', $listed),
            count($report['updated']),
            count($report['missing']),
            $packs ? sprintf(', zestawy bez zmian %d', $packs) : '',
            $other ? sprintf(', pominąłby %d (zaawansowany magazyn bez magazynu sklepu)', $other) : ''
        );

        return $lines;
    }

    /**
     * Linie raportu do logu crona.
     *
     * @param array $report
     * @param int|null $limit maks. linii szczegółów na grupę
     *
     * @return string[]
     */
    public function reportLines(array $report, $limit = null)
    {
        return $this->productStock->reportLines($report, $limit);
    }

    /**
     * Zerowanie dotyczy tylko magazynu sklepu (zaawansowane zarządzanie magazynem): stan produktów bez niego
     * to suma wszystkich źródeł, więc zero mogłoby skasować stan hurtowni.
     *
     * @return bool
     */
    private function zeroEnabled()
    {
        return $this->config->getBool(InfinitySubiektConfig::STOCK_ZERO_MISSING) && $this->shopWarehouseId() > 0;
    }

    /**
     * @return int
     */
    private function shopWarehouseId()
    {
        return (int) $this->config->get(InfinitySubiektConfig::PS_WAREHOUSE_ID);
    }

    /**
     * @param array $result
     *
     * @return string
     */
    private function errorText(array $result)
    {
        $error = is_string($result['error']) ? $result['error'] : InfinitySubiektJson::encode($result['error']);
        if ($error === 'unknown method') {
            return sprintf('agent nie zna metody: zaktualizuj go do wersji %s', self::AGENT_VERSION);
        }
        if ($error === InfinitySubiektClient::ERROR_TIMEOUT && isset($result['task_id'])) {
            $error .= ' (zadanie #' . (int) $result['task_id'] . ' zostaje w kolejce)';
        }

        return $error;
    }
}
