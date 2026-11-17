<?php
/**
 * Instalacja i odinstalowanie modułu poza tym, co robi Module::install()/uninstall().
 *
 * Tabele i ustawienia zostają po odinstalowaniu (reset modułu nie może zgubić numerów
 * dokumentów, symboli kontrahentów ani tokenów). Usuwany jest tylko stan działania.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektInstaller
{
    const ADMIN_TAB = 'AdminInfinitySubiekt';

    /** @var Module */
    private $module;

    /** @var InfinitySubiektSchema */
    private $schema;

    /** @var InfinitySubiektConfig */
    private $config;

    /** @var string[] */
    private $errors = [];

    public function __construct(Module $module, InfinitySubiektSchema $schema, InfinitySubiektConfig $config)
    {
        $this->module = $module;
        $this->schema = $schema;
        $this->config = $config;
    }

    /**
     * Wołane po parent::install().
     *
     * @return bool
     */
    public function install()
    {
        if (!$this->schema->install()) {
            return $this->fail('Nie udało się utworzyć tabel: ' . $this->schema->getError());
        }
        if (!$this->config->installDefaults()) {
            return $this->fail('Nie udało się zapisać ustawień domyślnych.');
        }
        if (!$this->installTab()) {
            return $this->fail('Nie udało się utworzyć zakładki ' . self::ADMIN_TAB . '.');
        }
        if (!$this->installHooks()) {
            return false;
        }

        return true;
    }

    /**
     * Wołane przed parent::uninstall().
     *
     * @return bool
     */
    public function uninstall()
    {
        if (!$this->uninstallTab()) {
            return $this->fail('Nie udało się usunąć zakładki ' . self::ADMIN_TAB . '.');
        }

        return $this->config->removeRuntime();
    }

    /**
     * @return string[]
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Rejestrowane są warianty hooków dla wszystkich wersji; który renderuje,
     * rozstrzyga handler (InfinitySubiektCompat::isPs177()).
     *
     * @return string[]
     */
    private function hooks()
    {
        return [
            'displayBackOfficeHeader',
            'displayAdminOrder',
            'displayAdminOrderMain',
            'displayAdminCustomers',
            'displayAdminProductsExtra',
            'actionInfinityMcpExtend',
            'actionInfinityMcpChangesExtend',
        ];
    }

    /**
     * @return bool
     */
    private function installHooks()
    {
        foreach ($this->hooks() as $hook) {
            if (!$this->module->registerHook($hook)) {
                return $this->fail('Nie udało się zarejestrować hooka ' . $hook . '.');
            }
        }

        return true;
    }

    /**
     * Ukryta zakładka dla akcji AJAX z paneli (AdminInfinitySubiektController).
     *
     * @return bool
     */
    private function installTab()
    {
        if ((int) Tab::getIdFromClassName(self::ADMIN_TAB)) {
            return true;
        }

        $tab = new Tab();
        $tab->class_name = self::ADMIN_TAB;
        $tab->module = $this->module->name;
        $tab->id_parent = -1;
        $tab->active = 1;
        $tab->name = [];
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[(int) $lang['id_lang']] = 'Subiekt GT';
        }

        return (bool) $tab->add();
    }

    /**
     * @return bool
     */
    private function uninstallTab()
    {
        $idTab = (int) Tab::getIdFromClassName(self::ADMIN_TAB);
        if (!$idTab) {
            return true;
        }

        $tab = new Tab($idTab);

        return (bool) $tab->delete();
    }

    /**
     * @param string $message
     *
     * @return bool zawsze false
     */
    private function fail($message)
    {
        $this->errors[] = $message;

        return false;
    }
}
