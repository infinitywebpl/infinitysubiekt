<?php
/**
 * Teksty dokumentu z szablonów w konfiguracji: podtytuł, uwagi i numer oryginału.
 * Tag to nazwa w nawiasach klamrowych, np. {reference}. Nieznany tag odrzuca walidacja formularza.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektDocumentText
{
    // agent obcina podtytuł do 50 znaków i po nim rozpoznaje wystawiony dokument
    const SUBTITLE_LENGTH = 50;

    // NumerOryginalny w Sferze; obcina agent
    const ORIGINAL_NUMBER_LENGTH = 30;

    /** @var InfinitySubiektConfig */
    private $config;

    public function __construct(InfinitySubiektConfig $config)
    {
        $this->config = $config;
    }

    /**
     * @return string[] tagi szablonów (bez nawiasów)
     */
    public static function tags()
    {
        return [
            'id_order',
            'reference',
            'date',
            'total_paid',
            'total_shipping',
            'currency',
            'payment',
            'carrier',
            'shop_name',
            'shop_domain',
        ];
    }

    /**
     * Tagi jednoznacznie wskazujące zamówienie (ochrona przed duplikatem po podtytule).
     *
     * @return string[]
     */
    public static function uniqueTags()
    {
        return ['id_order', 'reference'];
    }

    /**
     * @param string $template
     *
     * @return string[] nieznane tagi z szablonu
     */
    public static function unknownTags($template)
    {
        preg_match_all('/\{([^{}]*)\}/u', (string) $template, $m);

        return array_values(array_unique(array_diff($m[1], self::tags())));
    }

    /**
     * Teksty dokumentu dla zamówienia. Pusty tekst = agent nie ustawia pola.
     *
     * duplicate_check: podtytuł ma numer zamówienia w pierwszych 50 znakach (tyle porównuje agent),
     * więc agent może po nim rozpoznać dokument wystawiony przed awarią.
     *
     * @param Order $order
     *
     * @return array ['subtitle' => …, 'message' => …, 'original_number' => …, 'duplicate_check' => bool]
     */
    public function forOrder(Order $order)
    {
        $values = $this->values($order);

        $template = $this->config->get(InfinitySubiektConfig::DOC_SUBTITLE);
        $end = self::uniqueTagEnd($template);
        $duplicateCheck = $end !== null
            && Tools::strlen(self::line(self::render(Tools::substr($template, 0, $end), $values))) <= self::SUBTITLE_LENGTH;

        return [
            'subtitle' => self::line(self::render($template, $values)),
            'message' => trim(self::render($this->config->get(InfinitySubiektConfig::DOC_MESSAGE), $values)),
            'original_number' => self::line(self::render($this->config->get(InfinitySubiektConfig::DOC_ORIGINAL_NUMBER), $values)),
            'duplicate_check' => $duplicateCheck,
        ];
    }

    /**
     * Wartości tagów. Kwoty z przecinkiem dziesiętnym, bez separatora tysięcy.
     *
     * @param Order $order
     *
     * @return array tag => tekst
     */
    private function values(Order $order)
    {
        $shop = new Shop((int) $order->id_shop);
        $currency = new Currency((int) $order->id_currency);

        return [
            'id_order' => (string) (int) $order->id,
            'reference' => (string) $order->reference,
            'date' => date('d.m.Y', strtotime($order->date_add)),
            'total_paid' => number_format((float) $order->total_paid_tax_incl, 2, ',', ''),
            'total_shipping' => number_format((float) $order->total_shipping_tax_incl, 2, ',', ''),
            'currency' => Validate::isLoadedObject($currency) ? (string) $currency->iso_code : '',
            'payment' => (string) $order->payment,
            'carrier' => $this->carrierName($order, $shop),
            'shop_name' => (string) $shop->name,
            'shop_domain' => (string) $shop->domain,
        ];
    }

    /**
     * Nazwa przewoźnika; „0” to w PrestaShop nazwa sklepu.
     *
     * @param Order $order
     * @param Shop $shop
     *
     * @return string
     */
    private function carrierName(Order $order, Shop $shop)
    {
        $carrier = new Carrier((int) $order->id_carrier);
        if (!Validate::isLoadedObject($carrier)) {
            return '';
        }

        $name = (string) $carrier->name;
        if ($name === '0') {
            $name = (string) Configuration::get('PS_SHOP_NAME', null, (int) $shop->id_shop_group, (int) $shop->id);
        }

        return $name;
    }

    /**
     * @param string $template
     * @param array $values tag => tekst
     *
     * @return string
     */
    private static function render($template, array $values)
    {
        $pairs = [];
        foreach ($values as $tag => $value) {
            $pairs['{' . $tag . '}'] = $value;
        }

        return strtr((string) $template, $pairs);
    }

    /**
     * Jedna linia: białe znaki zwinięte do spacji.
     *
     * @param string $text
     *
     * @return string
     */
    private static function line($text)
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $text));
    }

    /**
     * Pozycja (w znakach) zaraz za pierwszym tagiem zamówienia.
     *
     * @param string $template
     *
     * @return int|null null = brak tagu
     */
    private static function uniqueTagEnd($template)
    {
        $end = null;
        foreach (self::uniqueTags() as $tag) {
            $pos = Tools::strpos((string) $template, '{' . $tag . '}');
            if ($pos !== false && ($end === null || $pos + Tools::strlen($tag) + 2 < $end)) {
                $end = $pos + Tools::strlen($tag) + 2;
            }
        }

        return $end;
    }
}
