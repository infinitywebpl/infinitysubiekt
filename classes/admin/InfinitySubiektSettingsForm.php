<?php
/**
 * Formularz ustawień modułu (getContent): definicja pól, walidacja, zapis, generowanie tokenów.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektSettingsForm
{
    const SUBMIT_SAVE = 'submitInfsubiektSettings';
    const SUBMIT_CRON_SAVE = 'submitInfsubiektCronSettings';
    const SUBMIT_AGENT_TOKEN = 'submitInfsubiektAgentToken';
    const SUBMIT_CRON_TOKEN = 'submitInfsubiektCronToken';

    // pole formularza bez wartości: tabela tagów szablonów
    const TAGS_FIELD = 'infsubiekt_doc_tags';
    // selecty mapy płatności: infsubiekt_payment[moduł] = forma
    const PAYMENT_FIELD = 'infsubiekt_payment';
    // pole bez wartości: przycisk pobrania cech z Subiekta
    const FEATURE_FETCH_FIELD = 'infsubiekt_feature_fetch';

    // nazwa pliku małymi literami: klucz tłumaczeń PrestaShop dla stringów z tej klasy
    const TRANSLATION_SOURCE = 'infinitysubiektsettingsform';

    /** @var Module */
    private $module;

    /** @var InfinitySubiektConfig */
    private $config;

    /** @var Context */
    private $context;

    /** @var InfinitySubiektView */
    private $view;

    /** @var InfinitySubiektPaymentForms */
    private $paymentForms;

    /** @var InfinitySubiektStockFeatures */
    private $stockFeatures;

    /** @var array|null wartości z formularza odrzuconego przez walidację */
    private $rejected;

    public function __construct(
        Module $module,
        InfinitySubiektConfig $config,
        Context $context,
        InfinitySubiektView $view,
        InfinitySubiektPaymentForms $paymentForms,
        InfinitySubiektStockFeatures $stockFeatures
    ) {
        $this->module = $module;
        $this->config = $config;
        $this->context = $context;
        $this->view = $view;
        $this->paymentForms = $paymentForms;
        $this->stockFeatures = $stockFeatures;
    }

    /**
     * Obsługa wysłanego formularza.
     *
     * @return string komunikat HTML (pusty, gdy nic nie wysłano)
     */
    public function process()
    {
        if (Tools::isSubmit(self::SUBMIT_AGENT_TOKEN)) {
            $this->config->set(InfinitySubiektConfig::AGENT_TOKEN, InfinitySubiektConfig::generateToken());

            return $this->module->displayConfirmation($this->l('Wygenerowano nowy token agenta. Wpisz go w konfiguracji agenta.'));
        }

        if (Tools::isSubmit(self::SUBMIT_CRON_TOKEN)) {
            $this->config->set(InfinitySubiektConfig::CRON_TOKEN, InfinitySubiektConfig::generateToken());

            return $this->module->displayConfirmation($this->l('Wygenerowano nowy token crona. Zaktualizuj adres w crontabie.'));
        }

        if (Tools::isSubmit(self::SUBMIT_CRON_SAVE)) {
            $cron = [
                InfinitySubiektConfig::CRON_STOCK_PULL => (int) (bool) Tools::getValue(InfinitySubiektConfig::CRON_STOCK_PULL),
                InfinitySubiektConfig::CRON_STOCK_INTERVAL => trim((string) Tools::getValue(InfinitySubiektConfig::CRON_STOCK_INTERVAL)),
                InfinitySubiektConfig::CRON_PRICE_PULL => (int) (bool) Tools::getValue(InfinitySubiektConfig::CRON_PRICE_PULL),
                InfinitySubiektConfig::CRON_PRICE_HOUR => (string) (int) Tools::getValue(InfinitySubiektConfig::CRON_PRICE_HOUR),
            ];
            $errors = [];
            if (!ctype_digit($cron[InfinitySubiektConfig::CRON_STOCK_INTERVAL]) || (int) $cron[InfinitySubiektConfig::CRON_STOCK_INTERVAL] > 1440) {
                $errors[] = $this->l('Stany co ile minut: liczba od 0 do 1440 (0 = przy każdym przebiegu crona).');
            }
            if ((int) $cron[InfinitySubiektConfig::CRON_PRICE_HOUR] < 0 || (int) $cron[InfinitySubiektConfig::CRON_PRICE_HOUR] > 23) {
                $errors[] = $this->l('Godzina cen: od 0 do 23.');
            }

            return $this->save($cron, $errors);
        }

        if (!Tools::isSubmit(self::SUBMIT_SAVE)) {
            return '';
        }

        $values = $this->readSubmitted();

        return $this->save($values, $this->validate($values));
    }

    /**
     * @param array $values klucz konfiguracji => wartość
     * @param string[] $errors
     *
     * @return string komunikat HTML
     */
    private function save(array $values, array $errors)
    {
        if ($errors) {
            $this->rejected = $values;

            return $this->module->displayError(implode('<br>', $errors));
        }

        foreach ($values as $key => $value) {
            $this->config->set($key, $value);
        }

        return $this->module->displayConfirmation($this->l('Ustawienia zapisane.'));
    }

    /**
     * @return string formularz zakładki „Ustawienia”
     */
    public function render()
    {
        return $this->renderForm($this->definitions(), self::SUBMIT_SAVE, InfinitySubiektSettingsPage::TAB_SETTINGS);
    }

    /**
     * @return string formularz zakładki „Cron”
     */
    public function renderCron()
    {
        return $this->renderForm($this->cronDefinitions(), self::SUBMIT_CRON_SAVE, InfinitySubiektSettingsPage::TAB_CRON);
    }

    /**
     * @param array $definitions panele dla HelperForm
     * @param string $submitAction
     * @param string $tab zakładka, na której zostajemy po zapisie
     *
     * @return string
     */
    private function renderForm(array $definitions, $submitAction, $tab)
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this->module;
        // Module::$table i $identifier są protected; to ich wartości z klasy Module.
        $helper->table = 'module';
        $helper->identifier = 'id_module';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->submit_action = $submitAction;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->module->name
            . '&tab_module=' . $this->module->tab
            . '&module_name=' . $this->module->name
            . '&' . InfinitySubiektSettingsPage::TAB_PARAM . '=' . $tab;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->currentValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => (int) $this->context->language->id,
        ];

        return $helper->generateForm($definitions);
    }

    /**
     * @return array klucz konfiguracji => wartość z formularza
     */
    private function readSubmitted()
    {
        return [
            InfinitySubiektConfig::AGENT_TOKEN => trim((string) Tools::getValue(InfinitySubiektConfig::AGENT_TOKEN)),
            InfinitySubiektConfig::CRON_TOKEN => trim((string) Tools::getValue(InfinitySubiektConfig::CRON_TOKEN)),
            InfinitySubiektConfig::DOC_MODE => (string) Tools::getValue(InfinitySubiektConfig::DOC_MODE),
            InfinitySubiektConfig::KSEF => (int) (bool) Tools::getValue(InfinitySubiektConfig::KSEF),
            InfinitySubiektConfig::SYMBOL_PREFIX => trim((string) Tools::getValue(InfinitySubiektConfig::SYMBOL_PREFIX)),
            InfinitySubiektConfig::CUSTOMER_MODE => (string) Tools::getValue(InfinitySubiektConfig::CUSTOMER_MODE),
            InfinitySubiektConfig::CREATE_PRODUCTS => (int) (bool) Tools::getValue(InfinitySubiektConfig::CREATE_PRODUCTS),
            InfinitySubiektConfig::PRODUCT_UNIT => trim((string) Tools::getValue(InfinitySubiektConfig::PRODUCT_UNIT)),
            InfinitySubiektConfig::SHIPPING_MODE => (string) Tools::getValue(InfinitySubiektConfig::SHIPPING_MODE),
            InfinitySubiektConfig::SHIPPING_NAME => trim((string) Tools::getValue(InfinitySubiektConfig::SHIPPING_NAME)),
            InfinitySubiektConfig::SHIPPING_CODE => trim((string) Tools::getValue(InfinitySubiektConfig::SHIPPING_CODE)),
            InfinitySubiektConfig::SHIPPING_VAT => str_replace(',', '.', trim((string) Tools::getValue(InfinitySubiektConfig::SHIPPING_VAT))),
            InfinitySubiektConfig::WAREHOUSE_ID => trim((string) Tools::getValue(InfinitySubiektConfig::WAREHOUSE_ID)),
            InfinitySubiektConfig::PS_WAREHOUSE_ID => (string) (int) Tools::getValue(InfinitySubiektConfig::PS_WAREHOUSE_ID),
            InfinitySubiektConfig::STOCK_SOURCE => (string) Tools::getValue(InfinitySubiektConfig::STOCK_SOURCE),
            InfinitySubiektConfig::PURCHASE_SOURCE => (string) Tools::getValue(InfinitySubiektConfig::PURCHASE_SOURCE),
            InfinitySubiektConfig::STOCK_FEATURE => trim((string) Tools::getValue(InfinitySubiektConfig::STOCK_FEATURE)),
            InfinitySubiektConfig::STOCK_ZERO_MISSING => (int) (bool) Tools::getValue(InfinitySubiektConfig::STOCK_ZERO_MISSING),
            InfinitySubiektConfig::PAYMENT_MAP => InfinitySubiektPaymentForms::mapText(Tools::getValue(self::PAYMENT_FIELD)),
            InfinitySubiektConfig::DOC_SUBTITLE => trim((string) Tools::getValue(InfinitySubiektConfig::DOC_SUBTITLE)),
            InfinitySubiektConfig::DOC_MESSAGE => trim(str_replace("\r\n", "\n", (string) Tools::getValue(InfinitySubiektConfig::DOC_MESSAGE))),
            InfinitySubiektConfig::DOC_ORIGINAL_NUMBER => trim((string) Tools::getValue(InfinitySubiektConfig::DOC_ORIGINAL_NUMBER)),
            InfinitySubiektConfig::MATCH_DAYS => trim((string) Tools::getValue(InfinitySubiektConfig::MATCH_DAYS)),
        ];
    }

    /**
     * @param array $values
     *
     * @return string[]
     */
    private function validate(array $values)
    {
        $errors = array_merge(
            $this->tokenErrors($values[InfinitySubiektConfig::AGENT_TOKEN], $this->l('Token agenta')),
            $this->tokenErrors($values[InfinitySubiektConfig::CRON_TOKEN], $this->l('Token crona'))
        );

        if (!in_array($values[InfinitySubiektConfig::DOC_MODE], InfinitySubiektConfig::docModes(), true)) {
            $errors[] = $this->l('Nieznany rodzaj dokumentu.');
        }

        $prefix = $values[InfinitySubiektConfig::SYMBOL_PREFIX];
        if ($prefix !== '' && !preg_match('/^[A-Za-z0-9_-]{1,10}$/', $prefix)) {
            $errors[] = $this->l('Prefiks symbolu: 1-10 znaków, litery, cyfry, _ lub -.');
        }

        if (!in_array($values[InfinitySubiektConfig::CUSTOMER_MODE], InfinitySubiektConfig::customerModes(), true)) {
            $errors[] = $this->l('Nieznany tryb kontrahenta.');
        }

        $unit = $values[InfinitySubiektConfig::PRODUCT_UNIT];
        if ($unit === '' || Tools::strlen($unit) > 10) {
            $errors[] = $this->l('Jednostka towaru: 1-10 znaków.');
        }
        if (!in_array($values[InfinitySubiektConfig::SHIPPING_MODE], InfinitySubiektConfig::shippingModes(), true)) {
            $errors[] = $this->l('Nieznany rodzaj pozycji wysyłki.');
        }

        if (Tools::strlen($values[InfinitySubiektConfig::SHIPPING_NAME]) > 50) {
            $errors[] = $this->l('Nazwa wysyłki: najwyżej 50 znaków.');
        }

        if (Tools::strlen($values[InfinitySubiektConfig::SHIPPING_CODE]) > 50) {
            $errors[] = $this->l('Kod towaru wysyłki: najwyżej 50 znaków.');
        }

        $vat = $values[InfinitySubiektConfig::SHIPPING_VAT];
        if ($vat !== '' && (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $vat))) {
            $errors[] = $this->l('Stawka VAT wysyłki: liczba od 0 do 99, np. 23 (puste = stawka przewoźnika z zamówienia).');
        }

        $days = $values[InfinitySubiektConfig::MATCH_DAYS];
        if (!ctype_digit($days) || (int) $days > 365) {
            $errors[] = $this->l('Dopasowanie w Subiekcie: liczba dni od 0 do 365.');
        }

        if (!ctype_digit($values[InfinitySubiektConfig::WAREHOUSE_ID])) {
            $errors[] = $this->l('Magazyn: liczba całkowita, 0 = magazyn domyślny agenta.');
        }

        if (!in_array($values[InfinitySubiektConfig::STOCK_SOURCE], InfinitySubiektConfig::stockSources(), true)) {
            $errors[] = $this->l('Nieznane źródło stanu.');
        }

        if (!in_array($values[InfinitySubiektConfig::PURCHASE_SOURCE], InfinitySubiektConfig::purchaseSources(), true)) {
            $errors[] = $this->l('Nieznane źródło ceny zakupu.');
        }

        if (Tools::strlen($values[InfinitySubiektConfig::STOCK_FEATURE]) > 50) {
            $errors[] = $this->l('Cecha towarów: najwyżej 50 znaków, nazwa jak w Subiekcie.');
        }

        $payments = InfinitySubiektConfig::parsePaymentMap($values[InfinitySubiektConfig::PAYMENT_MAP]);
        if ($payments['errors']) {
            // numery linii tekstu mapy → same linie (moduł=forma), bo pracownik widzi selecty, nie tekst
            $lines = preg_split('/\R/u', $values[InfinitySubiektConfig::PAYMENT_MAP]);
            $bad = [];
            foreach ($payments['errors'] as $number) {
                $bad[] = htmlspecialchars(isset($lines[$number - 1]) ? $lines[$number - 1] : (string) $number, ENT_QUOTES, 'UTF-8');
            }
            $errors[] = sprintf(
                $this->l('Płatności: błędne pozycje %s. Pobierz formy płatności z Subiekta ponownie i wybierz formę z listy.'),
                implode(', ', $bad)
            );
        }

        return array_merge($errors, $this->templateErrors($values));
    }

    /**
     * Szablony tekstów dokumentu: tylko znane tagi.
     *
     * @param array $values
     *
     * @return string[]
     */
    private function templateErrors(array $values)
    {
        $errors = [];
        $labels = [
            InfinitySubiektConfig::DOC_SUBTITLE => $this->l('Podtytuł dokumentu'),
            InfinitySubiektConfig::DOC_MESSAGE => $this->l('Uwagi dokumentu'),
            InfinitySubiektConfig::DOC_ORIGINAL_NUMBER => $this->l('Numer oryginału'),
        ];
        foreach ($labels as $key => $label) {
            $unknown = InfinitySubiektDocumentText::unknownTags($values[$key]);
            if ($unknown) {
                $errors[] = sprintf($this->l('%s: nieznane tagi %s.'), $label, '{' . implode('}, {', $unknown) . '}');
            }
        }

        return $errors;
    }

    /**
     * @return string HTML mapy płatności: moduł płatności → select formy w Subiekcie
     */
    private function paymentMapHtml()
    {
        $map = InfinitySubiektConfig::parsePaymentMap($this->currentValues()[InfinitySubiektConfig::PAYMENT_MAP]);
        $used = [];
        foreach ($map['map'] as $entry) {
            $used[] = InfinitySubiektPaymentForms::value($entry);
        }

        return $this->view->render('admin/payment_map.tpl', [
            'rows' => $this->paymentForms->rows($map['map']),
            'hidden' => $this->paymentForms->hidden($map['map']),
            'options' => $this->paymentForms->options($used),
            'fetched_at' => $this->paymentForms->fetchedAt(),
            'forms_count' => count($this->paymentForms->stored()),
            'field' => self::PAYMENT_FIELD,
        ]);
    }

    /**
     * @return string HTML pomocy: tabela tagów z wstawianiem w miejscu kursora
     */
    private function tagsHelp()
    {
        $tags = [];
        foreach ($this->tagLabels() as $tag => $label) {
            $tags[] = ['code' => '{' . $tag . '}', 'label' => $label];
        }

        return $this->view->render('admin/document_tags.tpl', [
            'tags' => $tags,
            'fields' => implode(',', [
                InfinitySubiektConfig::DOC_SUBTITLE,
                InfinitySubiektConfig::DOC_MESSAGE,
                InfinitySubiektConfig::DOC_ORIGINAL_NUMBER,
            ]),
            'subtitle_length' => InfinitySubiektDocumentText::SUBTITLE_LENGTH,
            'original_length' => InfinitySubiektDocumentText::ORIGINAL_NUMBER_LENGTH,
        ]);
    }

    /**
     * @return array tag => opis, w kolejności InfinitySubiektDocumentText::tags()
     */
    private function tagLabels()
    {
        $names = [
            'id_order' => $this->l('id zamówienia'),
            'reference' => $this->l('referencja zamówienia'),
            'date' => $this->l('data zamówienia (dd.mm.rrrr)'),
            'total_paid' => $this->l('kwota zamówienia brutto'),
            'total_shipping' => $this->l('koszt wysyłki brutto'),
            'currency' => $this->l('kod waluty'),
            'payment' => $this->l('forma płatności z zamówienia'),
            'carrier' => $this->l('przewoźnik'),
            'shop_name' => $this->l('nazwa sklepu'),
            'shop_domain' => $this->l('domena sklepu (przy kilku sklepach w jednym Subiekcie)'),
        ];
        $labels = [];
        foreach (InfinitySubiektDocumentText::tags() as $tag) {
            $labels[$tag] = isset($names[$tag]) ? $names[$tag] : '';
        }

        return $labels;
    }

    /**
     * @param string $token
     * @param string $label
     *
     * @return string[]
     */
    private function tokenErrors($token, $label)
    {
        if ($token === '' || preg_match('/^[A-Za-z0-9]{16,128}$/', $token)) {
            return [];
        }

        return [sprintf($this->l('%s: 16-128 znaków, tylko litery i cyfry (pusty = wyłączony).'), $label)];
    }

    /**
     * Po błędzie walidacji formularz pokazuje wysłane wartości, inaczej zapisane
     * (także po wygenerowaniu tokenu, gdy POST niesie jeszcze stary token).
     *
     * @return array
     */
    private function currentValues()
    {
        $values = [];
        foreach (array_keys($this->config->defaults()) as $key) {
            $values[$key] = $this->rejected !== null && array_key_exists($key, $this->rejected)
                ? $this->rejected[$key]
                : $this->config->get($key);
        }
        $values[self::TAGS_FIELD] = '';
        $values[self::PAYMENT_FIELD] = '';
        $values[self::FEATURE_FETCH_FIELD] = '';

        return $values;
    }

    /**
     * Panele formularza. Jeden <form>: każdy przycisk „Zapisz” zapisuje wszystkie panele.
     *
     * @return array definicje dla HelperForm
     */
    private function definitions()
    {
        $yesNo = $this->yesNo();

        return [
            $this->panel($this->l('Połączenie'), 'icon-link', [
                [
                    'type' => 'text',
                    'name' => InfinitySubiektConfig::AGENT_TOKEN,
                    'label' => $this->l('Token agenta'),
                    'desc' => $this->l('Nagłówek X-Agent-Token. Pusty = endpoint agenta wyłączony (403).'),
                ],
                [
                    'type' => 'text',
                    'name' => InfinitySubiektConfig::CRON_TOKEN,
                    'label' => $this->l('Token crona'),
                    'desc' => $this->l('Wymagany przy wywołaniu crona przez HTTP (wget). Pusty = wywołanie HTTP wyłączone.'),
                ],
            ], [
                [
                    'type' => 'submit',
                    'name' => self::SUBMIT_AGENT_TOKEN,
                    'title' => $this->l('Generuj token agenta'),
                    'icon' => 'process-icon-refresh',
                ],
                [
                    'type' => 'submit',
                    'name' => self::SUBMIT_CRON_TOKEN,
                    'title' => $this->l('Generuj token crona'),
                    'icon' => 'process-icon-refresh',
                ],
            ]),
            $this->panel($this->l('Dokument sprzedaży'), 'icon-file-text', [
                [
                    'type' => 'select',
                    'name' => InfinitySubiektConfig::DOC_MODE,
                    'label' => $this->l('Rodzaj dokumentu'),
                    'options' => [
                        'query' => [
                            ['id' => InfinitySubiektConfig::DOC_MODE_INVOICE, 'name' => $this->l('Zawsze faktura')],
                            ['id' => InfinitySubiektConfig::DOC_MODE_RECEIPT, 'name' => $this->l('Zawsze paragon')],
                            ['id' => InfinitySubiektConfig::DOC_MODE_INVOICE_IF_NIP, 'name' => $this->l('Faktura, gdy adres do faktury ma NIP, inaczej paragon')],
                            ['id' => InfinitySubiektConfig::DOC_MODE_ORDER, 'name' => $this->l('Zawsze zamówienie od klienta (ZK)')],
                            ['id' => InfinitySubiektConfig::DOC_MODE_ORDER_ADVANCE, 'name' => $this->l('Zawsze zamówienie zaliczkowe (ZKzal)')],
                        ],
                        'id' => 'id',
                        'name' => 'name',
                    ],
                ],
                [
                    'type' => 'text',
                    'name' => InfinitySubiektConfig::MATCH_DAYS,
                    'label' => $this->l('Dopasowanie w Subiekcie: dni po zamówieniu'),
                    'class' => 'fixed-width-sm',
                    'desc' => $this->l('Przycisk „Dopasuj w Subiekcie” na karcie zamówienia szuka dokumentu rodzaju jak wyżej, wystawionego od dnia przed zamówieniem do tylu dni po nim.'),
                ],
                [
                    'type' => 'switch',
                    'name' => InfinitySubiektConfig::KSEF,
                    'label' => $this->l('Wysyłaj do KSeF'),
                    'is_bool' => true,
                    'values' => $yesNo,
                ],
                [
                    'type' => 'html',
                    'name' => self::PAYMENT_FIELD,
                    'label' => $this->l('Płatności w dokumencie'),
                    'html_content' => $this->paymentMapHtml(),
                ],
            ]),
            $this->panel($this->l('Teksty dokumentu'), 'icon-font', [
                [
                    'type' => 'text',
                    'name' => InfinitySubiektConfig::DOC_SUBTITLE,
                    'label' => $this->l('Podtytuł dokumentu'),
                ],
                [
                    'type' => 'textarea',
                    'name' => InfinitySubiektConfig::DOC_MESSAGE,
                    'label' => $this->l('Uwagi dokumentu'),
                    'rows' => 4,
                ],
                [
                    'type' => 'text',
                    'name' => InfinitySubiektConfig::DOC_ORIGINAL_NUMBER,
                    'label' => $this->l('Numer oryginału'),
                ],
                [
                    'type' => 'html',
                    'name' => self::TAGS_FIELD,
                    'label' => $this->l('Tagi'),
                    'html_content' => $this->tagsHelp(),
                ],
            ]),
            $this->panel($this->l('Wysyłka w dokumencie'), 'icon-truck', [
                [
                    'type' => 'select',
                    'name' => InfinitySubiektConfig::SHIPPING_MODE,
                    'label' => $this->l('Wysyłka w dokumencie'),
                    'desc' => $this->l('Puste pole wybranego rodzaju (nazwa albo kod) = koszt dostawy nie trafia do dokumentu, więc dokument jest o tę kwotę niższy niż zamówienie.'),
                    'options' => [
                        'query' => [
                            ['id' => InfinitySubiektConfig::SHIPPING_MODE_ONE_TIME, 'name' => $this->l('Usługa jednorazowa (bez kartoteki w Subiekcie)')],
                            ['id' => InfinitySubiektConfig::SHIPPING_MODE_PRODUCT, 'name' => $this->l('Zwykły towar z kartoteki Subiekta')],
                        ],
                        'id' => 'id',
                        'name' => 'name',
                    ],
                ],
                [
                    'type' => 'text',
                    'name' => InfinitySubiektConfig::SHIPPING_NAME,
                    'label' => $this->l('Nazwa wysyłki'),
                    'desc' => $this->l('Usługa jednorazowa: nazwa pozycji na dokumencie. Zwykły towar: nazwa używana tylko przy zakładaniu brakującego towaru.'),
                ],
                [
                    'type' => 'text',
                    'name' => InfinitySubiektConfig::SHIPPING_VAT,
                    'label' => $this->l('Stawka VAT wysyłki (%)'),
                    'class' => 'fixed-width-sm',
                    'desc' => $this->l('Tylko dla usługi jednorazowej. Puste = stawka przewoźnika z zamówienia. Stawka musi istnieć w Subiekcie.'),
                ],
                [
                    'type' => 'text',
                    'name' => InfinitySubiektConfig::SHIPPING_CODE,
                    'label' => $this->l('Kod towaru wysyłki'),
                    'desc' => $this->l('Tylko dla zwykłego towaru: symbol w Subiekcie. Stawkę VAT bierze Subiekt z kartoteki towaru.'),
                ],
            ]),
            $this->panel($this->l('Kontrahenci'), 'icon-user', [
                [
                    'type' => 'text',
                    'name' => InfinitySubiektConfig::SYMBOL_PREFIX,
                    'label' => $this->l('Prefiks symbolu kontrahenta'),
                    'desc' => $this->l('Symbol kontrahenta: {prefiks} {id klienta} {id adresu}. Zmiana po pierwszych synchronizacjach tworzy w Subiekcie duplikaty kontrahentów.'),
                ],
                [
                    'type' => 'select',
                    'name' => InfinitySubiektConfig::CUSTOMER_MODE,
                    'label' => $this->l('Kontrahent'),
                    'options' => [
                        'query' => [
                            ['id' => InfinitySubiektConfig::CUSTOMER_MODE_SYMBOL, 'name' => $this->l('Zawsze własny symbol (zakładaj lub aktualizuj po symbolu)')],
                            ['id' => InfinitySubiektConfig::CUSTOMER_MODE_MATCH, 'name' => $this->l('Dopasuj istniejącego (symbol, NIP, e-mail), inaczej załóż')],
                        ],
                        'id' => 'id',
                        'name' => 'name',
                    ],
                ],
            ]),
            $this->panel($this->l('Towary'), 'icon-barcode', [
                [
                    'type' => 'switch',
                    'name' => InfinitySubiektConfig::CREATE_PRODUCTS,
                    'label' => $this->l('Zakładaj brakujące towary przy wystawianiu'),
                    'is_bool' => true,
                    'values' => $yesNo,
                ],
                [
                    'type' => 'text',
                    'name' => InfinitySubiektConfig::PRODUCT_UNIT,
                    'label' => $this->l('Jednostka towaru'),
                    'class' => 'fixed-width-sm',
                ],
            ]),
            $this->panel($this->l('Stany'), 'icon-archive', [
                [
                    'type' => 'text',
                    'name' => InfinitySubiektConfig::WAREHOUSE_ID,
                    'label' => $this->l('Magazyn Subiekta (id)'),
                    'class' => 'fixed-width-sm',
                    'desc' => $this->l('Magazyn dla stanów towarów. 0 = magazyn domyślny agenta.'),
                ],
                [
                    'type' => 'select',
                    'name' => InfinitySubiektConfig::PS_WAREHOUSE_ID,
                    'label' => $this->l('Magazyn sklepu (zaawansowane zarządzanie magazynem)'),
                    'desc' => $this->l('Tu trafia stan pobrany z Subiekta dla produktów z zaawansowanym zarządzaniem magazynem, jako ruch magazynowy na różnicę. Bez wybranego magazynu takie produkty są pomijane.'),
                    'options' => [
                        'query' => $this->shopWarehouses(),
                        'id' => 'id',
                        'name' => 'name',
                    ],
                ],
                [
                    'type' => 'select',
                    'name' => InfinitySubiektConfig::STOCK_FEATURE,
                    'label' => $this->l('Cecha towarów do pobierania stanów (opcjonalnie)'),
                    'options' => [
                        'query' => $this->stockFeatures->options($this->currentValues()[InfinitySubiektConfig::STOCK_FEATURE]),
                        'id' => 'value',
                        'name' => 'label',
                    ],
                    'desc' => $this->l('Puste = cron pobiera stany towarów ze stanem większym od 0 w magazynie Subiekta. Nazwa cechy (jak w Subiekcie, wielkość liter bez znaczenia) = tylko towary z tą cechą. Dry-run porównuje obie listy.'),
                ],
                [
                    'type' => 'html',
                    'name' => self::FEATURE_FETCH_FIELD,
                    'label' => '',
                    'html_content' => $this->view->render('admin/stock_features.tpl', [
                        'fetched_at' => $this->stockFeatures->fetchedAt(),
                        'count' => count($this->stockFeatures->stored()),
                    ]),
                ],
                [
                    'type' => 'switch',
                    'name' => InfinitySubiektConfig::STOCK_ZERO_MISSING,
                    'label' => $this->l('Zeruj magazyn sklepu dla pozycji spoza listy'),
                    'is_bool' => true,
                    'values' => $this->yesNo(),
                    'desc' => $this->l('Po pełnym pobraniu stanów cron ustawia 0 w magazynie sklepu (wyżej) pozycjom ze stanem, których nie dopasował do towaru z listy: po EAN albo po referencji równej symbolowi. Sprawdź kody przed włączeniem. Działa tylko z wybranym magazynem sklepu.'),
                ],
                [
                    'type' => 'select',
                    'name' => InfinitySubiektConfig::STOCK_SOURCE,
                    'label' => $this->l('Stan pobierany do sklepu'),
                    'options' => [
                        'query' => [
                            ['id' => InfinitySubiektConfig::STOCK_SOURCE_AVAILABLE, 'name' => $this->l('Dostępny (stan minus rezerwacje)')],
                            ['id' => InfinitySubiektConfig::STOCK_SOURCE_STOCK, 'name' => $this->l('Stan magazynowy')],
                        ],
                        'id' => 'id',
                        'name' => 'name',
                    ],
                ],
            ]),
            $this->panel($this->l('Ceny zakupu'), 'icon-money', [
                [
                    'type' => 'select',
                    'name' => InfinitySubiektConfig::PURCHASE_SOURCE,
                    'label' => $this->l('Cena zakupu'),
                    'desc' => $this->l('Która cena zakupu z Subiekta trafia do ceny hurtowej produktu lub kombinacji.'),
                    'options' => [
                        'query' => [
                            ['id' => InfinitySubiektConfig::PURCHASE_SOURCE_CARD, 'name' => $this->l('Cena kartotekowa')],
                            ['id' => InfinitySubiektConfig::PURCHASE_SOURCE_LAST_DELIVERY, 'name' => $this->l('Cena z ostatniej dostawy')],
                            ['id' => InfinitySubiektConfig::PURCHASE_SOURCE_WEIGHTED_AVG, 'name' => $this->l('Średnia ważona cena z istniejących dostaw')],
                            ['id' => InfinitySubiektConfig::PURCHASE_SOURCE_LAST_PURCHASE, 'name' => $this->l('Ostatnia cena zakupu')],
                        ],
                        'id' => 'id',
                        'name' => 'name',
                    ],
                ],
            ]),
        ];
    }

    /**
     * @return array definicja dla HelperForm (zakładka „Cron”)
     */
    private function cronDefinitions()
    {
        return [
            $this->panel($this->l('Ustawienia crona'), 'icon-cogs', [
                [
                    'type' => 'switch',
                    'name' => InfinitySubiektConfig::CRON_STOCK_PULL,
                    'label' => $this->l('Pobieraj stany z Subiekta do sklepu'),
                    'is_bool' => true,
                    'values' => $this->yesNo(),
                    'desc' => $this->l('Dla towarów ze stanem w Subiekcie albo z cechą z zakładki Ustawienia (tam też magazyny i zerowanie).'),
                ],
                [
                    'type' => 'text',
                    'name' => InfinitySubiektConfig::CRON_STOCK_INTERVAL,
                    'label' => $this->l('Stany co ile minut'),
                    'class' => 'fixed-width-sm',
                    'suffix' => 'min',
                    'desc' => $this->l('Cron może działać częściej (np. co 5 minut): stany pobiera dopiero po tym czasie od poprzedniego pobrania. 0 = przy każdym przebiegu.'),
                ],
                [
                    'type' => 'switch',
                    'name' => InfinitySubiektConfig::CRON_PRICE_PULL,
                    'label' => $this->l('Pobieraj ceny zakupu z Subiekta do sklepu'),
                    'is_bool' => true,
                    'values' => $this->yesNo(),
                    'desc' => $this->l('Raz na dobę, dla towarów z nowymi dokumentami zakupu (FZ, PZ), które są w sklepie. Pierwszy przebieg bierze wszystkie kupowane towary i trwa długo. Źródło ceny ustawia się w zakładce Ustawienia.'),
                ],
                [
                    'type' => 'select',
                    'name' => InfinitySubiektConfig::CRON_PRICE_HOUR,
                    'label' => $this->l('Ceny zakupu od godziny'),
                    'desc' => $this->l('Pierwszy przebieg crona od tej godziny pobiera ceny, raz na dobę. Najlepiej w nocy.'),
                    'options' => [
                        'query' => $this->hours(),
                        'id' => 'id',
                        'name' => 'name',
                    ],
                ],
            ]),
        ];
    }

    /**
     * @return array opcje godzin 0-23
     */
    private function hours()
    {
        $hours = [];
        for ($hour = 0; $hour < 24; ++$hour) {
            $hours[] = ['id' => $hour, 'name' => sprintf('%02d:00', $hour)];
        }

        return $hours;
    }

    /**
     * @return array wartości przełącznika dla HelperForm
     */
    private function yesNo()
    {
        return [
            ['id' => 'on', 'value' => 1, 'label' => $this->l('Tak')],
            ['id' => 'off', 'value' => 0, 'label' => $this->l('Nie')],
        ];
    }

    /**
     * @return array opcje listy magazynów sklepu
     */
    private function shopWarehouses()
    {
        $options = [['id' => 0, 'name' => $this->l('Nie zapisuj stanu takich produktów')]];
        if (class_exists('Warehouse')) {
            foreach (Warehouse::getWarehouses(true) as $warehouse) {
                $options[] = ['id' => (int) $warehouse['id_warehouse'], 'name' => $warehouse['name']];
            }
        }

        return $options;
    }

    /**
     * @param string $title
     * @param string $icon
     * @param array $inputs
     * @param array $buttons
     *
     * @return array
     */
    private function panel($title, $icon, array $inputs, array $buttons = [])
    {
        return [
            'form' => [
                'legend' => ['title' => $title, 'icon' => $icon],
                'input' => $inputs,
                'submit' => ['title' => $this->l('Zapisz')],
                'buttons' => $buttons,
            ],
        ];
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
