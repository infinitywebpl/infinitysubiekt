<?php
/**
 * Towar w BO: panel na karcie produktu z kombinacjami, kodem, stanem i ceną zakupu.
 * Render pokazuje tylko dane z bazy; agenta wołają akcje AJAX.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektProductPanel
{
    const TRANSLATION_SOURCE = 'infinitysubiektproductpanel';

    /** @var Module */
    private $module;

    /** @var InfinitySubiektRepository */
    private $repository;

    /** @var InfinitySubiektProductStock */
    private $productStock;

    /** @var InfinitySubiektMessages */
    private $messages;

    /** @var InfinitySubiektView */
    private $view;

    /** @var Context */
    private $context;

    public function __construct(
        Module $module,
        InfinitySubiektRepository $repository,
        InfinitySubiektProductStock $productStock,
        InfinitySubiektMessages $messages,
        InfinitySubiektView $view,
        Context $context
    ) {
        $this->module = $module;
        $this->repository = $repository;
        $this->productStock = $productStock;
        $this->messages = $messages;
        $this->view = $view;
        $this->context = $context;
    }

    /**
     * @param int $idProduct 0 = produkt jeszcze niezapisany
     *
     * @return string
     */
    public function renderPanel($idProduct)
    {
        return $this->view->render('hook/admin_product.tpl', [
            'bs4' => InfinitySubiektCompat::isPs17(),
            'id_product' => (int) $idProduct,
            'ajax_url' => $this->context->link->getAdminLink(InfinitySubiektInstaller::ADMIN_TAB),
            'params_json' => InfinitySubiektJson::encode(['id_product' => (int) $idProduct]),
            'table_html' => $idProduct ? $this->renderTable($idProduct) : '',
        ]);
    }

    /**
     * @param int $idProduct
     *
     * @return string
     */
    public function renderTable($idProduct)
    {
        $idLang = (int) $this->context->language->id;
        $product = new Product((int) $idProduct, false, $idLang);
        if (!Validate::isLoadedObject($product)) {
            return $this->view->render('hook/product_table.tpl', ['rows' => [], 'advanced_stock' => false, 'can_pull' => false]);
        }

        $items = $this->repository->productItems($idProduct);
        $codes = $this->repository->productCodes($items);
        $eans = $this->repository->productEans($items);
        $synced = $this->repository->findProducts($idProduct);

        $designations = [];
        if ($product->hasAttributes()) {
            $combinations = $product->getAttributesResume($idLang);
            foreach (is_array($combinations) ? $combinations : [] as $combination) {
                $designations[(int) $combination['id_product_attribute']] = $combination['attribute_designation'];
            }
        }

        $rows = [];
        foreach ($items as $item) {
            $idAttr = (int) $item[1];
            $sync = isset($synced[$idAttr]) ? $synced[$idAttr] : null;

            $rows[] = [
                'id_product_attribute' => $idAttr,
                'label' => $idAttr
                    ? (isset($designations[$idAttr]) ? $designations[$idAttr] : '#' . $idAttr)
                    : (string) $product->name,
                'code' => $codes[InfinitySubiektRepository::itemKey($idProduct, $idAttr)],
                'ean' => $eans[InfinitySubiektRepository::itemKey($idProduct, $idAttr)],
                'quantity' => (int) StockAvailable::getQuantityAvailableByProduct((int) $idProduct, $idAttr),
                'purchase_price' => $this->productStock->purchasePrice($product, $idAttr),
                'synced_at' => $sync ? $sync['synced_at'] : '',
                'subiekt_id' => $sync && $sync['subiekt_id'] ? (int) $sync['subiekt_id'] : 0,
                'params_json' => InfinitySubiektJson::encode(['id_product_attribute' => $idAttr]),
                'preview_json' => InfinitySubiektJson::encode(['type' => 'product', 'id_product_attribute' => $idAttr]),
            ];
        }

        return $this->view->render('hook/product_table.tpl', [
            'rows' => $rows,
            'advanced_stock' => $this->productStock->usesAdvancedStock($idProduct),
            'can_pull' => $this->productStock->canPull($idProduct),
        ]);
    }

    /**
     * @param array $results itemKey => wynik product/save
     *
     * @return string[]
     */
    public function saveMessages(array $results)
    {
        if (!$results) {
            return [$this->l('Brak pozycji do wysłania.')];
        }

        $messages = [];
        foreach ($results as $key => $result) {
            $messages[] = $this->itemLabel($key) . ' ' . (isset($result['error'])
                ? $this->messages->errorText($result)
                : sprintf(
                    !empty($result['created']) ? $this->l('założono towar %s.') : $this->l('zaktualizowano towar %s.'),
                    (string) $result['code']
                ));
        }

        return $messages;
    }

    /**
     * @param array $result wynik product/stock_set
     *
     * @return string
     */
    public function pushMessage(array $result)
    {
        if (isset($result['error'])) {
            return $this->messages->errorText($result);
        }

        $before = isset($result['before']) ? (string) $result['before'] : '?';
        $after = isset($result['after']) ? (string) $result['after'] : '?';
        if (!empty($result['document'])) {
            return sprintf($this->l('Stan w Subiekcie: %s → %s, dokument %s.'), $before, $after, (string) $result['document']);
        }

        return sprintf($this->l('Stan w Subiekcie bez zmian (%s), dokument nie powstał.'), $after);
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
     * @param string $key itemKey
     *
     * @return string
     */
    private function itemLabel($key)
    {
        $parts = explode('-', $key);
        $idAttr = isset($parts[1]) ? (int) $parts[1] : 0;

        return $idAttr ? sprintf($this->l('Kombinacja %d:'), $idAttr) : $this->l('Produkt:');
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
