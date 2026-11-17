<?php
/**
 * Formy płatności do mapy płatności w konfiguracji: lista pobrana z Subiekta (payment/forms),
 * opcje selectów i moduły płatności sklepu. Mapa zostaje tekstem `moduł=forma[:id]` w INFSUBIEKT_PAYMENT_MAP.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektPaymentForms
{
    // nazwa pliku małymi literami: klucz tłumaczeń PrestaShop dla stringów z tej klasy
    const TRANSLATION_SOURCE = 'infinitysubiektpaymentforms';

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
     * Zapisana lista form z Subiekta.
     *
     * @return array [['id' => int, 'type' => 'credit'|'card', 'name' => string], …]
     */
    public function stored()
    {
        $data = InfinitySubiektJson::decode($this->config->get(InfinitySubiektConfig::PAYMENT_FORMS));

        return is_array($data) && isset($data['forms']) && is_array($data['forms']) ? $data['forms'] : [];
    }

    /**
     * @return string data pobrania listy ('' = nie pobrano)
     */
    public function fetchedAt()
    {
        $data = InfinitySubiektJson::decode($this->config->get(InfinitySubiektConfig::PAYMENT_FORMS));

        return is_array($data) && isset($data['date']) ? (string) $data['date'] : '';
    }

    /**
     * Zapis wyniku payment/forms. Wiersze o nieznanym typie albo bez id są pomijane.
     *
     * @param array $result wynik agenta
     *
     * @return array zapisane formy
     */
    public function store(array $result)
    {
        $forms = [];
        $rows = isset($result['forms']) && is_array($result['forms']) ? $result['forms'] : [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'], $row['type']) || !in_array($row['type'], ['credit', 'card'], true) || (int) $row['id'] <= 0) {
                continue;
            }
            $forms[] = [
                'id' => (int) $row['id'],
                'type' => $row['type'],
                'name' => isset($row['name']) ? Tools::substr(trim((string) $row['name']), 0, 100) : '',
            ];
        }

        $this->config->set(InfinitySubiektConfig::PAYMENT_FORMS, InfinitySubiektJson::encode([
            'date' => $this->clock->now(),
            'forms' => $forms,
        ]));

        return $forms;
    }

    /**
     * Opcje selecta: forma domyślna Subiekta, formy bez wariantu, karty i terminy z listy Subiekta
     * oraz wartości z mapy, których na liście nie ma (żeby zapis ich nie zgubił).
     *
     * @param string[] $used wartości z bieżącej mapy, np. ['credit:3', 'card']
     *
     * @return array [['value' => string, 'label' => string], …]
     */
    public function options(array $used = [])
    {
        $options = [
            ['value' => '', 'label' => $this->l('— forma domyślna Subiekta —')],
            ['value' => 'cash', 'label' => $this->l('Gotówka')],
        ];

        $groups = [
            'card' => [$this->l('Karta (rodzaj domyślny)'), $this->l('Karta: %s')],
            'credit' => [$this->l('Kredyt kupiecki (termin domyślny)'), $this->l('Kredyt kupiecki: %s')],
        ];
        $known = ['', 'cash', 'transfer', 'card', 'credit'];
        foreach ($groups as $type => $labels) {
            $options[] = ['value' => $type, 'label' => $labels[0]];
            foreach ($this->stored() as $form) {
                if ($form['type'] === $type) {
                    $value = $type . ':' . $form['id'];
                    $known[] = $value;
                    $options[] = ['value' => $value, 'label' => sprintf($labels[1], $form['name'])];
                }
            }
        }

        $options[] = ['value' => 'transfer', 'label' => $this->l('Zapłacono przelewem (dokument od razu rozliczony)')];

        foreach (array_unique($used) as $value) {
            if (!in_array($value, $known, true) && preg_match('/^(card|credit):(\d+)$/', $value, $m)) {
                $options[] = [
                    'value' => $value,
                    'label' => sprintf(
                        $m[1] === 'card' ? $this->l('Karta #%d (brak na liście z Subiekta)') : $this->l('Kredyt kupiecki #%d (brak na liście z Subiekta)'),
                        (int) $m[2]
                    ),
                ];
            }
        }

        return $options;
    }

    /**
     * Wiersze mapy: tylko aktywne moduły płatności sklepu.
     *
     * @param array $map moduł => ['payment' => …, 'payment_id' => int|null] (parsePaymentMap)
     *
     * @return array [['module' => string, 'label' => string, 'value' => string], …]
     */
    public function rows(array $map)
    {
        $rows = [];
        foreach ($this->activeModules() as $name) {
            $label = Module::getModuleName($name);
            $rows[] = [
                'module' => $name,
                'label' => $label !== '' && $label !== $name ? $label . ' (' . $name . ')' : $name,
                'value' => isset($map[$name]) ? self::value($map[$name]) : '',
            ];
        }

        return $rows;
    }

    /**
     * Wpisy mapy dla modułów nieaktywnych albo odinstalowanych: niewidoczne na liście,
     * ale wysyłane ukrytymi polami, żeby zapis ich nie usunął (po włączeniu modułu forma wraca).
     *
     * @param array $map jak w rows()
     *
     * @return array moduł => wartość selecta
     */
    public function hidden(array $map)
    {
        $hidden = [];
        $active = $this->activeModules();
        foreach ($map as $name => $entry) {
            if (!in_array($name, $active, true)) {
                $hidden[$name] = self::value($entry);
            }
        }

        return $hidden;
    }

    /**
     * @return string[] nazwy techniczne aktywnych modułów płatności
     */
    private function activeModules()
    {
        $names = [];
        foreach ((array) PaymentModule::getInstalledPaymentModules() as $row) {
            $name = (string) $row['name'];
            if (Module::isEnabled($name)) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param array $entry ['payment' => …, 'payment_id' => int|null]
     *
     * @return string wartość selecta, np. credit:3
     */
    public static function value(array $entry)
    {
        return $entry['payment'] . ($entry['payment_id'] !== null ? ':' . (int) $entry['payment_id'] : '');
    }

    /**
     * Tekst mapy z wysłanych selectów (puste = moduł bez formy, poza mapą).
     *
     * @param mixed $submitted moduł => wartość
     *
     * @return string
     */
    public static function mapText($submitted)
    {
        $lines = [];
        foreach ((array) $submitted as $module => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $lines[] = trim((string) $module) . '=' . $value;
            }
        }

        return implode("\n", $lines);
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
