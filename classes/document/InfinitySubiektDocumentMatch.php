<?php
/**
 * Dopasowanie dokumentu wystawionego w Subiekcie, zanim moduł zaczął działać.
 *
 * find(): agent (order/find) szuka dokumentów rodzaju z konfiguracji w oknie dat zamówienia po numerze
 * zamówienia w tekstach dokumentu i po kwocie, a moduł porównuje każdego kandydata z zamówieniem
 * (numer, kwota, NIP, kod pocztowy, produkty). Niczego nie zapisuje.
 * assign(): przypisanie numeru wybranego przez pracownika, pod blokadą zamówienia jak przy wystawieniu.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektDocumentMatch
{
    // pierwsza wersja agenta z order/find
    const AGENT_VERSION = '0.6.0';

    const LIMIT = 10;

    // agent pomija krótsze teksty (przypadkowe trafienia)
    const MIN_NEEDLE = 3;

    // dokument bywa wystawiony dzień przed datą zamówienia (strefy czasowe, import)
    const DAYS_BEFORE = 1;

    const ASSIGN_OK = 'ok';
    const ASSIGN_ALREADY = 'already';
    const ASSIGN_IN_PROGRESS = 'in_progress';
    const ASSIGN_ERROR = 'error';

    /** @var InfinitySubiektClient */
    private $client;

    /** @var InfinitySubiektRepository */
    private $repository;

    /** @var InfinitySubiektTaskQueue */
    private $queue;

    /** @var InfinitySubiektDocumentTypeResolver */
    private $documentType;

    /** @var InfinitySubiektAgentStatus */
    private $agentStatus;

    /** @var InfinitySubiektConfig */
    private $config;

    public function __construct(
        InfinitySubiektClient $client,
        InfinitySubiektRepository $repository,
        InfinitySubiektTaskQueue $queue,
        InfinitySubiektDocumentTypeResolver $documentType,
        InfinitySubiektAgentStatus $agentStatus,
        InfinitySubiektConfig $config
    ) {
        $this->client = $client;
        $this->repository = $repository;
        $this->queue = $queue;
        $this->documentType = $documentType;
        $this->agentStatus = $agentStatus;
        $this->config = $config;
    }

    /**
     * Kandydaci z oceną zgodności, od najlepiej pasującego.
     *
     * @param Order $order
     *
     * @return array ['candidates' => […], 'searched' => kolumny, 'skipped' => kolumny] albo klucz `error`
     */
    public function find(Order $order)
    {
        $error = $this->precheck($order);
        if ($error !== null) {
            return ['error' => $error];
        }

        $time = strtotime($order->date_add);
        $params = [
            'documents' => [$this->documentType->resolve($order)],
            'needles' => $this->needles($order),
            'total' => round((float) $order->total_paid_tax_incl, 2),
            'date_from' => date('Y-m-d', $time - self::DAYS_BEFORE * 86400),
            'date_to' => date('Y-m-d', $time + max(0, (int) $this->config->get(InfinitySubiektConfig::MATCH_DAYS)) * 86400),
            'limit' => self::LIMIT,
        ];
        if (!$params['needles']) {
            unset($params['needles']);
        }

        $result = $this->client->callApi(InfinitySubiektClient::METHOD_ORDER_FIND, $params, InfinitySubiektConfig::TIMEOUT, (int) $order->id);
        if (isset($result['error'])) {
            return $result;
        }

        $expected = $this->expected($order);
        $candidates = [];
        foreach (isset($result['documents']) && is_array($result['documents']) ? $result['documents'] : [] as $document) {
            if (is_array($document) && !empty($document['number']) && is_string($document['number'])) {
                $candidates[] = $this->compare($document, $expected, (int) $order->id);
            }
        }

        usort($candidates, function (array $a, array $b) {
            return $b['score'] - $a['score'];
        });

        return [
            'candidates' => $candidates,
            'date_from' => $params['date_from'],
            'date_to' => $params['date_to'],
            'searched' => isset($result['searched']) && is_array($result['searched']) ? $result['searched'] : [],
            'skipped' => isset($result['skipped']) && is_array($result['skipped']) ? $result['skipped'] : [],
        ];
    }

    /**
     * Przypisanie numeru dokumentu do zamówienia. Agent potwierdza, że dokument istnieje i ma rodzaj z konfiguracji.
     *
     * @param Order $order
     * @param string $number NumerPelny wybrany przez pracownika
     *
     * @return array ['status' => ASSIGN_*, 'number' => …, 'task_id' => …, 'error' => …]
     */
    public function assign(Order $order, $number)
    {
        $number = trim((string) $number);
        $error = $this->precheck($order);
        if ($error === null && $number === '') {
            $error = 'brak numeru dokumentu';
        }
        if ($error !== null) {
            return ['status' => self::ASSIGN_ERROR, 'error' => $error];
        }

        $idOrder = (int) $order->id;
        if (!$this->repository->lockOrder($idOrder, InfinitySubiektConfig::ORDER_LOCK)) {
            return ['status' => self::ASSIGN_IN_PROGRESS];
        }

        try {
            return $this->assignLocked($order, $number);
        } finally {
            $this->repository->unlockOrder($idOrder);
        }
    }

    /**
     * @param Order $order
     * @param string $number
     *
     * @return array
     */
    private function assignLocked(Order $order, $number)
    {
        $idOrder = (int) $order->id;

        $row = $this->repository->findOrder($idOrder);
        if ($row !== null && (string) $row['invoice_number'] !== '') {
            return ['status' => self::ASSIGN_ALREADY, 'number' => $row['invoice_number']];
        }

        $active = $this->queue->findActive($idOrder, InfinitySubiektClient::METHOD_ORDER_ADD);
        if ($active !== null) {
            return ['status' => self::ASSIGN_IN_PROGRESS, 'task_id' => (int) $active['id']];
        }

        $taken = $this->takenBy($number, $idOrder);
        if ($taken) {
            return ['status' => self::ASSIGN_ERROR, 'error' => sprintf('dokument %s jest już przypisany do zamówienia #%d', $number, $taken)];
        }

        $type = $this->documentType->resolve($order);
        $result = $this->client->callApi(
            InfinitySubiektClient::METHOD_ORDER_FIND,
            ['documents' => [$type], 'numbers' => [$number]],
            InfinitySubiektConfig::TIMEOUT,
            $idOrder
        );
        if (isset($result['error'])) {
            return ['status' => self::ASSIGN_ERROR, 'error' => $result['error'], 'task_id' => isset($result['task_id']) ? (int) $result['task_id'] : null];
        }

        $found = false;
        foreach (isset($result['documents']) && is_array($result['documents']) ? $result['documents'] : [] as $document) {
            if (is_array($document) && isset($document['number']) && (string) $document['number'] === $number) {
                $found = true;
            }
        }
        if (!$found) {
            return ['status' => self::ASSIGN_ERROR, 'error' => sprintf('w Subiekcie nie ma dokumentu %s rodzaju z konfiguracji', $number)];
        }

        // bez symbolu kontrahenta: dawny dokument mógł mieć kontrahenta spoza schematu symboli modułu
        $this->repository->saveInvoice($idOrder, $number, $type);

        return ['status' => self::ASSIGN_OK, 'number' => $number];
    }

    /**
     * @param Order $order
     *
     * @return string|null
     */
    private function precheck(Order $order)
    {
        if (!Validate::isLoadedObject($order)) {
            return 'brak zamówienia';
        }
        if (!$this->agentStatus->atLeast(self::AGENT_VERSION)) {
            return sprintf('agent %s nie umie szukać dokumentów: zaktualizuj go do %s', $this->agentStatus->version(), self::AGENT_VERSION);
        }

        return null;
    }

    /**
     * Teksty, po których agent szuka dokumentu: numer (referencja) i id zamówienia.
     *
     * @param Order $order
     *
     * @return string[]
     */
    private function needles(Order $order)
    {
        $needles = [];
        foreach ([(string) $order->reference, (string) (int) $order->id] as $needle) {
            $needle = trim($needle);
            if (Tools::strlen($needle) >= self::MIN_NEEDLE) {
                $needles[] = $needle;
            }
        }

        return array_values(array_unique($needles));
    }

    /**
     * Dane zamówienia do porównania.
     *
     * @param Order $order
     *
     * @return array
     */
    private function expected(Order $order)
    {
        $rows = $order->getProducts();
        $items = [];
        foreach ($rows as $row) {
            $items[] = [(int) $row['product_id'], (int) $row['product_attribute_id']];
        }
        $eans = $items ? $this->repository->productEans($items) : [];

        $lines = [];
        foreach ($rows as $row) {
            if ((int) $row['product_quantity'] <= 0) {
                continue;
            }
            $key = InfinitySubiektRepository::itemKey($row['product_id'], $row['product_attribute_id']);
            $lines[] = [
                'code' => Tools::strtoupper(trim((string) $row['product_reference'])),
                'ean' => isset($eans[$key]) ? (string) $eans[$key] : '',
                'qty' => (int) $row['product_quantity'],
            ];
        }

        $addresses = [];
        foreach (array_unique([(int) $order->id_address_invoice, (int) $order->id_address_delivery]) as $idAddress) {
            $address = new Address($idAddress);
            if (Validate::isLoadedObject($address)) {
                $addresses[] = $address;
            }
        }

        $vatNumber = '';
        $postcodes = [];
        foreach ($addresses as $address) {
            if ($vatNumber === '' && (int) $address->id === (int) $order->id_address_invoice) {
                $vatNumber = self::digits($address->vat_number);
            }
            $postcode = self::postcode($address->postcode);
            if ($postcode !== '') {
                $postcodes[] = $postcode;
            }
        }

        return [
            'id_order' => (string) (int) $order->id,
            'reference' => trim((string) $order->reference),
            'total' => round((float) $order->total_paid_tax_incl, 2),
            'vat_number' => $vatNumber,
            'postcodes' => array_values(array_unique($postcodes)),
            'lines' => $lines,
        ];
    }

    /**
     * Wynik porównania kandydata z zamówieniem. `checks`: true = zgodne, false = niezgodne, null = nie do sprawdzenia.
     *
     * @param array $document dokument z order/find
     * @param array $expected expected()
     * @param int $idOrder
     *
     * @return array
     */
    private function compare(array $document, array $expected, $idOrder)
    {
        $texts = [];
        foreach (['subtitle', 'message', 'original_number'] as $field) {
            $texts[$field] = isset($document[$field]) && is_string($document[$field]) ? $document[$field] : '';
        }

        $referenceIn = $expected['reference'] !== '' ? $this->fieldsWith($texts, $expected['reference'], false) : [];
        $idIn = $this->fieldsWith($texts, $expected['id_order'], true);

        $total = isset($document['total']) && is_numeric($document['total']) ? (float) $document['total'] : null;
        $parties = [];
        foreach (['buyer', 'receiver'] as $key) {
            if (isset($document[$key]) && is_array($document[$key])) {
                $parties[] = $document[$key];
            }
        }

        $checks = [
            'reference' => $referenceIn ? true : false,
            'id_order' => $idIn ? true : false,
            'total' => $total === null ? null : abs($total - $expected['total']) < 0.01,
            'vat_number' => $this->vatCheck($parties, $expected['vat_number']),
            'postcode' => $this->postcodeCheck($parties, $expected['postcodes']),
        ];
        $products = $this->productsCheck(isset($document['products']) && is_array($document['products']) ? $document['products'] : null, $expected['lines']);

        $score = ($checks['reference'] ? 40 : 0) + ($checks['id_order'] ? 20 : 0) + ($checks['total'] ? 25 : 0)
            + ($checks['vat_number'] ? 10 : 0) + ($checks['postcode'] ? 10 : 0);
        if ($products !== null && $products['lines'] > 0) {
            $score += (int) round(25 * $products['matched'] / $products['lines']);
        }

        $buyer = isset($document['buyer']) && is_array($document['buyer']) ? $document['buyer'] : [];

        return [
            'number' => (string) $document['number'],
            'date' => isset($document['date']) ? (string) $document['date'] : '',
            'total' => $total,
            'buyer' => trim((isset($buyer['name']) ? $buyer['name'] : '') . ', ' . (isset($buyer['postcode']) ? $buyer['postcode'] : '') . ' ' . (isset($buyer['city']) ? $buyer['city'] : ''), ', '),
            'texts' => array_filter($texts, 'strlen'),
            'reference_in' => array_merge($referenceIn, $idIn),
            'checks' => $checks,
            'products' => $products,
            'score' => $score,
            // numer zamówienia i kwota = dokument tego zamówienia z dużym prawdopodobieństwem
            'likely' => ($checks['reference'] || $checks['id_order']) && $checks['total'] === true,
            'taken_by' => $this->takenBy((string) $document['number'], $idOrder),
            'error' => isset($document['error']) ? (string) $document['error'] : '',
        ];
    }

    /**
     * Pola dokumentu z tekstem. Id zamówienia tylko jako osobna liczba (nie fragment innej).
     *
     * @param array $texts pole => tekst
     * @param string $needle
     * @param bool $number
     *
     * @return string[] pola
     */
    private function fieldsWith(array $texts, $needle, $number)
    {
        $fields = [];
        foreach ($texts as $field => $text) {
            $found = $number
                ? preg_match('/(?<!\d)' . preg_quote($needle, '/') . '(?!\d)/u', $text) === 1
                : Tools::strpos(Tools::strtoupper($text), Tools::strtoupper($needle)) !== false;
            if ($found) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @param array[] $parties nabywca i odbiorca dokumentu
     * @param string $vatNumber NIP z adresu do faktury
     *
     * @return bool|null
     */
    private function vatCheck(array $parties, $vatNumber)
    {
        if ($vatNumber === '' || !$parties) {
            return null;
        }
        foreach ($parties as $party) {
            if (isset($party['vat_number']) && self::digits($party['vat_number']) === $vatNumber) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array[] $parties
     * @param string[] $postcodes kody adresów zamówienia
     *
     * @return bool|null
     */
    private function postcodeCheck(array $parties, array $postcodes)
    {
        if (!$postcodes || !$parties) {
            return null;
        }
        foreach ($parties as $party) {
            if (isset($party['postcode']) && in_array(self::postcode($party['postcode']), $postcodes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pozycje zamówienia znalezione w dokumencie z tą samą ilością: EAN, potem kod = symbol (jak product/list).
     *
     * @param array|null $positions pozycje dokumentu
     * @param array $lines pozycje zamówienia
     *
     * @return array|null ['matched' => n, 'lines' => n, 'positions' => n] albo null bez pozycji z agenta
     */
    private function productsCheck($positions, array $lines)
    {
        if ($positions === null) {
            return null;
        }

        $goods = [];
        foreach ($positions as $position) {
            if (is_array($position) && empty($position['one_time'])) {
                $goods[] = $position;
            }
        }

        $used = [];
        $matched = 0;
        foreach ($lines as $line) {
            foreach ($goods as $i => $position) {
                if (isset($used[$i])) {
                    continue;
                }
                $ean = isset($position['ean']) ? trim((string) $position['ean']) : '';
                $symbol = isset($position['symbol']) ? Tools::strtoupper(trim((string) $position['symbol'])) : '';
                $same = ($line['ean'] !== '' && $ean === $line['ean']) || ($line['code'] !== '' && $symbol === $line['code']);
                if ($same && isset($position['qty']) && abs((float) $position['qty'] - $line['qty']) < 0.0001) {
                    $used[$i] = true;
                    ++$matched;
                    break;
                }
            }
        }

        return ['matched' => $matched, 'lines' => count($lines), 'positions' => count($goods)];
    }

    /**
     * @param string $number
     * @param int $idOrder bieżące zamówienie (nie liczy się)
     *
     * @return int id innego zamówienia z tym numerem albo 0
     */
    private function takenBy($number, $idOrder)
    {
        foreach ($this->repository->ordersWithInvoice($number) as $id) {
            if ($id !== (int) $idOrder) {
                return $id;
            }
        }

        return 0;
    }

    /**
     * @param string $text
     *
     * @return string
     */
    private static function digits($text)
    {
        return (string) preg_replace('/\D+/', '', (string) $text);
    }

    /**
     * Kod pocztowy bez spacji i myślników, wielkimi literami.
     *
     * @param string $text
     *
     * @return string
     */
    private static function postcode($text)
    {
        return Tools::strtoupper((string) preg_replace('/[\s-]+/u', '', (string) $text));
    }
}
