<?php
/**
 * 1.0.1: hook actionInfinityMcpExtend, czyli odczyt stanu modułu przez serwer MCP (classes/mcp/).
 * registerHook() zakłada wpis hooka także bez zainstalowanego modułu infinitymcp.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param InfinitySubiekt $module
 *
 * @return bool
 */
function upgrade_module_1_0_1($module)
{
    return $module->registerHook('actionInfinityMcpExtend');
}
