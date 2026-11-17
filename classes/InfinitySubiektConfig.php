<?php
/**
 * Ustawienia modułu: klucze, wartości domyślne, odczyt i zapis.
 * Wszystkie ustawienia są globalne (jedna kolejka i jeden agent na instalację).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektConfig
{
    // ustawienia (zostają po odinstalowaniu)
    const AGENT_TOKEN = 'INFSUBIEKT_AGENT_TOKEN';
    const CRON_TOKEN = 'INFSUBIEKT_CRON_TOKEN';
    const DOC_MODE = 'INFSUBIEKT_DOC_MODE';
    const KSEF = 'INFSUBIEKT_KSEF';
    const SYMBOL_PREFIX = 'INFSUBIEKT_SYMBOL_PREFIX';
    const CUSTOMER_MODE = 'INFSUBIEKT_CUSTOMER_MODE';
    const CREATE_PRODUCTS = 'INFSUBIEKT_CREATE_PRODUCTS';
    const PRODUCT_UNIT = 'INFSUBIEKT_PRODUCT_UNIT';
    const SHIPPING_MODE = 'INFSUBIEKT_SHIPPING_MODE';
    const SHIPPING_NAME = 'INFSUBIEKT_SHIPPING_NAME';
    const SHIPPING_CODE = 'INFSUBIEKT_SHIPPING_CODE';
    // stawka VAT usługi jednorazowej wysyłki; pusta = stawka przewoźnika z zamówienia
    const SHIPPING_VAT = 'INFSUBIEKT_SHIPPING_VAT';
    const WAREHOUSE_ID = 'INFSUBIEKT_WAREHOUSE_ID';
    const PS_WAREHOUSE_ID = 'INFSUBIEKT_PS_WAREHOUSE_ID';
    const STOCK_SOURCE = 'INFSUBIEKT_STOCK_SOURCE';
    const PURCHASE_SOURCE = 'INFSUBIEKT_PURCHASE_SOURCE';
    // opcjonalna nazwa cechy towaru w Subiekcie: stock_pull bierze towary z cechą zamiast towarów ze stanem > 0
    const STOCK_FEATURE = 'INFSUBIEKT_STOCK_FEATURE';
    // stock_pull zeruje w magazynie sklepu pozycje spoza listy z Subiekta
    const STOCK_ZERO_MISSING = 'INFSUBIEKT_STOCK_ZERO_MISSING';
    const CRON_STOCK_PULL = 'INFSUBIEKT_CRON_STOCK_PULL';
    const CRON_PRICE_PULL = 'INFSUBIEKT_CRON_PRICE_PULL';
    // co ile minut stock_pull przy częstszym cronie (0 = przy każdym przebiegu)
    const CRON_STOCK_INTERVAL = 'INFSUBIEKT_CRON_STOCK_INTERVAL';
    // od której godziny price_pull raz na dobę
    const CRON_PRICE_HOUR = 'INFSUBIEKT_CRON_PRICE_HOUR';
    // dawny wspólny przełącznik: tylko wartość domyślna dwóch powyższych
    const CRON_PRODUCT_PULL = 'INFSUBIEKT_CRON_PRODUCT_PULL';
    const PAYMENT_MAP = 'INFSUBIEKT_PAYMENT_MAP';
    // szablony tekstów dokumentu (InfinitySubiektDocumentText)
    const DOC_SUBTITLE = 'INFSUBIEKT_DOC_SUBTITLE';
    const DOC_MESSAGE = 'INFSUBIEKT_DOC_MESSAGE';
    const DOC_ORIGINAL_NUMBER = 'INFSUBIEKT_DOC_ORIGINAL_NUMBER';
    // dopasowanie dokumentu wystawionego przed modułem: dni po dacie zamówienia, w których szukać
    const MATCH_DAYS = 'INFSUBIEKT_MATCH_DAYS';

    // stan działania (usuwany przy odinstalowaniu)
    const AGENT_LAST_SEEN = 'INFSUBIEKT_AGENT_LAST_SEEN';
    // wersja agenta z User-Agent ostatniego polla
    const AGENT_VERSION = 'INFSUBIEKT_AGENT_VERSION';
    const CRON_LAST_RUN = 'INFSUBIEKT_CRON_LAST_RUN';
    const CRON_LOG = 'INFSUBIEKT_CRON_LOG';
    const CLEANUP_DATE = 'INFSUBIEKT_CLEANUP_DATE';
    // dzień startu ostatniego udanego przebiegu price_pull (zakupy od tej daty minus PRICE_PULL_OVERLAP)
    const PRICE_PULL_DATE = 'INFSUBIEKT_PRICE_PULL_DATE';
    // start ostatniego stock_pull z harmonogramu i dzień ostatniej próby price_pull
    const CRON_STOCK_LAST = 'INFSUBIEKT_CRON_STOCK_LAST';
    const PRICE_PULL_TRIED = 'INFSUBIEKT_PRICE_PULL_TRIED';
    // ostatni wynik zadań z harmonogramu (JSON zadanie => czas i linie), żeby log ich nie gubił między przebiegami
    const CRON_JOB_LOG = 'INFSUBIEKT_CRON_JOB_LOG';
    // lista form płatności z Subiekta (payment/forms) do selectów mapy płatności
    const PAYMENT_FORMS = 'INFSUBIEKT_PAYMENT_FORMS';
    // lista cech towarów z Subiekta (product/features) do selecta cechy stanów
    const STOCK_FEATURES = 'INFSUBIEKT_STOCK_FEATURES';

    const DOC_MODE_INVOICE = 'invoice';
    const DOC_MODE_RECEIPT = 'receipt';
    const DOC_MODE_INVOICE_IF_NIP = 'invoice_if_nip';
    const DOC_MODE_ORDER = 'order';
    const DOC_MODE_ORDER_ADVANCE = 'order_advance';

    const CUSTOMER_MODE_SYMBOL = 'symbol';
    const CUSTOMER_MODE_MATCH = 'match';

    const SHIPPING_MODE_ONE_TIME = 'one_time';
    const SHIPPING_MODE_PRODUCT = 'product';

    const STOCK_SOURCE_AVAILABLE = 'available';
    const STOCK_SOURCE_STOCK = 'stock';

    // klucze purchase_prices z product/info i product/list
    const PURCHASE_SOURCE_CARD = 'card';
    const PURCHASE_SOURCE_LAST_DELIVERY = 'last_delivery';
    const PURCHASE_SOURCE_WEIGHTED_AVG = 'weighted_avg';
    const PURCHASE_SOURCE_LAST_PURCHASE = 'last_purchase';

    // parametry czasowe
    const POLL_WAIT = 20;          // s
    const TIMEOUT = 60;            // s
    const TIMEOUT_ADD = 90;        // s
    const TIMEOUT_PING = 15;       // s
    const TIMEOUT_UPDATE = 150;    // s, pobranie plików agenta i test nowej wersji
    const TASK_TTL = 60;           // min
    const STUCK_AFTER = 10;        // min
    const MAX_ATTEMPTS = 3;
    const ONLINE_THRESHOLD = 120;  // s
    const ADOPT_DAYS = 7;
    const CLEANUP_DAYS = 30;
    const ORDER_LOCK = 5;          // min
    const CRON_LOCK = 30;          // min
    const CRON_STALE = 60;         // min
    const PRICE_PULL_OVERLAP = 7;  // dni wstecz od ostatniego przebiegu price_pull (dokumenty z wcześniejszą datą)

    const PRIORITY_PANEL = 10;
    const PRIORITY_BACKGROUND = 0;

    /**
     * @return string[]
     */
    public static function docModes()
    {
        return [
            self::DOC_MODE_INVOICE,
            self::DOC_MODE_RECEIPT,
            self::DOC_MODE_INVOICE_IF_NIP,
            self::DOC_MODE_ORDER,
            self::DOC_MODE_ORDER_ADVANCE,
        ];
    }

    /**
     * @return string[]
     */
    public static function customerModes()
    {
        return [self::CUSTOMER_MODE_SYMBOL, self::CUSTOMER_MODE_MATCH];
    }

    /**
     * @return string[]
     */
    public static function shippingModes()
    {
        return [self::SHIPPING_MODE_ONE_TIME, self::SHIPPING_MODE_PRODUCT];
    }

    /**
     * @return string[]
     */
    public static function stockSources()
    {
        return [self::STOCK_SOURCE_AVAILABLE, self::STOCK_SOURCE_STOCK];
    }

    /**
     * @return string[]
     */
    public static function purchaseSources()
    {
        return [
            self::PURCHASE_SOURCE_CARD,
            self::PURCHASE_SOURCE_LAST_DELIVERY,
            self::PURCHASE_SOURCE_WEIGHTED_AVG,
            self::PURCHASE_SOURCE_LAST_PURCHASE,
        ];
    }

    /**
     * @return string
     */
    public static function generateToken()
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Ustawienia z wartościami domyślnymi. Pusty token = wyłączony
     * (token crona dostaje losową wartość przy pierwszej instalacji).
     *
     * @return array
     */
    public function defaults()
    {
        return [
            self::AGENT_TOKEN => '',
            self::CRON_TOKEN => '',
            self::DOC_MODE => self::DOC_MODE_INVOICE,
            self::KSEF => '1',
            self::SYMBOL_PREFIX => '',
            self::CUSTOMER_MODE => self::CUSTOMER_MODE_SYMBOL,
            self::CREATE_PRODUCTS => '0',
            self::PRODUCT_UNIT => 'szt.',
            self::SHIPPING_MODE => self::SHIPPING_MODE_ONE_TIME,
            self::SHIPPING_NAME => 'Wysyłka',
            self::SHIPPING_CODE => 'WYSYLKA',
            self::SHIPPING_VAT => '23',
            self::WAREHOUSE_ID => '0',
            self::PS_WAREHOUSE_ID => '0',
            self::STOCK_SOURCE => self::STOCK_SOURCE_AVAILABLE,
            self::PURCHASE_SOURCE => self::PURCHASE_SOURCE_CARD,
            self::STOCK_FEATURE => '',
            self::STOCK_ZERO_MISSING => '1',
            self::CRON_STOCK_PULL => (string) (int) Configuration::getGlobalValue(self::CRON_PRODUCT_PULL),
            self::CRON_PRICE_PULL => (string) (int) Configuration::getGlobalValue(self::CRON_PRODUCT_PULL),
            self::CRON_STOCK_INTERVAL => '60',
            self::CRON_PRICE_HOUR => '2',
            // pusta mapa: każdy dokument z formą domyślną Subiekta; id form (fp_Id) są inne w każdej bazie Subiekta
            self::PAYMENT_MAP => '',
            self::DOC_SUBTITLE => 'zamówienie {reference} - {shop_domain} z dnia {date}',
            self::DOC_MESSAGE => 'zamówienie {reference} - {shop_domain} z dnia {date}',
            self::DOC_ORIGINAL_NUMBER => '',
            self::MATCH_DAYS => '60',
        ];
    }

    /**
     * Mapa płatności: linie `moduł=payment` albo `moduł=payment:payment_id`.
     *
     * @param string $text
     *
     * @return array ['map' => [moduł => ['payment' => …, 'payment_id' => int|null]], 'errors' => numery błędnych linii]
     */
    public static function parsePaymentMap($text)
    {
        $map = [];
        $errors = [];
        foreach (preg_split('/\R/u', (string) $text) as $number => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // formy obsługiwane przez agenta
            if (!preg_match('/^([A-Za-z0-9_-]+)\s*=\s*(transfer|credit|card|cash)(?:\s*:\s*(\d+))?$/u', $line, $m)) {
                $errors[] = $number + 1;
                continue;
            }
            $map[$m[1]] = [
                'payment' => $m[2],
                'payment_id' => isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null,
            ];
        }

        return ['map' => $map, 'errors' => $errors];
    }

    /**
     * @return string[]
     */
    public function runtimeKeys()
    {
        return [
            self::AGENT_LAST_SEEN,
            self::AGENT_VERSION,
            self::CRON_LAST_RUN,
            self::CRON_LOG,
            self::CLEANUP_DATE,
            self::PAYMENT_FORMS,
            self::STOCK_FEATURES,
            self::PRICE_PULL_DATE,
            self::CRON_STOCK_LAST,
            self::PRICE_PULL_TRIED,
            self::CRON_JOB_LOG,
        ];
    }

    /**
     * Wartość ustawienia; brak klucza w bazie = wartość domyślna.
     *
     * @param string $key
     *
     * @return string
     */
    public function get($key)
    {
        $value = Configuration::getGlobalValue($key);
        if ($value !== false) {
            return (string) $value;
        }

        $defaults = $this->defaults();

        return isset($defaults[$key]) ? $defaults[$key] : '';
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public function getBool($key)
    {
        return (bool) (int) $this->get($key);
    }

    /**
     * @param string $key
     * @param mixed $value
     *
     * @return bool
     */
    public function set($key, $value)
    {
        return (bool) Configuration::updateGlobalValue($key, $value);
    }

    /**
     * Ustawia wartości domyślne tylko dla brakujących kluczy, żeby reinstalacja
     * nie nadpisała tokenów i prefiksu.
     *
     * @return bool
     */
    public function installDefaults()
    {
        $ok = true;
        foreach ($this->defaults() as $key => $value) {
            if (Configuration::getGlobalValue($key) !== false) {
                continue;
            }
            if ($key === self::CRON_TOKEN) {
                $value = self::generateToken();
            }
            $ok = $this->set($key, $value) && $ok;
        }

        return $ok;
    }

    /**
     * @return bool
     */
    public function removeRuntime()
    {
        $ok = true;
        foreach ($this->runtimeKeys() as $key) {
            $ok = Configuration::deleteByName($key) && $ok;
        }

        return $ok;
    }
}
