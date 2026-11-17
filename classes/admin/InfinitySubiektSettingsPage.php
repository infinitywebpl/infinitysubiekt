<?php
/**
 * Strona konfiguracji modułu (getContent) w zakładkach: agent, cron, ustawienia, kolejka, wiedza dla AI, licencja.
 *
 * Zakładka to link z parametrem TAB_PARAM (osobne wczytanie strony); treść i dane liczone są
 * tylko dla otwartej zakładki.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektSettingsPage
{
    const TAB_PARAM = 'infsubiekt_tab';

    const TAB_AGENT = 'agent';
    const TAB_CRON = 'cron';
    const TAB_SETTINGS = 'settings';
    const TAB_TASKS = 'tasks';
    const TAB_NOTES = 'notes';
    const TAB_LICENSE = 'license';

    // zakładka => ikona, w kolejności paska
    const TABS = [
        self::TAB_AGENT => 'icon-exchange',
        self::TAB_CRON => 'icon-time',
        self::TAB_SETTINGS => 'icon-cogs',
        self::TAB_TASKS => 'icon-tasks',
        self::TAB_NOTES => 'icon-lightbulb',
        self::TAB_LICENSE => 'icon-flag',
    ];

    const TRANSLATION_SOURCE = 'infinitysubiektsettingspage';

    /** @var Module */
    private $module;

    /** @var InfinitySubiektConfig */
    private $config;

    /** @var InfinitySubiektAgentStatus */
    private $agentStatus;

    /** @var InfinitySubiektTaskList */
    private $taskList;

    /** @var InfinitySubiektAgentPackage */
    private $agentPackage;

    /** @var InfinitySubiektView */
    private $view;

    /** @var Context */
    private $context;

    public function __construct(
        Module $module,
        InfinitySubiektConfig $config,
        InfinitySubiektAgentStatus $agentStatus,
        InfinitySubiektTaskList $taskList,
        InfinitySubiektAgentPackage $agentPackage,
        InfinitySubiektView $view,
        Context $context
    ) {
        $this->module = $module;
        $this->config = $config;
        $this->agentStatus = $agentStatus;
        $this->taskList = $taskList;
        $this->agentPackage = $agentPackage;
        $this->view = $view;
        $this->context = $context;
    }

    /**
     * Adres zakładki strony konfiguracji (także akcja formularza ustawień).
     *
     * @param Module $module
     * @param string $tab
     * @param bool $withToken
     *
     * @return string
     */
    public static function tabUrl(Module $module, $tab, $withToken = true)
    {
        return AdminController::$currentIndex . '&configure=' . $module->name
            . ($withToken ? '&token=' . Tools::getAdminTokenLite('AdminModules') : '')
            . '&' . self::TAB_PARAM . '=' . $tab;
    }

    /**
     * @return string
     */
    public function render()
    {
        // najpierw zapis formularza, żeby zakładki pokazały np. nowy token
        $form = new InfinitySubiektSettingsForm(
            $this->module,
            $this->config,
            $this->context,
            $this->view,
            $this->module->getPaymentForms(),
            $this->module->getStockFeatures()
        );
        $message = $form->process();

        $this->context->controller->addJS($this->module->getPathUri() . 'views/js/settings.js');
        $this->context->controller->addJS($this->module->getPathUri() . 'views/js/tasks.js');

        $active = $this->currentTab();
        $licensePage = new InfinitySubiektLicensePage($this->module, $this->view, $this->context);

        switch ($active) {
            case self::TAB_CRON:
                $content = $this->view->render('admin/tab_cron.tpl', [
                    'cron' => $this->cronVars(),
                    'form_html' => $form->renderCron(),
                ]);
                break;
            case self::TAB_SETTINGS:
                $content = $form->render();
                break;
            case self::TAB_TASKS:
                $content = $this->taskList->renderPanel();
                break;
            case self::TAB_NOTES:
                $notesPage = new InfinitySubiektNotesPage($this->module, $this->module->getQueryNotes(), $this->view, $this->context);
                $message .= $notesPage->process();
                $content = $notesPage->render();
                break;
            case self::TAB_LICENSE:
                $content = $licensePage->render();
                break;
            default:
                $release = $this->module->createAgentRelease()->status();
                $content = $this->view->render('admin/tab_agent.tpl', [
                    'online' => $this->agentStatus->isOnline(),
                    'last_seen' => $this->agentStatus->lastSeen(),
                    'token_set' => $this->config->get(InfinitySubiektConfig::AGENT_TOKEN) !== '',
                    'endpoint_url' => $this->endpointUrl(),
                    'poll_wait' => InfinitySubiektConfig::POLL_WAIT,
                    'settings_url' => self::tabUrl($this->module, self::TAB_SETTINGS),
                    'agent_package' => $this->agentPackage->info(),
                    'agent_version' => $this->agentStatus->version(),
                    'agent_release' => $release,
                    'agent_release_changed' => implode(', ', array_slice($release['changed'], 0, 10))
                        . (count($release['changed']) > 10 ? ' …' : ''),
                    // inny adres przy każdym wczytaniu: pośrednik nie ma czego podać z cache
                    'agent_download_url' => $this->context->link->getAdminLink(InfinitySubiektInstaller::ADMIN_TAB)
                        . '&ajax=1&action=downloadAgent&_=' . time(),
                ]);
        }

        return $this->view->render('admin/settings.tpl', [
            'message_html' => $message,
            'ajax_url' => $this->context->link->getAdminLink(InfinitySubiektInstaller::ADMIN_TAB),
            'tabs' => $this->tabs(),
            'active_tab' => $active,
            'tab_content' => $content,
            // wersja demo: komunikat w miejscu paska licencji wersji pełnej
            'demo_notice_html' => $active === self::TAB_LICENSE ? '' : $licensePage->renderDemoNotice(),
        ]);
    }

    /**
     * Formularz ustawień wysyła się na adres swojej zakładki, więc po zapisie zostajemy w niej.
     *
     * @return string
     */
    private function currentTab()
    {
        $tab = (string) Tools::getValue(self::TAB_PARAM);

        return isset(self::TABS[$tab]) ? $tab : self::TAB_AGENT;
    }

    /**
     * @return array klucz => label, icon, url, badge, badge_class
     */
    private function tabs()
    {
        $labels = [
            self::TAB_AGENT => $this->l('Agent'),
            self::TAB_CRON => $this->l('Cron'),
            self::TAB_SETTINGS => $this->l('Ustawienia'),
            self::TAB_TASKS => $this->l('Kolejka zadań'),
            self::TAB_NOTES => $this->l('Wiedza dla AI'),
            self::TAB_LICENSE => $this->l('Licencja'),
        ];

        // plakietki z tanich odczytów ustawień, liczone dla każdej zakładki
        $badges = [
            self::TAB_AGENT => $this->agentStatus->isOnline()
                ? [$this->l('online'), 'success']
                : [$this->l('offline'), 'danger'],
            self::TAB_CRON => $this->cronStale() ? [$this->l('nie działa'), 'warning'] : null,
            self::TAB_SETTINGS => $this->config->get(InfinitySubiektConfig::SYMBOL_PREFIX) === ''
                ? [$this->l('brak prefiksu'), 'warning']
                : null,
        ];

        $tabs = [];
        foreach (self::TABS as $key => $icon) {
            $tabs[$key] = [
                'label' => $labels[$key],
                'icon' => $icon,
                'url' => self::tabUrl($this->module, $key),
                'badge' => isset($badges[$key]) ? $badges[$key][0] : '',
                'badge_class' => isset($badges[$key]) ? $badges[$key][1] : '',
            ];
        }

        return $tabs;
    }

    /**
     * @return array
     */
    private function cronVars()
    {
        $token = $this->config->get(InfinitySubiektConfig::CRON_TOKEN);

        return [
            'http_url' => $token === '' ? '' : $this->context->link->getModuleLink(
                $this->module->name,
                'cron',
                ['token' => $token],
                true,
                null,
                (int) Configuration::get('PS_SHOP_DEFAULT')
            ),
            'cli_path' => _PS_MODULE_DIR_ . $this->module->name . '/cron.php',
            'jobs' => implode(', ', InfinitySubiektCron::jobNames()),
            'last_run' => $this->config->get(InfinitySubiektConfig::CRON_LAST_RUN),
            'stale' => $this->cronStale(),
            'stale_minutes' => InfinitySubiektConfig::CRON_STALE,
            'log' => $this->config->get(InfinitySubiektConfig::CRON_LOG),
            'job_rows' => $this->cronJobRows(),
            'settings_url' => self::tabUrl($this->module, self::TAB_SETTINGS),
        ];
    }

    /**
     * Opis zadań crona z bieżącymi parametrami i stanem przełącznika.
     *
     * @return array lista ['name', 'what', 'when', 'off']
     */
    private function cronJobRows()
    {
        $stockPull = $this->config->getBool(InfinitySubiektConfig::CRON_STOCK_PULL);
        $pricePull = $this->config->getBool(InfinitySubiektConfig::CRON_PRICE_PULL);

        return [
            [
                'name' => 'maintenance',
                'what' => sprintf(
                    $this->l('Porządkuje kolejkę: zadania wiszące w stanie processing dłużej niż %d min oddaje do ponownego wykonania, a po %d próbach oznacza jako failed. Zadania czekające dłużej niż %d min oznacza jako expired.'),
                    InfinitySubiektConfig::STUCK_AFTER,
                    InfinitySubiektConfig::MAX_ATTEMPTS,
                    InfinitySubiektConfig::TASK_TTL
                ),
                'when' => $this->l('przy każdym przebiegu'),
                'off' => false,
            ],
            [
                'name' => 'cleanup',
                'what' => sprintf($this->l('Usuwa z kolejki zadania starsze niż %d dni.'), InfinitySubiektConfig::CLEANUP_DAYS),
                'when' => $this->l('raz na dobę'),
                'off' => false,
            ],
            [
                'name' => 'stock_pull',
                'what' => sprintf(
                    $this->l('Pobiera przez agenta listę: %s, ze stanami z Subiekta i zapisuje stan dopasowanych pozycji sklepu (po EAN, potem po referencji równej symbolowi). Produkty z zaawansowanym zarządzaniem magazynem trafiają do magazynu sklepu z zakładki Ustawienia.'),
                    trim($this->config->get(InfinitySubiektConfig::STOCK_FEATURE)) === ''
                        ? $this->l('towary ze stanem większym od 0')
                        : sprintf($this->l('towary z cechą „%s”'), $this->config->get(InfinitySubiektConfig::STOCK_FEATURE))
                ) . ' ' . ($this->config->getBool(InfinitySubiektConfig::STOCK_ZERO_MISSING)
                    ? $this->l('Po pełnej liście pozycje magazynu sklepu ze stanem spoza listy dostają stan 0.')
                    : $this->l('Bez zerowania pozycji spoza listy.')),
                'when' => !$stockPull
                    ? $this->l('wyłączone, włączysz niżej')
                    : ((int) $this->config->get(InfinitySubiektConfig::CRON_STOCK_INTERVAL) > 0
                        ? sprintf($this->l('co %d min'), (int) $this->config->get(InfinitySubiektConfig::CRON_STOCK_INTERVAL))
                        : $this->l('przy każdym przebiegu')),
                'off' => !$stockPull,
            ],
            [
                'name' => 'price_pull',
                'what' => sprintf(
                    $this->l('Pobiera przez agenta listę towarów z dokumentami zakupu od ostatniego przebiegu (z zapasem %d dni), dopasowuje je do sklepu i tylko dla dopasowanych wczytuje cenę zakupu do ceny hurtowej. Pierwszy przebieg bierze wszystkie kupowane towary. Zestawów i stanów nie zmienia.'),
                    InfinitySubiektConfig::PRICE_PULL_OVERLAP
                ),
                'when' => $pricePull
                    ? sprintf($this->l('raz na dobę od %02d:00'), (int) $this->config->get(InfinitySubiektConfig::CRON_PRICE_HOUR))
                    : $this->l('wyłączone, włączysz niżej'),
                'off' => !$pricePull,
            ],
            [
                'name' => 'price_pull_full',
                'what' => $this->l('Ceny zakupu wszystkich kupowanych towarów, bez daty. Długie: każdy towar wczytywany przez Sferę.'),
                'when' => $this->l('tylko na żądanie: jobs=price_pull_full'),
                'off' => false,
            ],
        ];
    }

    /**
     * @return bool
     */
    private function cronStale()
    {
        $lastRun = $this->config->get(InfinitySubiektConfig::CRON_LAST_RUN);
        $lastRunTime = $lastRun === '' ? false : strtotime($lastRun);

        return $lastRunTime === false || $lastRunTime < time() - InfinitySubiektConfig::CRON_STALE * 60;
    }

    /**
     * Adres liczony przez PrestaShop, więc zgodny z przyjaznymi URL-ami, SSL i katalogiem sklepu.
     *
     * @return string
     */
    private function endpointUrl()
    {
        return $this->context->link->getModuleLink(
            $this->module->name,
            'agent',
            [],
            true,
            null,
            (int) Configuration::get('PS_SHOP_DEFAULT')
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
