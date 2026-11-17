<?php
/**
 * Klient kolejki: „synchroniczne” wywołanie metody agenta i metody kontraktu.
 * Stany towarów są w InfinitySubiektProductStock.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektClient
{
    // pozycja pominięta, bo agent nie odpowiedział przy poprzedniej
    const ERROR_SKIPPED = 'skipped';
    const ERROR_TIMEOUT = 'timeout';

    const METHOD_ORDER_ADD = 'order/add';
    const METHOD_ORDER_PDF = 'order/pdf';
    const METHOD_ORDER_FIND = 'order/find';
    const METHOD_PAYMENT_FORMS = 'payment/forms';
    const METHOD_AGENT_UPDATE = 'agent/update';
    const METHOD_AGENT_ROLLBACK = 'agent/rollback';

    // pierwsza wersja agenta z products_value (rabat zamówienia)
    const AGENT_DISCOUNT_VERSION = '0.4.0';

    // katalog PDF w _PS_DOWNLOAD_DIR_ (download/ ma .htaccess z deny)
    const PDF_DIR = 'infinitysubiekt';

    // wynik addInvoice()
    const INVOICE_OK = 'ok';
    const INVOICE_ALREADY = 'already';
    const INVOICE_ADOPTED = 'adopted';
    const INVOICE_IN_PROGRESS = 'in_progress';
    const INVOICE_ERROR = 'error';

    /** @var InfinitySubiektTaskQueue */
    private $queue;

    /** @var InfinitySubiektPayload */
    private $payload;

    /** @var InfinitySubiektRepository */
    private $repository;

    /** @var InfinitySubiektAgentStatus */
    private $agentStatus;

    public function __construct(
        InfinitySubiektTaskQueue $queue,
        InfinitySubiektPayload $payload,
        InfinitySubiektRepository $repository,
        InfinitySubiektAgentStatus $agentStatus
    ) {
        $this->queue = $queue;
        $this->payload = $payload;
        $this->repository = $repository;
        $this->agentStatus = $agentStatus;
    }

    /**
     * Tworzy zadanie i czeka na wynik. Błąd = klucz `error` (także `timeout` z `task_id`).
     * Po timeoucie zadanie żyje dalej w kolejce do expires_at.
     *
     * @param string $method
     * @param array $params
     * @param int|null $timeout sekundy, null = InfinitySubiektConfig::TIMEOUT
     * @param int|null $orderId
     * @param int $priority
     *
     * @return array
     */
    public function callApi($method, array $params = [], $timeout = null, $orderId = null, $priority = InfinitySubiektConfig::PRIORITY_PANEL)
    {
        // zablokowana sesja blokowałaby inne karty pracownika na czas czekania
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $id = $this->queue->create($method, $params, $orderId, $priority);
        if (!$id) {
            return ['error' => 'nie udało się utworzyć zadania'];
        }

        return $this->queue->waitForResult($id, $timeout === null ? InfinitySubiektConfig::TIMEOUT : (int) $timeout);
    }

    /**
     * @return array
     */
    public function ping()
    {
        return $this->callApi('ping', [], InfinitySubiektConfig::TIMEOUT_PING);
    }

    /**
     * Zdalna aktualizacja agenta do wersji z agent/release.json.
     *
     * @return array wynik agent/update albo klucz `error`
     */
    public function updateAgent()
    {
        return $this->callApi(self::METHOD_AGENT_UPDATE, [], InfinitySubiektConfig::TIMEOUT_UPDATE);
    }

    /**
     * Powrót agenta do poprzedniej wersji.
     *
     * @return array wynik agent/rollback albo klucz `error`
     */
    public function rollbackAgent()
    {
        return $this->callApi(self::METHOD_AGENT_ROLLBACK);
    }

    /**
     * Terminy płatności i rodzaje kart z Subiekta.
     *
     * @return array wynik payment/forms albo klucz `error`
     */
    public function paymentForms()
    {
        return $this->callApi(self::METHOD_PAYMENT_FORMS);
    }

    /**
     * Kartoteka kontrahenta dla adresu.
     *
     * @param int $idAddress
     *
     * @return array wynik customer/save albo klucz `error`
     */
    public function saveCustomer($idAddress)
    {
        try {
            $params = $this->payload->customerParty($idAddress);
        } catch (InfinitySubiektException $e) {
            return ['error' => $e->getMessage()];
        }

        return $this->sendCustomer($idAddress, $params);
    }

    /**
     * Wszystkie aktywne adresy klienta po kolei.
     *
     * @param int $idCustomer
     * @param int $idLang
     *
     * @return array id_address => wynik jak w saveCustomer()
     */
    public function saveCustomerAddresses($idCustomer, $idLang)
    {
        $customer = new Customer((int) $idCustomer);
        if (!Validate::isLoadedObject($customer)) {
            return [];
        }

        $results = [];
        foreach ($customer->getAddresses((int) $idLang) as $address) {
            $idAddress = (int) $address['id_address'];
            $results[$idAddress] = $this->agentDown($results)
                ? ['error' => self::ERROR_SKIPPED]
                : $this->saveCustomer($idAddress);
        }

        return $results;
    }

    /**
     * Nabywca i odbiorca zamówienia, z tymi samymi danymi co w order/add.
     *
     * @param Order $order
     *
     * @return array id_address => wynik jak w saveCustomer(); przy błędzie danych [0 => ['error' => …]]
     */
    public function saveOrderCustomers(Order $order)
    {
        try {
            $parties = $this->payload->orderParties($order);
        } catch (InfinitySubiektException $e) {
            return [0 => ['error' => $e->getMessage()]];
        }

        $results = [];
        foreach ($parties as $idAddress => $params) {
            $results[$idAddress] = $this->agentDown($results)
                ? ['error' => self::ERROR_SKIPPED]
                : $this->sendCustomer($idAddress, $params);
        }

        return $results;
    }

    /**
     * Dokument dla zamówienia z ochroną przed duplikatem:
     * jedno zamówienie = najwyżej jeden dokument.
     *
     * @param Order $order
     * @param string $issuer imię i nazwisko pracownika
     *
     * @return array ['status' => INVOICE_*, 'number' => …, 'task_id' => …, 'error' => …]
     */
    public function addInvoice(Order $order, $issuer)
    {
        if (!Validate::isLoadedObject($order)) {
            return ['status' => self::INVOICE_ERROR, 'error' => 'brak zamówienia'];
        }

        $idOrder = (int) $order->id;

        // 1. dwa kliknięcia „Wystaw” nie mogą utworzyć dwóch zadań
        if (!$this->repository->lockOrder($idOrder, InfinitySubiektConfig::ORDER_LOCK)) {
            return ['status' => self::INVOICE_IN_PROGRESS];
        }

        try {
            return $this->issueInvoice($order, $issuer);
        } finally {
            $this->repository->unlockOrder($idOrder);
        }
    }

    /**
     * Wystawienie pod blokadą zamówienia.
     *
     * @param Order $order
     * @param string $issuer
     *
     * @return array
     */
    private function issueInvoice(Order $order, $issuer)
    {
        $idOrder = (int) $order->id;

        // 2. numer już zapisany
        $row = $this->repository->findOrder($idOrder);
        if ($row !== null && (string) $row['invoice_number'] !== '') {
            return ['status' => self::INVOICE_ALREADY, 'number' => $row['invoice_number']];
        }

        // 3. zadanie w toku (np. po timeoucie poprzedniej próby)
        $active = $this->queue->findActive($idOrder, self::METHOD_ORDER_ADD);
        if ($active !== null) {
            return ['status' => self::INVOICE_IN_PROGRESS, 'task_id' => (int) $active['id']];
        }

        // 4. agent wystawił dokument, ale nikt nie poczekał na wynik: adopcja numeru
        $done = $this->queue->findLastDone($idOrder, self::METHOD_ORDER_ADD, InfinitySubiektConfig::ADOPT_DAYS);
        if ($done !== null && !empty($done['result_data']['NumerPelny'])) {
            $params = InfinitySubiektJson::decode($done['params']);
            $number = (string) $done['result_data']['NumerPelny'];
            $this->saveInvoice($order, $number, isset($params['document']) ? (string) $params['document'] : null, $done['result_data']);

            return ['status' => self::INVOICE_ADOPTED, 'number' => $number, 'task_id' => (int) $done['id']];
        }

        // 5. payload
        try {
            $params = $this->payload->order($order, $issuer);
        } catch (InfinitySubiektException $e) {
            return ['status' => self::INVOICE_ERROR, 'error' => $e->getMessage()];
        }

        // agent przed 0.4.0 nie zna products_value i wystawiłby dokument bez rabatu zamówienia
        if (isset($params['products_value']) && !$this->agentStatus->atLeast(self::AGENT_DISCOUNT_VERSION)) {
            return ['status' => self::INVOICE_ERROR, 'error' => sprintf(
                'zamówienie ma rabat, a agent %s wystawiłby dokument bez niego: zaktualizuj agenta do %s',
                $this->agentStatus->version(),
                self::AGENT_DISCOUNT_VERSION
            )];
        }

        // 6. wywołanie agenta
        $result = $this->callApi(self::METHOD_ORDER_ADD, $params, InfinitySubiektConfig::TIMEOUT_ADD, $idOrder);

        // 7. sukces wyłącznie po niepustym NumerPelny
        if (!isset($result['error']) && !empty($result['NumerPelny']) && is_string($result['NumerPelny'])) {
            $this->saveInvoice($order, $result['NumerPelny'], $params['document'], $result);

            return ['status' => self::INVOICE_OK, 'number' => $result['NumerPelny']];
        }

        return [
            'status' => self::INVOICE_ERROR,
            'error' => isset($result['error']) ? $result['error'] : 'odpowiedź agenta bez numeru dokumentu',
            'task_id' => isset($result['task_id']) ? (int) $result['task_id'] : null,
        ];
    }

    /**
     * PDF dokumentu zamówienia.
     * Przy $refresh albo braku pliku pobiera PDF od agenta; błąd agenta nigdy nie zwraca starej kopii.
     *
     * @param Order $order
     * @param bool $refresh
     *
     * @return array ['path' => …, 'number' => …] albo klucz `error`
     */
    public function getInvoicePath(Order $order, $refresh)
    {
        if (!Validate::isLoadedObject($order)) {
            return ['error' => 'brak zamówienia'];
        }

        $row = $this->repository->findOrder((int) $order->id);
        $number = $row !== null ? (string) $row['invoice_number'] : '';
        if ($number === '') {
            return ['error' => 'zamówienie nie ma dokumentu'];
        }

        $time = strtotime($order->date_add);
        $dir = _PS_DOWNLOAD_DIR_ . self::PDF_DIR . '/' . date('Y', $time) . '/' . date('m', $time) . '/';
        $path = $dir . (int) $order->id . '.pdf';

        if (!$refresh && is_file($path)) {
            return ['path' => $path, 'number' => $number];
        }

        $result = $this->callApi(self::METHOD_ORDER_PDF, ['number' => $number], InfinitySubiektConfig::TIMEOUT, (int) $order->id);
        if (isset($result['error'])) {
            return $result;
        }

        $content = isset($result['content']) && is_string($result['content'])
            ? base64_decode($result['content'], true)
            : false;
        if ($content === false || strncmp($content, '%PDF', 4) !== 0) {
            return ['error' => 'agent nie zwrócił poprawnego PDF'];
        }

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['error' => 'nie można utworzyć katalogu ' . $dir];
        }

        // zapis przez plik tymczasowy, żeby równoległe pobranie nie dostało połowy pliku
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $content) === false || !@rename($tmp, $path)) {
            @unlink($tmp);

            return ['error' => 'nie można zapisać pliku ' . $path];
        }

        $this->repository->savePdfAt((int) $order->id);

        return ['path' => $path, 'number' => $number];
    }

    /**
     * @param Order $order
     * @param string $number
     * @param string|null $documentType
     * @param array $result wynik order/add (customer_symbol)
     */
    private function saveInvoice(Order $order, $number, $documentType, array $result)
    {
        $this->repository->saveInvoice((int) $order->id, $number, $documentType);

        if (!empty($result['customer_symbol']) && is_string($result['customer_symbol'])) {
            $this->repository->saveCustomerSymbol(
                (int) $order->id_address_invoice,
                (int) $order->id_customer,
                $result['customer_symbol']
            );
        }
    }

    /**
     * Kartoteka towaru: jedna kombinacja albo wszystkie pozycje produktu po kolei.
     *
     * @param int $idProduct
     * @param int|null $idAttr null = wszystkie kombinacje (albo sam produkt bez kombinacji)
     *
     * @return array InfinitySubiektRepository::itemKey() => wynik product/save albo klucz `error`
     */
    public function saveProduct($idProduct, $idAttr = null)
    {
        $items = $idAttr === null
            ? $this->repository->productItems($idProduct)
            : [[(int) $idProduct, (int) $idAttr]];

        $results = [];
        foreach ($items as $item) {
            $results[InfinitySubiektRepository::itemKey($item[0], $item[1])] = $this->agentDown($results)
                ? ['error' => self::ERROR_SKIPPED]
                : $this->sendProduct($item[0], $item[1]);
        }

        return $results;
    }

    /**
     * Po timeoucie kolejne wywołania czekałyby tyle samo (publiczne dla paczek product/info).
     *
     * @param array $results
     *
     * @return bool
     */
    public function agentDown(array $results)
    {
        foreach ($results as $result) {
            if (isset($result['error']) && in_array($result['error'], [self::ERROR_TIMEOUT, self::ERROR_SKIPPED], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param int $idProduct
     * @param int $idAttr
     *
     * @return array
     */
    private function sendProduct($idProduct, $idAttr)
    {
        try {
            $params = $this->payload->product($idProduct, $idAttr);
        } catch (InfinitySubiektException $e) {
            return ['error' => $e->getMessage()];
        }

        $result = $this->callApi('product/save', $params, InfinitySubiektConfig::TIMEOUT);
        if (isset($result['error'])) {
            return $result;
        }
        if (empty($result['code']) || !is_string($result['code'])) {
            return ['error' => 'odpowiedź agenta bez kodu towaru'];
        }

        $saved = $this->repository->saveProduct(
            (int) $idProduct,
            (int) $idAttr,
            $result['code'],
            isset($result['id']) ? (int) $result['id'] : null
        );
        if (!$saved) {
            return ['error' => sprintf('towar %s zapisany w Subiekcie, ale nie w sklepie', $result['code'])];
        }

        return $result;
    }

    /**
     * @param int $idAddress
     * @param array $params
     *
     * @return array
     */
    private function sendCustomer($idAddress, array $params)
    {
        $result = $this->callApi('customer/save', $params, InfinitySubiektConfig::TIMEOUT);
        if (isset($result['error'])) {
            return $result;
        }
        if (empty($result['symbol']) || !is_string($result['symbol'])) {
            return ['error' => 'odpowiedź agenta bez symbolu kontrahenta'];
        }

        $address = new Address((int) $idAddress);
        $saved = $this->repository->saveCustomer(
            (int) $idAddress,
            (int) $address->id_customer,
            $result['symbol'],
            isset($result['id']) ? (int) $result['id'] : null,
            isset($result['matched_by']) && is_string($result['matched_by']) ? $result['matched_by'] : null
        );
        if (!$saved) {
            return ['error' => sprintf('kontrahent %s zapisany w Subiekcie, ale nie w sklepie', $result['symbol'])];
        }

        return $result;
    }
}
