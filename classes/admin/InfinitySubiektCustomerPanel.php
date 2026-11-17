<?php
/**
 * Kontrahenci w BO: panel na karcie klienta i sekcja na karcie zamówienia.
 * Render pokazuje tylko dane z bazy; agenta wołają akcje AJAX.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektCustomerPanel
{
    const TRANSLATION_SOURCE = 'infinitysubiektcustomerpanel';

    /** @var Module */
    private $module;

    /** @var InfinitySubiektConfig */
    private $config;

    /** @var InfinitySubiektRepository */
    private $repository;

    /** @var InfinitySubiektMessages */
    private $messages;

    /** @var InfinitySubiektView */
    private $view;

    /** @var Context */
    private $context;

    public function __construct(
        Module $module,
        InfinitySubiektConfig $config,
        InfinitySubiektRepository $repository,
        InfinitySubiektMessages $messages,
        InfinitySubiektView $view,
        Context $context
    ) {
        $this->module = $module;
        $this->config = $config;
        $this->repository = $repository;
        $this->messages = $messages;
        $this->view = $view;
        $this->context = $context;
    }

    /**
     * @param int $idCustomer
     *
     * @return string
     */
    public function renderCustomerPanel($idCustomer)
    {
        return $this->view->render('hook/admin_customer.tpl', $this->panelVars(
            InfinitySubiektCompat::isPs176(),
            ['id_customer' => (int) $idCustomer],
            $this->renderCustomerTable($idCustomer)
        ));
    }

    /**
     * @param int $idCustomer
     *
     * @return string
     */
    public function renderCustomerTable($idCustomer)
    {
        $addresses = [];
        $customer = new Customer((int) $idCustomer);
        if (Validate::isLoadedObject($customer)) {
            foreach ($customer->getAddresses((int) $this->context->language->id) as $row) {
                $address = new Address((int) $row['id_address']);
                $addresses[] = [$address, (string) $address->alias, ['id_address' => (int) $address->id]];
            }
        }

        return $this->renderTable($addresses, true);
    }

    /**
     * Nabywca i (gdy inny adres dostawy) odbiorca; sekcja panelu zamówienia (InfinitySubiektOrderPanel).
     *
     * @param int $idOrder
     *
     * @return string
     */
    public function renderOrderTable($idOrder)
    {
        $addresses = [];
        $order = new Order((int) $idOrder);
        if (Validate::isLoadedObject($order)) {
            $params = ['id_order' => (int) $order->id];
            $addresses[] = [new Address((int) $order->id_address_invoice), $this->l('Nabywca'), $params];
            if ((int) $order->id_address_delivery !== (int) $order->id_address_invoice) {
                $addresses[] = [new Address((int) $order->id_address_delivery), $this->l('Odbiorca'), $params];
            }
        }

        return $this->renderTable($addresses, false);
    }

    /**
     * Komunikaty dla pracownika z wyników InfinitySubiektClient::save…().
     *
     * @param array $results id_address => wynik
     *
     * @return string[]
     */
    public function resultMessages(array $results)
    {
        if (!$results) {
            return [$this->l('Brak adresów do wysłania.')];
        }

        $messages = [];
        foreach ($results as $idAddress => $result) {
            $prefix = $idAddress ? sprintf($this->l('Adres %d:'), (int) $idAddress) . ' ' : '';
            $messages[] = $prefix . (isset($result['error']) ? $this->messages->errorText($result) : $this->successText($result));
        }

        return $messages;
    }

    /**
     * @param array $results
     *
     * @return bool
     */
    public function allOk(array $results)
    {
        return $this->messages->allOk($results);
    }

    /**
     * @param bool $bs4 karta na Symfony (Bootstrap 4)
     * @param array $params parametry akcji AJAX wspólne dla panelu
     * @param string $tableHtml
     *
     * @return array
     */
    private function panelVars($bs4, array $params, $tableHtml)
    {
        return [
            'bs4' => $bs4,
            'ajax_url' => $this->context->link->getAdminLink(InfinitySubiektInstaller::ADMIN_TAB),
            'params_json' => InfinitySubiektJson::encode($params),
            'prefix_missing' => $this->config->get(InfinitySubiektConfig::SYMBOL_PREFIX) === '',
            'customer_mode' => $this->config->get(InfinitySubiektConfig::CUSTOMER_MODE),
            'table_html' => $tableHtml,
        ];
    }

    /**
     * @param array $addresses lista [Address, etykieta, parametry akcji]
     * @param bool $sendPerAddress przycisk „Wyślij” w każdym wierszu
     *
     * @return string
     */
    private function renderTable(array $addresses, $sendPerAddress)
    {
        $ids = [];
        foreach ($addresses as $item) {
            $ids[] = (int) $item[0]->id;
        }
        $synced = $this->repository->findCustomers($ids);

        $rows = [];
        foreach ($addresses as $item) {
            list($address, $label, $params) = $item;
            $id = (int) $address->id;
            $sync = isset($synced[$id]) ? $synced[$id] : null;

            $rows[] = [
                'id_address' => $id,
                'label' => $label,
                'summary' => $this->summary($address),
                'deleted' => (bool) $address->deleted,
                'symbol' => $sync ? $sync['symbol'] : '',
                'matched_by' => $sync ? $this->matchedByText($sync['matched_by']) : '',
                'subiekt_id' => $sync && $sync['subiekt_id'] ? (int) $sync['subiekt_id'] : 0,
                'synced_at' => $sync ? $sync['synced_at'] : '',
                'params_json' => InfinitySubiektJson::encode($params + ['id_address' => $id]),
            ];
        }

        return $this->view->render('hook/customer_table.tpl', [
            'rows' => $rows,
            'send_per_address' => $sendPerAddress,
        ]);
    }

    /**
     * @param Address $address
     *
     * @return string
     */
    private function summary(Address $address)
    {
        if (!Validate::isLoadedObject($address)) {
            return $this->l('brak adresu');
        }

        $parts = [
            trim($address->company . ' ' . $address->firstname . ' ' . $address->lastname),
            trim($address->address1 . ' ' . $address->address2),
            trim($address->postcode . ' ' . $address->city),
            (string) Country::getNameById((int) $this->context->language->id, (int) $address->id_country),
        ];
        if (trim((string) $address->vat_number) !== '') {
            $parts[] = $this->l('NIP') . ' ' . $address->vat_number;
        }

        return implode(', ', array_filter($parts, 'strlen'));
    }

    /**
     * @param string|null $matchedBy
     *
     * @return string
     */
    private function matchedByText($matchedBy)
    {
        switch ((string) $matchedBy) {
            case 'symbol':
                return $this->l('symbol');
            case 'vat_number':
                return $this->l('NIP');
            case 'email':
                return $this->l('e-mail');
            case '':
                return $this->l('nowy');
            default:
                return (string) $matchedBy;
        }
    }

    /**
     * @param array $result
     *
     * @return string
     */
    private function successText(array $result)
    {
        $symbol = (string) $result['symbol'];
        if (!empty($result['created'])) {
            return sprintf($this->l('założono kontrahenta %s.'), $symbol);
        }

        return sprintf(
            $this->l('zaktualizowano kontrahenta %s (dopasowanie: %s).'),
            $symbol,
            $this->matchedByText(isset($result['matched_by']) ? $result['matched_by'] : null)
        );
    }

    /**
     * @param string $string
     *
     * @return string
     */
    private function l($string)
    {
        return $this->module->l($string, self::TRANSLATION_SOURCE);
    }
}
