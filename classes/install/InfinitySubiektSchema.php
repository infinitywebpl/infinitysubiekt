<?php
/**
 * Tabele modułu. Tworzone przez CREATE TABLE IF NOT EXISTS i nigdy
 * nieusuwane przy odinstalowaniu.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektSchema
{
    /** @var Db */
    private $db;

    /** @var string */
    private $prefix;

    /** @var string */
    private $charset;

    /** @var string */
    private $error = '';

    /**
     * @param Db $db
     * @param string $prefix
     * @param string $charset
     */
    public function __construct(Db $db, $prefix, $charset)
    {
        $this->db = $db;
        $this->prefix = $prefix;
        $this->charset = $charset;
    }

    /**
     * @return bool
     */
    public function install()
    {
        foreach ($this->queries() as $query) {
            if (!$this->db->execute($query, false)) {
                $this->error = $this->db->getMsgError();

                return false;
            }
        }

        return true;
    }

    /**
     * @return string
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * @return string[]
     */
    private function queries()
    {
        $queries = [];
        foreach ($this->tables() as $name => $definition) {
            $queries[] = 'CREATE TABLE IF NOT EXISTS `' . $this->prefix . $name . '` (' . $definition . ')'
                . ' ENGINE=InnoDB DEFAULT CHARSET=' . $this->charset;
        }

        $queries[] = 'INSERT IGNORE INTO `' . $this->prefix . 'infinitysubiekt_lock` (`name`, `locked_at`)'
            . ' VALUES (\'cron\', NULL)';

        return $queries;
    }

    /**
     * InnoDB jest wymagany: claim zadania używa SELECT … FOR UPDATE w transakcji.
     *
     * @return array nazwa tabeli bez prefiksu => definicja kolumn
     */
    private function tables()
    {
        return [
            'subiekt_tasks' => '
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `method` VARCHAR(255) NOT NULL,
                `params` LONGTEXT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT \'pending\',
                `result` LONGTEXT NULL,
                `error` TEXT NULL,
                `order_id` BIGINT UNSIGNED NULL,
                `priority` TINYINT NOT NULL DEFAULT 0,
                `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `locked_at` DATETIME NULL,
                `expires_at` DATETIME NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                KEY `idx_claim` (`status`, `priority`, `id`),
                KEY `idx_order` (`order_id`, `method`, `status`)',

            'infinitysubiekt_order' => '
                `id_order` INT UNSIGNED NOT NULL,
                `document_type` VARCHAR(20) NULL,
                `invoice_number` VARCHAR(64) NULL,
                `issued_at` DATETIME NULL,
                `pdf_at` DATETIME NULL,
                `locked_at` DATETIME NULL,
                PRIMARY KEY (`id_order`),
                KEY `idx_invoice_number` (`invoice_number`)',

            'infinitysubiekt_customer' => '
                `id_address` INT UNSIGNED NOT NULL,
                `id_customer` INT UNSIGNED NOT NULL,
                `symbol` VARCHAR(64) NOT NULL,
                `subiekt_id` INT UNSIGNED NULL,
                `matched_by` VARCHAR(20) NULL,
                `synced_at` DATETIME NOT NULL,
                PRIMARY KEY (`id_address`),
                KEY `idx_customer` (`id_customer`),
                KEY `idx_symbol` (`symbol`)',

            'infinitysubiekt_product' => '
                `id_product` INT UNSIGNED NOT NULL,
                `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
                `code` VARCHAR(64) NOT NULL,
                `subiekt_id` INT UNSIGNED NULL,
                `synced_at` DATETIME NOT NULL,
                PRIMARY KEY (`id_product`, `id_product_attribute`),
                KEY `idx_code` (`code`)',

            // wiedza o tym, jak firma prowadzi Subiekta, dla subiekt_query (InfinitySubiektQueryNotes)
            'infinitysubiekt_query_note' => '
                `id_note` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `title` VARCHAR(128) NOT NULL,
                `content` TEXT NOT NULL,
                `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
                `source` VARCHAR(16) NOT NULL DEFAULT \'employee\',
                `id_employee` INT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id_note`)',

            'infinitysubiekt_lock' => '
                `name` VARCHAR(64) NOT NULL,
                `locked_at` DATETIME NULL,
                PRIMARY KEY (`name`)',
        ];
    }
}
