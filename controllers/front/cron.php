<?php
/**
 * Wejście HTTP crona. Adres: getModuleLink('infinitysubiekt', 'cron', ['token' => …]).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektCronModuleFrontController extends ModuleFrontController
{
    /**
     * Celowo bez parent::init(): bez trybu serwisowego, przekierowań, koszyka i cookie.
     * Endpoint zawsze kończy żądanie.
     */
    public function init()
    {
        /** @var InfinitySubiekt $module */
        $module = $this->module;
        $module->createCronEndpoint()->handle(new InfinitySubiektRequest());
    }
}
