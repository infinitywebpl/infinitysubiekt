<?php
/**
 * Integracja PrestaShop z Subiektem GT przez agenta (long polling). Wersja demo: InfinitySubiektDemo.
 *
 * Klasa modułu jest tylko punktem wejścia PrestaShop: deleguje do klas z classes/
 * i tworzy je (leniwe gettery poniżej).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/InfinitySubiektAutoloader.php';
InfinitySubiektAutoloader::register(__DIR__ . '/classes');

class InfinitySubiekt extends Module
{
    /** @var InfinitySubiektConfig|null */
    private $subiektConfig;

    /** @var InfinitySubiektClock|null */
    private $clock;

    /** @var InfinitySubiektTaskQueue|null */
    private $taskQueue;

    /** @var InfinitySubiektRepository|null */
    private $repository;

    public function __construct()
    {
        $this->name = 'infinitysubiekt';
        $this->tab = 'administration';
        $this->version = '1.0.3';
        $this->author = 'InfinityWeb.pl';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.6.1.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Subiekt GT - integracja (demo)');
        $this->description = $this->l('Wersja demo: konfiguracja, kolejka i połączenie z agentem Subiekta GT. Kartoteki, stany, ceny, dokumenty i PDF działają w wersji pełnej.');
    }

    public function install()
    {
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        $installer = $this->createInstaller();
        if (parent::install() && $installer->install()) {
            return true;
        }

        $this->_errors = array_merge($this->_errors, $installer->getErrors());

        return false;
    }

    public function uninstall()
    {
        $installer = $this->createInstaller();
        if ($installer->uninstall() && parent::uninstall()) {
            return true;
        }

        $this->_errors = array_merge($this->_errors, $installer->getErrors());

        return false;
    }

    public function getContent()
    {
        $page = new InfinitySubiektSettingsPage(
            $this,
            $this->getSubiektConfig(),
            $this->getAgentStatus(),
            $this->createTaskList($this->context),
            $this->createAgentPackage(),
            new InfinitySubiektView($this, $this->context),
            $this->context
        );

        return $page->render();
    }

    /*
     * Usługi
     */

    /**
     * @return InfinitySubiektConfig
     */
    public function getSubiektConfig()
    {
        if ($this->subiektConfig === null) {
            $this->subiektConfig = new InfinitySubiektConfig();
        }

        return $this->subiektConfig;
    }

    /**
     * @return InfinitySubiektClock
     */
    public function getClock()
    {
        if ($this->clock === null) {
            $this->clock = new InfinitySubiektClock();
        }

        return $this->clock;
    }

    /**
     * @return InfinitySubiektTaskQueue
     */
    public function getTaskQueue()
    {
        if ($this->taskQueue === null) {
            $this->taskQueue = new InfinitySubiektTaskQueue(Db::getInstance(), $this->getClock(), _DB_PREFIX_);
        }

        return $this->taskQueue;
    }

    /**
     * @return InfinitySubiektRepository
     */
    public function getRepository()
    {
        if ($this->repository === null) {
            $this->repository = new InfinitySubiektRepository(Db::getInstance(), $this->getClock(), _DB_PREFIX_);
        }

        return $this->repository;
    }

    /**
     * Notatki o tym, jak firma prowadzi Subiekta, dla narzędzia MCP subiekt_query.
     *
     * @return InfinitySubiektQueryNotes
     */
    public function getQueryNotes()
    {
        return new InfinitySubiektQueryNotes(Db::getInstance(), $this->getClock(), _DB_PREFIX_);
    }

    /**
     * @return InfinitySubiektPayload
     */
    public function getPayload()
    {
        return new InfinitySubiektPayload(
            $this->getSubiektConfig(),
            $this->getRepository(),
            $this->getDocumentTypeResolver(),
            new InfinitySubiektDocumentText($this->getSubiektConfig())
        );
    }

    /**
     * @return InfinitySubiektPaymentForms
     */
    public function getPaymentForms()
    {
        return new InfinitySubiektPaymentForms($this, $this->getSubiektConfig(), $this->getClock());
    }

    /**
     * @return InfinitySubiektStockFeatures
     */
    public function getStockFeatures()
    {
        return new InfinitySubiektStockFeatures($this, $this->getSubiektConfig(), $this->getClock());
    }

    /**
     * @return InfinitySubiektClient
     */
    public function getClient()
    {
        return new InfinitySubiektClient(
            $this->getTaskQueue(),
            $this->getPayload(),
            $this->getRepository(),
            $this->getAgentStatus()
        );
    }

    /**
     * @return InfinitySubiektDocumentMatch
     */
    public function createDocumentMatch()
    {
        return new InfinitySubiektDocumentMatch(
            $this->getClient(),
            $this->getRepository(),
            $this->getTaskQueue(),
            $this->getDocumentTypeResolver(),
            $this->getAgentStatus(),
            $this->getSubiektConfig()
        );
    }

    /**
     * @return InfinitySubiektProductStock
     */
    public function getProductStock()
    {
        return new InfinitySubiektProductStock(
            $this->getClient(),
            $this->getRepository(),
            $this->getSubiektConfig(),
            new InfinitySubiektWarehouseStock()
        );
    }

    /**
     * @return InfinitySubiektCatalogPull
     */
    public function getCatalogPull()
    {
        return new InfinitySubiektCatalogPull(
            $this->getClient(),
            $this->getRepository(),
            $this->getProductStock(),
            new InfinitySubiektWarehouseStock(),
            $this->getSubiektConfig(),
            $this->getClock()
        );
    }

    /**
     * @return InfinitySubiektAgentStatus
     */
    public function getAgentStatus()
    {
        return new InfinitySubiektAgentStatus($this->getSubiektConfig(), $this->getClock());
    }

    /**
     * @return InfinitySubiektAgentPackage
     */
    public function createAgentPackage()
    {
        return new InfinitySubiektAgentPackage($this->getLocalPath() . 'agent');
    }

    /**
     * @return InfinitySubiektAgentRelease
     */
    public function createAgentRelease()
    {
        return new InfinitySubiektAgentRelease($this->getLocalPath() . 'agent');
    }

    /**
     * @return InfinitySubiektAgentEndpoint
     */
    public function createAgentEndpoint()
    {
        return new InfinitySubiektAgentEndpoint(
            new InfinitySubiektTokenAuth($this->getSubiektConfig(), InfinitySubiektConfig::AGENT_TOKEN),
            $this->getTaskQueue(),
            $this->getAgentStatus(),
            new InfinitySubiektResponse(),
            $this->createAgentRelease()
        );
    }

    /**
     * @return InfinitySubiektCron
     */
    public function createCron()
    {
        return new InfinitySubiektCron(
            $this->getTaskQueue(),
            new InfinitySubiektLock(Db::getInstance(), $this->getClock(), _DB_PREFIX_),
            $this->getCatalogPull(),
            $this->getSubiektConfig(),
            $this->getClock()
        );
    }

    /**
     * @return InfinitySubiektCronEndpoint
     */
    public function createCronEndpoint()
    {
        return new InfinitySubiektCronEndpoint(
            new InfinitySubiektTokenAuth($this->getSubiektConfig(), InfinitySubiektConfig::CRON_TOKEN),
            $this->createCron(),
            new InfinitySubiektResponse()
        );
    }

    /**
     * @param Context $context
     *
     * @return InfinitySubiektTaskList
     */
    public function createTaskList(Context $context)
    {
        return new InfinitySubiektTaskList($this->getTaskQueue(), new InfinitySubiektView($this, $context), $context);
    }

    /**
     * @param Context $context
     *
     * @return InfinitySubiektCustomerPanel
     */
    public function createCustomerPanel(Context $context)
    {
        return new InfinitySubiektCustomerPanel(
            $this,
            $this->getSubiektConfig(),
            $this->getRepository(),
            new InfinitySubiektMessages($this),
            new InfinitySubiektView($this, $context),
            $context
        );
    }

    /**
     * @param Context $context
     *
     * @return InfinitySubiektOrderPanel
     */
    public function createOrderPanel(Context $context)
    {
        return new InfinitySubiektOrderPanel(
            $this,
            $this->getSubiektConfig(),
            $this->getRepository(),
            $this->getTaskQueue(),
            $this->createCustomerPanel($context),
            $this->createTaskList($context),
            $this->getDocumentTypeResolver(),
            new InfinitySubiektMessages($this),
            new InfinitySubiektView($this, $context),
            $context
        );
    }

    /**
     * @param Context $context
     *
     * @return InfinitySubiektProductPanel
     */
    public function createProductPanel(Context $context)
    {
        return new InfinitySubiektProductPanel(
            $this,
            $this->getRepository(),
            $this->getProductStock(),
            new InfinitySubiektMessages($this),
            new InfinitySubiektView($this, $context),
            $context
        );
    }

    /**
     * @return InfinitySubiektDocumentTypeResolver
     */
    public function getDocumentTypeResolver()
    {
        return new InfinitySubiektDocumentTypeResolver($this->getSubiektConfig());
    }

    /**
     * @return InfinitySubiektInstaller
     */
    private function createInstaller()
    {
        $schema = new InfinitySubiektSchema(Db::getInstance(), _DB_PREFIX_, InfinitySubiektCompat::dbCharset());

        return new InfinitySubiektInstaller($this, $schema, $this->getSubiektConfig());
    }

    /*
     * Hooki BO.
     */

    public function hookDisplayBackOfficeHeader($params)
    {
        $controller = $this->context->controller;
        if (isset($controller->controller_name) && in_array($controller->controller_name, ['AdminOrders', 'AdminCustomers', 'AdminProducts'], true)) {
            $controller->addJS($this->getPathUri() . 'views/js/admin.js');
        }
        // lista zadań zamówienia na karcie zamówienia: popup szczegółów
        if (isset($controller->controller_name) && $controller->controller_name === 'AdminOrders') {
            $controller->addJS($this->getPathUri() . 'views/js/tasks.js');
        }

        return '';
    }

    public function hookDisplayAdminOrder($params)
    {
        if (InfinitySubiektCompat::isPs177()) {
            return '';
        }

        return $this->renderOrderPanel((int) $params['id_order']);
    }

    public function hookDisplayAdminOrderMain($params)
    {
        if (!InfinitySubiektCompat::isPs177()) {
            return '';
        }

        return $this->renderOrderPanel((int) $params['id_order']);
    }

    public function hookDisplayAdminCustomers($params)
    {
        return $this->createCustomerPanel($this->context)->renderCustomerPanel((int) $params['id_customer']);
    }

    /**
     * PS 1.7+ podaje id_product w parametrach, PS 1.6 tylko w żądaniu.
     */
    public function hookDisplayAdminProductsExtra($params)
    {
        $idProduct = isset($params['id_product']) ? (int) $params['id_product'] : (int) Tools::getValue('id_product');

        return $this->createProductPanel($this->context)->renderPanel($idProduct);
    }

    /**
     * Serwer MCP modułu infinitymcp (tylko odczyt). Bez tego modułu hook nigdy się nie wykona.
     */
    public function hookActionInfinityMcpExtend($params)
    {
        if (!isset($params['extensions']) || !$params['extensions'] instanceof \InfinityMcp\McpExtensions) {
            return;
        }

        (new InfinitySubiektMcpExtension($this))->register($params['extensions']);
    }

    /**
     * Paczki zmian modułu infinitymcp_changes: sklep zmienia dopiero akceptacja pracownika w BO.
     */
    public function hookActionInfinityMcpChangesExtend($params)
    {
        if (!isset($params['extensions']) || !$params['extensions'] instanceof \InfinityMcpChanges\ChangesExtensions) {
            return;
        }

        // Pełna wersja: operacje subiekt.pull_purchase_price, subiekt.pull_stock, subiekt.fetch_order_pdf,
        // subiekt.inspect_schema i subiekt.save_query_note.
    }

    /**
     * @param int $idOrder
     *
     * @return string
     */
    private function renderOrderPanel($idOrder)
    {
        return $this->createOrderPanel($this->context)->renderPanel($idOrder);
    }
}
