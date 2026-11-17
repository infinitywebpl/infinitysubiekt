<?php
/**
 * Ukryta zakładka dla akcji AJAX z paneli BO.
 * Wywołanie: getAdminLink('AdminInfinitySubiekt') + ajax=1&action=…
 *
 * Token zakładki sprawdza PrestaShop. Uprawnienia do samej zakładki PrestaShop nadaje przy
 * instalacji tylko profilowi instalującego, a ukrytej zakładki nie widać w ekranie uprawnień,
 * więc każda akcja sprawdza prawa do zakładki, której dotyczy (requireAccess).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminInfinitySubiektController extends ModuleAdminController
{
    // strona ustawień modułu: PS 1.6 AdminModules, PS 1.7+ także AdminModulesSf
    const SETTINGS_TABS = ['AdminModules', 'AdminModulesSf'];

    public function __construct()
    {
        $this->bootstrap = true;

        parent::__construct();
    }

    /**
     * Prawa do zakładki modułu zastępuje requireAccess() w każdej akcji.
     *
     * @param bool $disable
     *
     * @return bool
     */
    public function viewAccess($disable = false)
    {
        return true;
    }

    /**
     * Wejście bez AJAX prowadzi na stronę ustawień modułu.
     */
    public function initContent()
    {
        if ($this->ajax) {
            return;
        }

        Tools::redirectAdmin(
            $this->context->link->getAdminLink('AdminModules') . '&configure=' . $this->module->name
        );
    }

    public function ajaxProcessPing()
    {
        $this->requireAccess(self::SETTINGS_TABS, InfinitySubiektAccess::VIEW);

        $result = $this->subiekt()->getClient()->ping();

        $response = ['ok' => !isset($result['error']), 'result' => $result];
        if (isset($result['error']) && $result['error'] === InfinitySubiektClient::ERROR_TIMEOUT) {
            $response['hint'] = (new InfinitySubiektMessages($this->module))->pingTimeoutText($this->subiekt()->getAgentStatus());
        }

        $this->json($response);
    }

    /**
     * Zdalna aktualizacja agenta do wersji z agent/release.json.
     */
    public function ajaxProcessUpdateAgent()
    {
        $this->requireAccess(self::SETTINGS_TABS, InfinitySubiektAccess::EDIT);

        $release = $this->subiekt()->createAgentRelease()->status();
        if (!$release['exists']) {
            $this->json(['ok' => false, 'error' => $this->module->l('Brak agent/release.json na sklepie: podpisz wydanie (agent/tools/release.php) i wgraj katalog agent/.', 'admininfinitysubiektcontroller')]);
        }
        if ($release['changed']) {
            $this->json(['ok' => false, 'error' => $this->module->l('Pliki agenta na sklepie różnią się od podpisanego release.json: podpisz wydanie ponownie i wgraj release.json.', 'admininfinitysubiektcontroller')]);
        }

        $this->agentResult($this->subiekt()->getClient()->updateAgent());
    }

    /**
     * Powrót agenta do poprzedniej wersji.
     */
    public function ajaxProcessRollbackAgent()
    {
        $this->requireAccess(self::SETTINGS_TABS, InfinitySubiektAccess::EDIT);

        $this->agentResult($this->subiekt()->getClient()->rollbackAgent());
    }

    /**
     * Wynik agent/update albo agent/rollback z czytelnym komunikatem dla starego agenta i timeoutu.
     *
     * @param array $result
     */
    private function agentResult(array $result)
    {
        if (isset($result['error'])) {
            $error = (string) $result['error'];
            if ($error === InfinitySubiektClient::ERROR_TIMEOUT) {
                $error = (new InfinitySubiektMessages($this->module))->pingTimeoutText($this->subiekt()->getAgentStatus());
            } elseif (stripos($error, 'unknown method') !== false) {
                $error = $this->module->l('Agent nie obsługuje zdalnej aktualizacji: pierwszy raz wgraj wersję 0.4.0 ręcznie z paczki ZIP.', 'admininfinitysubiektcontroller');
            }
            $this->json(['ok' => false, 'error' => $error]);
        }

        $this->json(['ok' => true, 'result' => $result]);
    }

    /**
     * Pobranie form płatności z Subiekta do selectów mapy płatności (zapis listy, nie mapy).
     */
    public function ajaxProcessPaymentForms()
    {
        $this->requireAccess(self::SETTINGS_TABS, InfinitySubiektAccess::EDIT);

        $result = $this->subiekt()->getClient()->paymentForms();
        if (isset($result['error'])) {
            $error = (string) $result['error'];
            if ($error === InfinitySubiektClient::ERROR_TIMEOUT) {
                $error = (new InfinitySubiektMessages($this->module))->pingTimeoutText($this->subiekt()->getAgentStatus());
            } elseif (stripos($error, 'unknown method') !== false) {
                $error = $this->module->l('Agent nie zna metody payment/forms: zaktualizuj go do wersji 0.4.0.', 'admininfinitysubiektcontroller');
            }
            $this->json(['ok' => false, 'error' => $error]);
        }

        $forms = $this->subiekt()->getPaymentForms();
        $stored = $forms->store($result);
        $this->json([
            'ok' => true,
            'count' => count($stored),
            'fetched_at' => $forms->fetchedAt(),
            'options' => $forms->options(),
        ]);
    }

    /**
     * Pobranie cech towarów z Subiekta do selecta cechy stanów (zapis listy, nie ustawienia).
     */
    public function ajaxProcessStockFeatures()
    {
        $this->requireAccess(self::SETTINGS_TABS, InfinitySubiektAccess::EDIT);

        $result = $this->subiekt()->getClient()->callApi(InfinitySubiektStockFeatures::METHOD);
        if (isset($result['error'])) {
            $error = is_string($result['error']) ? $result['error'] : InfinitySubiektJson::encode($result['error']);
            if ($error === InfinitySubiektClient::ERROR_TIMEOUT) {
                $error = (new InfinitySubiektMessages($this->module))->pingTimeoutText($this->subiekt()->getAgentStatus());
            } elseif (stripos($error, 'unknown method') !== false) {
                $error = $this->module->l('Agent nie zna metody product/features: zaktualizuj go do wersji 0.5.2.', 'admininfinitysubiektcontroller');
            }
            $this->json(['ok' => false, 'error' => $error]);
        }

        $features = $this->subiekt()->getStockFeatures();
        $names = $features->store($result);
        $this->json([
            'ok' => true,
            'count' => count($names),
            'fetched_at' => $features->fetchedAt(),
            'options' => $features->options(),
        ]);
    }

    public function ajaxProcessCronRun()
    {
        $this->requireAccess(self::SETTINGS_TABS, InfinitySubiektAccess::EDIT);

        // przebieg dłuższy niż limit proxy (Cloudflare 524) ma się dokończyć i zapisać log
        @set_time_limit(0);
        ignore_user_abort(true);

        $result = $this->subiekt()->createCron()->run(
            [],
            (bool) Tools::getValue('dry_run'),
            InfinitySubiektCron::SOURCE_BO
        );

        $this->json($result);
    }

    public function ajaxProcessTasks()
    {
        $this->requireAccess(self::SETTINGS_TABS, InfinitySubiektAccess::VIEW);

        $status = (string) Tools::getValue('status');
        $html = $this->subiekt()->createTaskList($this->context)->renderTable($status === '' ? null : $status);

        $this->json(['html' => $html]);
    }

    /**
     * Szczegóły zadania do popupu z listy zadań (ustawienia albo karta zamówienia).
     */
    public function ajaxProcessTaskDetails()
    {
        $idTask = (int) Tools::getValue('id_task');
        $task = $this->subiekt()->getTaskQueue()->find($idTask);

        $tabs = $task !== null && $task['order_id'] ? array_merge(self::SETTINGS_TABS, ['AdminOrders']) : self::SETTINGS_TABS;
        $this->requireAccess($tabs, InfinitySubiektAccess::VIEW);

        if ($task === null) {
            $this->json(['ok' => false, 'error' => sprintf($this->module->l('Nie ma zadania #%d.', 'admininfinitysubiektcontroller'), $idTask)]);
        }

        $this->json(['ok' => true, 'html' => $this->subiekt()->createTaskList($this->context)->renderDetails($task)]);
    }

    /**
     * ZIP z agentem, zbudowany na to żądanie i bez cache (nagłówki w InfinitySubiektResponse).
     */
    public function ajaxProcessDownloadAgent()
    {
        $this->requireAccess(self::SETTINGS_TABS, InfinitySubiektAccess::EDIT);

        $response = new InfinitySubiektResponse();
        $package = $this->subiekt()->createAgentPackage();

        try {
            $path = $package->build();
        } catch (InfinitySubiektException $e) {
            $response->text(500, $this->module->l('Nie udało się przygotować paczki agenta:', 'admininfinitysubiektcontroller') . ' ' . $e->getMessage());
        }

        // Response kończy żądanie przez exit, więc plik tymczasowy usuwamy przy zamknięciu
        register_shutdown_function(static function () use ($path) {
            @unlink($path);
        });

        $response->file($path, $package->filename(), 'application/zip', 'attachment');
    }

    /**
     * Ręczne zakończenie zadania z listy zadań (ustawienia albo karta zamówienia, wtedy z id_order).
     */
    public function ajaxProcessCancelTask()
    {
        $queue = $this->subiekt()->getTaskQueue();
        $idTask = (int) Tools::getValue('id_task');
        $task = $queue->find($idTask);

        $tabs = $task !== null && $task['order_id'] ? array_merge(self::SETTINGS_TABS, ['AdminOrders']) : self::SETTINGS_TABS;
        $this->requireAccess($tabs, InfinitySubiektAccess::EDIT);

        if ($task === null) {
            $this->json(['ok' => false, 'error' => $idTask
                ? sprintf($this->module->l('Nie ma zadania #%d.', 'admininfinitysubiektcontroller'), $idTask)
                : $this->module->l('Żądanie nie zawiera numeru zadania.', 'admininfinitysubiektcontroller')]);
        }

        // błąd pokazujemy pracownikowi zamiast samego HTTP 500: strona musi powiedzieć, co poszło nie tak
        try {
            $employee = $this->context->employee;
            $ok = $queue->cancel((int) $task['id'], sprintf('anulowane ręcznie: %s %s', $employee->firstname, $employee->lastname));

            $response = [
                'ok' => $ok,
                'messages' => [$ok
                    ? sprintf($this->module->l('Zadanie #%d anulowane. Jeśli agent wisi, zrestartuj go na serwerze: nssm restart InfinitySubiektAgent.', 'admininfinitysubiektcontroller'), (int) $task['id'])
                    : sprintf($this->module->l('Zadanie #%d jest już zakończone.', 'admininfinitysubiektcontroller'), (int) $task['id'])],
            ];

            $idOrder = (int) Tools::getValue('id_order');
            if ($idOrder) {
                $response['html'] = $this->subiekt()->createOrderPanel($this->context)->renderBody($idOrder);
            }
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'error' => sprintf('%s (%s:%d)', $e->getMessage(), basename($e->getFile()), $e->getLine())]);
        }

        $this->json($response);
    }

    /**
     * Kontrahent: id_order (nabywca i odbiorca), id_address (jeden adres)
     * albo samo id_customer (wszystkie aktywne adresy klienta).
     */
    public function ajaxProcessSaveCustomer()
    {
        $this->requireAccess(['AdminCustomers', 'AdminOrders'], InfinitySubiektAccess::EDIT);

        $idOrder = (int) Tools::getValue('id_order');
        $idAddress = (int) Tools::getValue('id_address');
        $idCustomer = (int) Tools::getValue('id_customer');
        $client = $this->subiekt()->getClient();
        $panel = $this->subiekt()->createCustomerPanel($this->context);

        if ($idOrder) {
            $results = $client->saveOrderCustomers(new Order($idOrder));
            $html = $this->subiekt()->createOrderPanel($this->context)->renderBody($idOrder);
        } elseif ($idAddress) {
            $results = [$idAddress => $client->saveCustomer($idAddress)];
            $html = $panel->renderCustomerTable($idCustomer ?: (int) (new Address($idAddress))->id_customer);
        } elseif ($idCustomer) {
            $results = $client->saveCustomerAddresses($idCustomer, (int) $this->context->language->id);
            $html = $panel->renderCustomerTable($idCustomer);
        } else {
            $this->json(['ok' => false, 'error' => $this->module->l('Brak adresu.', 'admininfinitysubiektcontroller')]);
        }

        $this->json([
            'ok' => $panel->allOk($results),
            'messages' => $panel->resultMessages($results),
            'html' => $html,
        ]);
    }

    /**
     * Dokument dla zamówienia, z potwierdzeniem.
     */
    public function ajaxProcessMakeInvoice()
    {
        $this->requireAccess(['AdminOrders'], InfinitySubiektAccess::EDIT);

        if (!Tools::getValue('confirm')) {
            $this->json(['ok' => false, 'error' => $this->module->l('Wystawienie dokumentu wymaga potwierdzenia.', 'admininfinitysubiektcontroller')]);
        }

        $idOrder = (int) Tools::getValue('id_order');
        $employee = $this->context->employee;
        $result = $this->subiekt()->getClient()->addInvoice(
            new Order($idOrder),
            trim($employee->firstname . ' ' . $employee->lastname)
        );
        $panel = $this->subiekt()->createOrderPanel($this->context);

        $this->json([
            'ok' => in_array($result['status'], [InfinitySubiektClient::INVOICE_OK, InfinitySubiektClient::INVOICE_ALREADY, InfinitySubiektClient::INVOICE_ADOPTED], true),
            'messages' => [$panel->invoiceMessage($result)],
            'html' => $panel->renderBody($idOrder),
        ]);
    }

    /**
     * Szukanie w Subiekcie dokumentu wystawionego dla zamówienia wcześniej (bez zapisu).
     */
    public function ajaxProcessFindDocument()
    {
        $this->requireAccess(['AdminOrders'], InfinitySubiektAccess::VIEW);

        $order = $this->orderForEmployee();
        $match = $this->subiekt()->createDocumentMatch()->find($order);
        $panel = $this->subiekt()->createOrderPanel($this->context);

        $this->json([
            'ok' => !isset($match['error']),
            'messages' => [$panel->matchMessage($match)],
            'html' => $panel->renderBody((int) $order->id, $match),
        ]);
    }

    /**
     * Przypisanie numeru dokumentu z Subiekta do zamówienia, z potwierdzeniem.
     */
    public function ajaxProcessAssignDocument()
    {
        $this->requireAccess(['AdminOrders'], InfinitySubiektAccess::EDIT);

        if (!Tools::getValue('confirm')) {
            $this->json(['ok' => false, 'error' => $this->module->l('Przypisanie dokumentu wymaga potwierdzenia.', 'admininfinitysubiektcontroller')]);
        }

        $order = $this->orderForEmployee();
        $result = $this->subiekt()->createDocumentMatch()->assign($order, (string) Tools::getValue('number'));
        $panel = $this->subiekt()->createOrderPanel($this->context);

        $this->json([
            'ok' => in_array($result['status'], [InfinitySubiektDocumentMatch::ASSIGN_OK, InfinitySubiektDocumentMatch::ASSIGN_ALREADY], true),
            'messages' => [$panel->assignMessage($result)],
            'html' => $panel->renderBody((int) $order->id),
        ]);
    }

    /**
     * Zamówienie z id_order, do którego pracownik ma dostęp w sklepie; inaczej odpowiedź z błędem.
     *
     * @return Order
     */
    private function orderForEmployee()
    {
        $order = new Order((int) Tools::getValue('id_order'));
        if (!Validate::isLoadedObject($order) || !$this->context->employee->hasAuthOnShop((int) $order->id_shop)) {
            $this->json(['ok' => false, 'error' => $this->module->l('Brak zamówienia.', 'admininfinitysubiektcontroller')], 404);
        }

        return $order;
    }

    /**
     * PDF dokumentu (GET, link „Pobierz PDF” w nowej karcie). Zawsze świeży od agenta:
     * dokument mógł zostać skorygowany w Subiekcie, a przy błędzie agenta nie serwujemy starej kopii.
     */
    public function ajaxProcessDownloadPdf()
    {
        $this->requireAccess(['AdminOrders'], InfinitySubiektAccess::VIEW);

        $response = new InfinitySubiektResponse();
        $order = new Order((int) Tools::getValue('id_order'));
        if (!Validate::isLoadedObject($order) || !$this->context->employee->hasAuthOnShop((int) $order->id_shop)) {
            $response->text(404, $this->module->l('Brak zamówienia.', 'admininfinitysubiektcontroller'));
        }

        $result = $this->subiekt()->getClient()->getInvoicePath($order, true);
        if (isset($result['error'])) {
            $message = (new InfinitySubiektMessages($this->module))->errorText($result);
            $response->text(502, $this->module->l('Nie udało się pobrać PDF:', 'admininfinitysubiektcontroller') . ' ' . $message);
        }

        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', str_replace('/', '_', $result['number'])) . '.pdf';
        $response->file($result['path'], $filename, 'application/pdf');
    }

    /**
     * Kartoteka towaru: id_product + id_product_attribute (jedna pozycja) albo samo id_product (wszystkie).
     */
    public function ajaxProcessSaveProduct()
    {
        $this->requireAccess(['AdminProducts'], InfinitySubiektAccess::EDIT);

        $idProduct = (int) Tools::getValue('id_product');
        $results = $this->subiekt()->getClient()->saveProduct($idProduct, $this->idProductAttribute());
        $panel = $this->subiekt()->createProductPanel($this->context);

        $this->json([
            'ok' => $panel->allOk($results),
            'messages' => $panel->saveMessages($results),
            'html' => $panel->renderTable($idProduct),
        ]);
    }

    public function ajaxProcessPullStock()
    {
        $this->pullProduct(InfinitySubiektProductStock::PULL_STOCK);
    }

    public function ajaxProcessPullPrice()
    {
        $this->pullProduct(InfinitySubiektProductStock::PULL_PRICE);
    }

    /**
     * Stan albo cena zakupu z Subiekta dla jednej pozycji albo wszystkich pozycji produktu.
     *
     * @param string $scope InfinitySubiektProductStock::PULL_*
     */
    private function pullProduct($scope)
    {
        $this->requireAccess(['AdminProducts'], InfinitySubiektAccess::EDIT);

        $idProduct = (int) Tools::getValue('id_product');
        $idAttr = $this->idProductAttribute();
        $items = $idAttr === null
            ? $this->subiekt()->getRepository()->productItems($idProduct)
            : [[$idProduct, $idAttr]];

        $productStock = $this->subiekt()->getProductStock();
        $report = $productStock->pull($items, InfinitySubiektConfig::PRIORITY_PANEL, $scope);

        $this->json([
            'ok' => !$report['errors'],
            'messages' => $productStock->reportLines($report),
            'html' => $this->subiekt()->createProductPanel($this->context)->renderTable($idProduct),
        ]);
    }

    /**
     * Stan sklepu do Subiekta (dokument PW/RW), tylko z potwierdzeniem.
     */
    public function ajaxProcessPushStock()
    {
        $this->requireAccess(['AdminProducts'], InfinitySubiektAccess::EDIT);

        if (!Tools::getValue('confirm')) {
            $this->json(['ok' => false, 'error' => $this->module->l('Wysłanie stanu wymaga potwierdzenia.', 'admininfinitysubiektcontroller')]);
        }

        $idProduct = (int) Tools::getValue('id_product');
        $result = $this->subiekt()->getProductStock()->push($idProduct, (int) $this->idProductAttribute());
        $panel = $this->subiekt()->createProductPanel($this->context);

        $this->json([
            'ok' => !isset($result['error']),
            'messages' => [$panel->pushMessage($result)],
            'html' => $panel->renderTable($idProduct),
        ]);
    }

    /**
     * Params, które poszłyby do agenta, bez tworzenia zadania.
     * type=product: id_product + id_product_attribute; type=order: id_order (order/add).
     * Kontrahent: id_address, a przy id_order z danymi jak w dokumencie (telefon z adresu dostawy).
     */
    public function ajaxProcessPreviewPayload()
    {
        if (Tools::getValue('type') === 'product') {
            $this->previewProduct();
        }
        if (Tools::getValue('type') === 'order') {
            $this->previewOrder();
        }

        $this->requireAccess(['AdminCustomers', 'AdminOrders'], InfinitySubiektAccess::VIEW);

        $idOrder = (int) Tools::getValue('id_order');
        $idAddress = (int) Tools::getValue('id_address');
        $payload = $this->subiekt()->getPayload();

        try {
            if ($idOrder) {
                $parties = $payload->orderParties(new Order($idOrder));
                $params = isset($parties[$idAddress]) ? $parties[$idAddress] : reset($parties);
            } else {
                $params = $payload->customerParty($idAddress);
            }
        } catch (InfinitySubiektException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()]);
        }

        $this->json(['ok' => true, 'method' => 'customer/save', 'payload' => $params]);
    }

    private function previewOrder()
    {
        $this->requireAccess(['AdminOrders'], InfinitySubiektAccess::VIEW);

        $employee = $this->context->employee;
        try {
            $params = $this->subiekt()->getPayload()->order(
                new Order((int) Tools::getValue('id_order')),
                trim($employee->firstname . ' ' . $employee->lastname)
            );
        } catch (InfinitySubiektException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()]);
        }

        $this->json(['ok' => true, 'method' => InfinitySubiektClient::METHOD_ORDER_ADD, 'payload' => $params]);
    }

    private function previewProduct()
    {
        $this->requireAccess(['AdminProducts'], InfinitySubiektAccess::VIEW);

        try {
            $params = $this->subiekt()->getPayload()->product(
                (int) Tools::getValue('id_product'),
                (int) $this->idProductAttribute()
            );
        } catch (InfinitySubiektException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()]);
        }

        $this->json(['ok' => true, 'method' => 'product/save', 'payload' => $params]);
    }

    /**
     * @return int|null null, gdy parametru nie przesłano (= wszystkie pozycje produktu)
     */
    private function idProductAttribute()
    {
        return Tools::getIsset('id_product_attribute') ? (int) Tools::getValue('id_product_attribute') : null;
    }

    /**
     * @return InfinitySubiekt
     */
    private function subiekt()
    {
        return $this->module;
    }

    /**
     * Kończy żądanie z 403, gdy pracownik nie ma prawa do żadnej z zakładek.
     *
     * @param string[] $tabClasses
     * @param string $right
     */
    private function requireAccess(array $tabClasses, $right)
    {
        if (!(new InfinitySubiektAccess($this->context))->canAny($tabClasses, $right)) {
            $this->json(['ok' => false, 'error' => $this->module->l('Brak uprawnień.', 'admininfinitysubiektcontroller')], 403);
        }
    }

    /**
     * @param array $data
     * @param int $code
     */
    private function json(array $data, $code = 200)
    {
        (new InfinitySubiektResponse())->json($code, $data);
    }
}
