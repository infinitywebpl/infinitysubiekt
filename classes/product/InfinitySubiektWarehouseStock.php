<?php
/**
 * Stan produktu z zaawansowanym zarządzaniem magazynem: przez StockAvailable::setQuantityOnWarehouse(),
 * gdy sklep ją ma, inaczej ruch magazynowy na różnicę, jak w AdminStockManagement.
 * StockAvailable PrestaShop przelicza potem sam.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektWarehouseStock
{
    /**
     * Doprowadza stan użytkowy pozycji w magazynie do podanej ilości.
     *
     * @param int $idProduct
     * @param int $idAttr
     * @param int $idWarehouse
     * @param int $quantity stan docelowy
     * @param float $price cena zakupu netto dla przyjęcia na magazyn
     *
     * @throws InfinitySubiektException
     */
    public function setQuantity($idProduct, $idAttr, $idWarehouse, $quantity, $price)
    {
        $warehouse = new Warehouse((int) $idWarehouse);
        if (!Validate::isLoadedObject($warehouse)) {
            throw new InfinitySubiektException(sprintf('magazyn sklepu %d nie istnieje, wybierz go w konfiguracji modułu', (int) $idWarehouse));
        }

        // metody nie ma w rdzeniu PrestaShop: gdy dostarcza ją sklep (override), ona ustawia stan
        $hasSetter = method_exists('StockAvailable', 'setQuantityOnWarehouse');
        $difference = 0;
        if (!$hasSetter) {
            $manager = StockManagerFactory::getManager();
            $current = (int) $manager->getProductPhysicalQuantities($idProduct, $idAttr, [(int) $warehouse->id], true);
            $difference = (int) $quantity - $current;
            if ($difference === 0) {
                return;
            }
        }

        // ruch magazynowy wymaga pracownika, a cron działa bez niego
        $context = Context::getContext();
        $previousEmployee = $context->employee;
        if (!Validate::isLoadedObject($previousEmployee)) {
            $context->employee = $this->cronEmployee();
        }

        try {
            if ($hasSetter) {
                StockAvailable::setQuantityOnWarehouse($idProduct, $idAttr, (int) $quantity, (int) $warehouse->id);
            } elseif ($difference > 0) {
                $this->add($manager, $idProduct, $idAttr, $warehouse, $difference, $price);
            } else {
                $this->remove($manager, $idProduct, $idAttr, $warehouse, -$difference);
            }
        } finally {
            $context->employee = $previousEmployee;
        }

        $this->ensureLocation($idProduct, $idAttr, $warehouse);
        StockAvailable::synchronize((int) $idProduct);
    }

    /**
     * Bez tego powiązania PrestaShop nie liczy magazynu do stanu produktu.
     *
     * @param int $idProduct
     * @param int $idAttr
     * @param Warehouse $warehouse
     */
    private function ensureLocation($idProduct, $idAttr, Warehouse $warehouse)
    {
        if (WarehouseProductLocation::getIdByProductAndWarehouse($idProduct, $idAttr, $warehouse->id)) {
            return;
        }

        $location = new WarehouseProductLocation();
        $location->id_product = (int) $idProduct;
        $location->id_product_attribute = (int) $idAttr;
        $location->id_warehouse = (int) $warehouse->id;
        $location->save();
    }

    /**
     * @param StockManagerInterface $manager
     * @param int $idProduct
     * @param int $idAttr
     * @param Warehouse $warehouse
     * @param int $quantity
     * @param float $price
     *
     * @throws InfinitySubiektException
     */
    private function add($manager, $idProduct, $idAttr, Warehouse $warehouse, $quantity, $price)
    {
        // StockManager::addProduct() odrzuca przyjęcie bez ceny
        if ((float) $price <= 0) {
            throw new InfinitySubiektException('przyjęcie na magazyn wymaga ceny zakupu większej od 0');
        }

        $added = $manager->addProduct(
            $idProduct,
            $idAttr,
            $warehouse,
            $quantity,
            (int) Configuration::get('PS_STOCK_MVT_INC_REASON_DEFAULT'),
            (float) $price
        );
        if (!$added) {
            throw new InfinitySubiektException('nie udało się przyjąć towaru na magazyn sklepu');
        }
    }

    /**
     * @param StockManagerInterface $manager
     * @param int $idProduct
     * @param int $idAttr
     * @param Warehouse $warehouse
     * @param int $quantity
     *
     * @throws InfinitySubiektException
     */
    private function remove($manager, $idProduct, $idAttr, Warehouse $warehouse, $quantity)
    {
        $removed = $manager->removeProduct(
            $idProduct,
            $idAttr,
            $warehouse,
            $quantity,
            (int) Configuration::get('PS_STOCK_MVT_DEC_REASON_DEFAULT')
        );
        if (!$removed) {
            throw new InfinitySubiektException('nie udało się wydać towaru z magazynu sklepu');
        }
    }

    /**
     * @return Employee
     *
     * @throws InfinitySubiektException
     */
    private function cronEmployee()
    {
        $employees = Employee::getEmployeesByProfile(_PS_ADMIN_PROFILE_, true);
        if (!$employees) {
            throw new InfinitySubiektException('brak aktywnego SuperAdmina do zapisania ruchu magazynowego');
        }

        return new Employee((int) $employees[0]['id_employee']);
    }
}
