<?php
/**
 * Params metod agenta budowane z danych PrestaShop.
 * Strona dokumentu, towar i dokument (order/add).
 *
 * Brak danych albo konfiguracji = InfinitySubiektException z komunikatem dla pracownika.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektPayload
{
    // hook sklepu: które reguły koszyka obniżają dostawę i o ile
    const HOOK_SHIPPING_DISCOUNTS = 'actionInfinitySubiektShippingDiscounts';

    /** @var InfinitySubiektConfig */
    private $config;

    /** @var InfinitySubiektRepository */
    private $repository;

    /** @var InfinitySubiektDocumentTypeResolver */
    private $documentType;

    /** @var InfinitySubiektDocumentText */
    private $documentText;

    public function __construct(
        InfinitySubiektConfig $config,
        InfinitySubiektRepository $repository,
        InfinitySubiektDocumentTypeResolver $documentType,
        InfinitySubiektDocumentText $documentText
    ) {
        $this->config = $config;
        $this->repository = $repository;
        $this->documentType = $documentType;
        $this->documentText = $documentText;
    }

    /**
     * Params order/add.
     *
     * @param Order $order
     * @param string $issuer imię i nazwisko pracownika (pusty = domyślny wystawca Subiekta)
     *
     * @return array
     *
     * @throws InfinitySubiektException
     */
    public function order(Order $order, $issuer)
    {
        $parties = array_values($this->orderParties($order));
        $products = $this->orderProducts($order);
        $shipping = $this->shippingPaid($order);
        $productsValue = $this->productsValue($order, $products, $shipping);

        $shippingLine = $shipping > 0 ? $this->shippingLine($order, $shipping) : null;
        if ($shippingLine !== null) {
            $products[] = $shippingLine;
        }

        $payload = [
            'document' => $this->documentType->resolve($order),
            'ksef' => $this->config->getBool(InfinitySubiektConfig::KSEF),
            'customer' => $parties[0],
        ];
        // odbiorca tylko przy innym adresie dostawy; inaczej klucza nie ma wcale
        if (isset($parties[1])) {
            $payload['receiver'] = $parties[1];
        }

        $texts = $this->documentText->forOrder($order);
        $payload['products'] = $products;
        // rabat zamówienia: Subiekt obniża pozycje towarowe do tej kwoty (NadajRabatDoWartosci)
        if ($productsValue !== null) {
            $payload['products_value'] = $productsValue;
        }
        $payload['create_missing_products'] = $this->config->getBool(InfinitySubiektConfig::CREATE_PRODUCTS);
        $payload['subtitle'] = $texts['subtitle'];
        $payload['message'] = $texts['message'];
        // false = podtytuł nie wskazuje zamówienia: agent go nie szuka, a zawieszone zadanie nie wraca do kolejki
        $payload['duplicate_check'] = $texts['duplicate_check'];
        // pusty szablon = bez klucza, numer oryginału zostaje pusty
        if ($texts['original_number'] !== '') {
            $payload['original_number'] = $texts['original_number'];
        }

        if (trim((string) $issuer) !== '') {
            $payload['issuer'] = trim((string) $issuer);
        }

        // moduł płatności spoza mapy = bez kluczy, Subiekt bierze formę domyślną
        $payments = InfinitySubiektConfig::parsePaymentMap($this->config->get(InfinitySubiektConfig::PAYMENT_MAP));
        if (isset($payments['map'][$order->module])) {
            $payload['payment'] = $payments['map'][$order->module]['payment'];
            if ($payments['map'][$order->module]['payment_id'] !== null) {
                $payload['payment_id'] = $payments['map'][$order->module]['payment_id'];
            }
        }

        return $payload;
    }

    /**
     * Params customer/save dla adresu (kontrahent = klient + adres).
     *
     * @param Address $address
     * @param Customer $customer
     * @param Address|null $phoneFrom adres, z którego brać telefon (null = ten sam)
     *
     * @return array
     *
     * @throws InfinitySubiektException
     */
    public function party(Address $address, Customer $customer, ?Address $phoneFrom = null)
    {
        if ($this->config->get(InfinitySubiektConfig::SYMBOL_PREFIX) === '') {
            throw new InfinitySubiektException('prefiks symbolu kontrahenta jest pusty, ustaw go w konfiguracji modułu');
        }
        if (!Validate::isLoadedObject($address)) {
            throw new InfinitySubiektException('brak adresu');
        }
        if (!Validate::isLoadedObject($customer) || (int) $address->id_customer !== (int) $customer->id) {
            throw new InfinitySubiektException(sprintf('adres %d nie należy do klienta', (int) $address->id));
        }

        $country = (string) Country::getIsoById((int) $address->id_country);
        if ($country === '') {
            throw new InfinitySubiektException(sprintf('adres %d: brak kraju', (int) $address->id));
        }

        return [
            'symbol' => $this->symbol($address),
            'match' => $this->config->get(InfinitySubiektConfig::CUSTOMER_MODE),
            'firstname' => (string) $address->firstname,
            'lastname' => (string) $address->lastname,
            'email' => (string) $customer->email,
            'company' => (string) $address->company,
            'vat_number' => preg_replace('/\D+/', '', (string) $address->vat_number),
            'address' => (string) $address->address1,
            'address_number' => (string) $address->address2,
            'postcode' => (string) $address->postcode,
            'city' => (string) $address->city,
            'country' => $country,
            'phone' => $this->phone($phoneFrom !== null && Validate::isLoadedObject($phoneFrom) ? $phoneFrom : $address),
        ];
    }

    /**
     * Params customer/save dla adresu klienta (karta klienta).
     *
     * @param int $idAddress
     *
     * @return array
     *
     * @throws InfinitySubiektException
     */
    public function customerParty($idAddress)
    {
        $address = new Address((int) $idAddress);

        return $this->party($address, new Customer((int) $address->id_customer));
    }

    /**
     * Strony dokumentu zamówienia: nabywca z adresu do faktury z telefonem z adresu dostawy,
     * odbiorca tylko przy innym adresie dostawy.
     *
     * @param Order $order
     *
     * @return array id_address => params; pierwszy jest nabywca
     *
     * @throws InfinitySubiektException
     */
    public function orderParties(Order $order)
    {
        if (!Validate::isLoadedObject($order)) {
            throw new InfinitySubiektException('brak zamówienia');
        }

        $customer = new Customer((int) $order->id_customer);
        $invoice = new Address((int) $order->id_address_invoice);
        $delivery = new Address((int) $order->id_address_delivery);

        $parties = [(int) $invoice->id => $this->party($invoice, $customer, $delivery)];
        if ((int) $order->id_address_delivery !== (int) $order->id_address_invoice) {
            $parties[(int) $delivery->id] = $this->party($delivery, $customer);
        }

        return $parties;
    }

    /**
     * Params product/save dla produktu bez kombinacji (idAttr 0) albo jednej kombinacji.
     *
     * @param int $idProduct
     * @param int $idAttr
     *
     * @return array
     *
     * @throws InfinitySubiektException
     */
    public function product($idProduct, $idAttr)
    {
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $product = new Product((int) $idProduct, false, $idLang);
        if (!Validate::isLoadedObject($product)) {
            throw new InfinitySubiektException(sprintf('brak produktu %d', (int) $idProduct));
        }

        $ean = '';
        if ($idAttr) {
            $combination = new Combination((int) $idAttr);
            if (!Validate::isLoadedObject($combination) || (int) $combination->id_product !== (int) $product->id) {
                throw new InfinitySubiektException(sprintf('kombinacja %d nie należy do produktu %d', (int) $idAttr, (int) $idProduct));
            }
            $ean = trim((string) $combination->ean13);
        } elseif ($product->hasAttributes()) {
            throw new InfinitySubiektException(sprintf('produkt %d ma kombinacje, wyślij je osobno', (int) $idProduct));
        }

        $codes = $this->repository->productCodes([[(int) $idProduct, (int) $idAttr]]);
        $code = $codes[InfinitySubiektRepository::itemKey($idProduct, $idAttr)];
        // EAN produktu nie dla kombinacji: kod kreskowy wskazuje w Subiekcie jeden towar
        $ean = $idAttr ? $ean : trim((string) $product->ean13);
        if ($code === '' && $ean === '') {
            throw new InfinitySubiektException('brak referencji (kodu towaru) i EAN');
        }

        return [
            'code' => $code,
            'name' => (string) Product::getProductName((int) $idProduct, $idAttr ? (int) $idAttr : null, $idLang),
            'ean' => $ean,
            'price' => (float) Product::getPriceStatic((int) $idProduct, true, $idAttr ? (int) $idAttr : null, 2, null, false, false),
            'vat_rate' => (float) $product->getTaxesRate(),
            'unit' => $this->config->get(InfinitySubiektConfig::PRODUCT_UNIT),
            'update' => true,
        ];
    }

    /**
     * Pozycje zamówienia z qty > 0. Agent szuka towaru po EAN, potem po kodzie, więc błąd to brak obu.
     * EAN z kartoteki, a nie z pozycji: pozycja kombinacji bez własnego kodu ma EAN produktu,
     * który wskazałby w Subiekcie inny towar.
     *
     * @param Order $order
     *
     * @return array
     *
     * @throws InfinitySubiektException
     */
    private function orderProducts(Order $order)
    {
        $unit = $this->config->get(InfinitySubiektConfig::PRODUCT_UNIT);

        $rows = $order->getProducts();
        $items = [];
        foreach ($rows as $row) {
            $items[] = [(int) $row['product_id'], (int) $row['product_attribute_id']];
        }
        $eans = $items ? $this->repository->productEans($items) : [];

        $products = [];
        foreach ($rows as $row) {
            $qty = (int) $row['product_quantity'];
            if ($qty <= 0) {
                continue;
            }

            $code = trim((string) $row['product_reference']);
            $ean = $eans[InfinitySubiektRepository::itemKey($row['product_id'], $row['product_attribute_id'])];
            if ($code === '' && $ean === '') {
                throw new InfinitySubiektException(sprintf('pozycja "%s" nie ma referencji (kodu towaru) ani EAN', $row['product_name']));
            }

            $products[] = [
                'code' => $code,
                'qty' => $qty,
                'price' => round((float) $row['unit_price_tax_incl'], 2),
                'note' => $this->attributesNote($row, (int) $order->id_lang),
                'name' => (string) $row['product_name'],
                'ean' => $ean,
                'vat_rate' => $this->taxRate($row),
                'unit' => $unit,
            ];
        }

        if (!$products) {
            throw new InfinitySubiektException('zamówienie nie ma pozycji');
        }

        return $products;
    }

    /**
     * Pozycja kosztu dostawy: usługa jednorazowa albo zwykły towar z kartoteki.
     *
     * @param Order $order
     * @param float $shipping
     *
     * @return array|null null = pole trybu puste, bez pozycji w dokumencie
     */
    private function shippingLine(Order $order, $shipping)
    {
        $name = $this->config->get(InfinitySubiektConfig::SHIPPING_NAME);
        $line = [
            // agent dodaje wysyłkę po nadaniu rabatu, więc rabat zamówienia jej nie obniża
            'shipping' => true,
            'qty' => 1,
            'price' => $shipping,
            'note' => '',
            'name' => $name,
            'vat_rate' => $this->shippingVat($order),
            'unit' => $this->config->get(InfinitySubiektConfig::PRODUCT_UNIT),
        ];

        if ($this->config->get(InfinitySubiektConfig::SHIPPING_MODE) === InfinitySubiektConfig::SHIPPING_MODE_PRODUCT) {
            $code = $this->config->get(InfinitySubiektConfig::SHIPPING_CODE);
            if ($code === '') {
                return null;
            }

            return array_merge($line, ['code' => $code, 'name' => $name !== '' ? $name : $code, 'ean' => '']);
        }

        return $name === '' ? null : array_merge($line, ['one_time' => true]);
    }

    /**
     * Stawka VAT usługi jednorazowej wysyłki: z konfiguracji, a przy pustym polu stawka przewoźnika z zamówienia.
     * Zwykły towar ma stawkę z kartoteki Subiekta, agent jej nie używa.
     *
     * @param Order $order
     *
     * @return float
     */
    private function shippingVat(Order $order)
    {
        $vat = $this->config->get(InfinitySubiektConfig::SHIPPING_VAT);

        return $vat === '' ? (float) $order->carrier_tax_rate : (float) $vat;
    }

    /**
     * Koszt dostawy brutto do dokumentu: 0, gdy zamówienie ma regułę koszyka z darmową wysyłką
     * (PrestaShop zostawia wtedy koszt w total_shipping, a zdejmuje go w rabatach).
     * Inaczej koszt minus rabaty na dostawę zgłoszone przez hook sklepu (shippingDiscounts()).
     *
     * @param Order $order
     *
     * @return float
     *
     * @throws InfinitySubiektException
     */
    private function shippingPaid(Order $order)
    {
        $rules = (array) $order->getCartRules();
        foreach ($rules as $rule) {
            if (!empty($rule['free_shipping'])) {
                return 0.0;
            }
        }

        $shipping = round((float) $order->total_shipping_tax_incl, 2);
        if ($shipping <= 0 || !$rules) {
            return $shipping;
        }

        return round($shipping - $this->shippingDiscounts($order, $rules, $shipping), 2);
    }

    /**
     * Suma brutto rabatów, które obniżają dostawę, a nie towary. PrestaShop tego nie zapisuje
     * (order_cart_rule ma tylko wartość reguły), więc rozstrzyga sklep w hooku
     * actionInfinitySubiektShippingDiscounts: wpisuje do $params['discounts'] (referencja)
     * id_cart_rule => kwota brutto zdjęta z dostawy. Brak modułu z hookiem = 0.
     *
     * @param Order $order
     * @param array $rules wiersze Order::getCartRules()
     * @param float $shipping koszt dostawy brutto
     *
     * @return float
     *
     * @throws InfinitySubiektException błędna odpowiedź hooka, żeby nie wystawić złego dokumentu
     */
    private function shippingDiscounts(Order $order, array $rules, $shipping)
    {
        $discounts = [];
        Hook::exec(
            self::HOOK_SHIPPING_DISCOUNTS,
            [
                'order' => $order,
                'cart_rules' => $rules,
                'shipping' => $shipping,
                'discounts' => &$discounts,
            ],
            null,
            false,
            true,
            false,
            (int) $order->id_shop
        );
        if (!is_array($discounts) || !$discounts) {
            return 0.0;
        }

        $values = [];
        foreach ($rules as $rule) {
            $id = (int) $rule['id_cart_rule'];
            $values[$id] = (isset($values[$id]) ? $values[$id] : 0.0) + round((float) $rule['value'], 2);
        }

        $total = 0.0;
        foreach ($discounts as $id => $amount) {
            $id = (int) $id;
            if (!isset($values[$id])) {
                throw new InfinitySubiektException(sprintf('rabat na dostawę: reguła koszyka %d nie należy do zamówienia', $id));
            }
            if (!is_numeric($amount) || (float) $amount < 0) {
                throw new InfinitySubiektException(sprintf('rabat na dostawę: błędna kwota reguły koszyka %d', $id));
            }
            $amount = round((float) $amount, 2);
            if ($amount - $values[$id] >= 0.005) {
                throw new InfinitySubiektException(sprintf(
                    'rabat na dostawę: reguła koszyka %d zdejmuje %.2f, a ma w zamówieniu %.2f',
                    $id,
                    $amount,
                    $values[$id]
                ));
            }
            $total += $amount;
        }

        $total = round($total, 2);
        if ($total - $shipping >= 0.005) {
            throw new InfinitySubiektException(sprintf('rabat na dostawę (%.2f) większy niż koszt dostawy (%.2f)', $total, $shipping));
        }

        return $total;
    }

    /**
     * Wartość brutto pozycji towarowych po rabacie zamówienia:
     * zapłacono minus wysyłka i opakowanie. null = bez rabatu (wartość równa sumie pozycji).
     *
     * @param Order $order
     * @param array $products pozycje towarowe (bez wysyłki)
     * @param float $shipping koszt dostawy z shippingPaid() (po rabatach na dostawę)
     *
     * @return float|null
     *
     * @throws InfinitySubiektException
     */
    private function productsValue(Order $order, array $products, $shipping)
    {
        $total = 0.0;
        foreach ($products as $product) {
            $total += $product['price'] * $product['qty'];
        }
        $total = round($total, 2);

        $value = round(
            (float) $order->total_paid_tax_incl - $shipping - (float) $order->total_wrapping_tax_incl,
            2
        );
        if ($value < 0) {
            throw new InfinitySubiektException(sprintf('rabat zamówienia większy niż wartość towarów (%.2f)', $value));
        }

        return abs($value - $total) < 0.005 ? null : $value;
    }

    /**
     * Atrybuty kombinacji z nazwy pozycji („Nazwa - Kolor : Czarny” → „Kolor : Czarny”).
     *
     * @param array $row wiersz Order::getProducts()
     * @param int $idLang
     *
     * @return string
     */
    private function attributesNote(array $row, $idLang)
    {
        if (empty($row['product_attribute_id'])) {
            return '';
        }

        $name = (string) $row['product_name'];
        $base = (string) Product::getProductName((int) $row['product_id'], null, $idLang);
        if ($base !== '' && strpos($name, $base . ' - ') === 0) {
            return trim(substr($name, strlen($base) + 3));
        }

        return '';
    }

    /**
     * Stawka z pozycji, a gdy jej brak, z cen brutto i netto.
     *
     * @param array $row
     *
     * @return float
     */
    private function taxRate(array $row)
    {
        $rate = isset($row['tax_rate']) ? (float) $row['tax_rate'] : 0.0;
        $net = (float) $row['unit_price_tax_excl'];
        if ($rate <= 0 && $net > 0) {
            $rate = round(((float) $row['unit_price_tax_incl'] / $net - 1) * 100, 2);
        }

        return $rate;
    }

    /**
     * Symbol zapisany po wcześniejszej synchronizacji (w trybie match może być obcy),
     * inaczej {prefiks} {id klienta} {id adresu}.
     *
     * @param Address $address
     *
     * @return string
     */
    private function symbol(Address $address)
    {
        $row = $this->repository->findCustomer((int) $address->id);
        if ($row !== null && $row['symbol'] !== '') {
            return $row['symbol'];
        }

        return $this->config->get(InfinitySubiektConfig::SYMBOL_PREFIX)
            . ' ' . (int) $address->id_customer
            . ' ' . (int) $address->id;
    }

    /**
     * @param Address $address
     *
     * @return string
     */
    private function phone(Address $address)
    {
        $mobile = trim((string) $address->phone_mobile);

        return $mobile !== '' ? $mobile : trim((string) $address->phone);
    }
}
