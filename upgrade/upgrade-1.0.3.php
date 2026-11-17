<?php
/**
 * 1.0.3: tabela infinitysubiekt_query_note (notatki o tym, jak firma prowadzi Subiekta, dla narzędzia MCP subiekt_query).
 * InfinitySubiektSchema::install() tworzy tylko brakujące tabele (CREATE TABLE IF NOT EXISTS).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param InfinitySubiekt $module
 *
 * @return bool
 */
function upgrade_module_1_0_3($module)
{
    $schema = new InfinitySubiektSchema(Db::getInstance(), _DB_PREFIX_, InfinitySubiektCompat::dbCharset());

    return $schema->install();
}
