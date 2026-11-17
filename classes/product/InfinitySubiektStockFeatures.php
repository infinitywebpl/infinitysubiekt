<?php
/**
 * Cechy towarów z Subiekta do selecta „Cecha towarów do pobierania stanów”: lista pobrana przez agenta
 * (product/features) i zapisana w konfiguracji, bo strona BO nie woła agenta podczas renderu.
 * W ustawieniu zostaje nazwa cechy (agent szuka po nazwie).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektStockFeatures
{
    const METHOD = 'product/features';

    // nazwa pliku małymi literami: klucz tłumaczeń PrestaShop dla stringów z tej klasy
    const TRANSLATION_SOURCE = 'infinitysubiektstockfeatures';

    /** @var Module */
    private $module;

    /** @var InfinitySubiektConfig */
    private $config;

    /** @var InfinitySubiektClock */
    private $clock;

    public function __construct(Module $module, InfinitySubiektConfig $config, InfinitySubiektClock $clock)
    {
        $this->module = $module;
        $this->config = $config;
        $this->clock = $clock;
    }

    /**
     * @return string[] nazwy cech z ostatniego pobrania
     */
    public function stored()
    {
        $data = InfinitySubiektJson::decode($this->config->get(InfinitySubiektConfig::STOCK_FEATURES));

        return is_array($data) && isset($data['names']) && is_array($data['names']) ? $data['names'] : [];
    }

    /**
     * @return string data pobrania listy ('' = nie pobrano)
     */
    public function fetchedAt()
    {
        $data = InfinitySubiektJson::decode($this->config->get(InfinitySubiektConfig::STOCK_FEATURES));

        return is_array($data) && isset($data['date']) ? (string) $data['date'] : '';
    }

    /**
     * @param array $result wynik product/features
     *
     * @return string[] zapisane nazwy
     */
    public function store(array $result)
    {
        $names = [];
        foreach (isset($result['features']) && is_array($result['features']) ? $result['features'] : [] as $row) {
            $name = is_array($row) && isset($row['name']) ? Tools::substr(trim((string) $row['name']), 0, 50) : '';
            if ($name !== '') {
                $names[] = $name;
            }
        }
        $names = array_values(array_unique($names));

        $this->config->set(InfinitySubiektConfig::STOCK_FEATURES, InfinitySubiektJson::encode([
            'date' => $this->clock->now(),
            'names' => $names,
        ]));

        return $names;
    }

    /**
     * Opcje selecta: bez cechy, cechy z Subiekta i bieżąca wartość, gdy jej na liście nie ma (żeby zapis jej nie zgubił).
     *
     * @param string $current
     *
     * @return array [['value' => …, 'label' => …], …]
     */
    public function options($current = '')
    {
        $options = [['value' => '', 'label' => $this->l('— bez cechy: towary ze stanem większym od 0 —')]];
        $known = [''];
        foreach ($this->stored() as $name) {
            $options[] = ['value' => $name, 'label' => $name];
            $known[] = Tools::strtolower($name);
        }

        $current = trim((string) $current);
        if (!in_array(Tools::strtolower($current), $known, true)) {
            $options[] = ['value' => $current, 'label' => sprintf($this->l('%s (brak na liście z Subiekta)'), $current)];
        }

        return $options;
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
