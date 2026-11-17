<?php
/**
 * Endpoint agenta Subiekta. Adres: getModuleLink('infinitysubiekt', 'agent').
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektAgentModuleFrontController extends ModuleFrontController
{
    /**
     * Celowo bez parent::init(): bez trybu serwisowego, przekierowań, koszyka i cookie.
     * Endpoint zawsze kończy żądanie.
     */
    public function init()
    {
        /** @var InfinitySubiekt $module */
        $module = $this->module;
        $module->createAgentEndpoint()->handle(new InfinitySubiektRequest());
    }
}
