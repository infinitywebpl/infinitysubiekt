<?php
/**
 * 1.0.2: hook actionInfinityMcpChangesExtend, czyli operacja paczki zmian subiekt.pull_purchase_price
 * (classes/mcp/InfinitySubiektMcpPullPriceOperation.php). registerHook() zakłada wpis hooka także bez modułu zmian.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param InfinitySubiekt $module
 *
 * @return bool
 */
function upgrade_module_1_0_2($module)
{
    return $module->registerHook('actionInfinityMcpChangesExtend');
}
