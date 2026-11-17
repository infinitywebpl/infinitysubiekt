<?php
/**
 * Blokady nazwane w tabeli infinitysubiekt_lock.
 *
 * Atomowy UPDATE + Affected_Rows zamiast GET_LOCK: blokada przeżywa zerwane połączenie
 * i sama wygasa po $ttl, gdy proces padnie bez zwolnienia.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektLock
{
    const TABLE = 'infinitysubiekt_lock';

    /** @var Db */
    private $db;

    /** @var InfinitySubiektClock */
    private $clock;

    /** @var string */
    private $table;

    /**
     * @param Db $db
     * @param InfinitySubiektClock $clock
     * @param string $prefix prefiks tabel PrestaShop
     */
    public function __construct(Db $db, InfinitySubiektClock $clock, $prefix)
    {
        $this->db = $db;
        $this->clock = $clock;
        $this->table = '`' . $prefix . self::TABLE . '`';
    }

    /**
     * @param string $name
     * @param int $ttl sekundy, po których zajęta blokada uchodzi za porzuconą
     *
     * @return bool
     */
    public function acquire($name, $ttl)
    {
        // wiersz zakłada instalacja; INSERT IGNORE na wypadek blokady dodanej później
        $this->db->execute(
            'INSERT IGNORE INTO ' . $this->table . ' (`name`, `locked_at`) VALUES (' . $this->quote($name) . ', NULL)',
            false
        );

        $this->db->execute(
            'UPDATE ' . $this->table . ' SET `locked_at` = ' . $this->quote($this->clock->now())
            . ' WHERE `name` = ' . $this->quote($name)
            . ' AND (`locked_at` IS NULL OR `locked_at` < ' . $this->quote($this->clock->at(-(int) $ttl)) . ')',
            false
        );

        return (int) $this->db->Affected_Rows() === 1;
    }

    /**
     * Odświeża zajętą blokadę: długi przebieg robiący postęp nie uchodzi za porzucony.
     *
     * @param string $name
     */
    public function touch($name)
    {
        $this->db->execute(
            'UPDATE ' . $this->table . ' SET `locked_at` = ' . $this->quote($this->clock->now())
            . ' WHERE `name` = ' . $this->quote($name) . ' AND `locked_at` IS NOT NULL',
            false
        );
    }

    /**
     * @param string $name
     */
    public function release($name)
    {
        $this->db->execute(
            'UPDATE ' . $this->table . ' SET `locked_at` = NULL WHERE `name` = ' . $this->quote($name),
            false
        );
    }

    /**
     * @param string $name
     *
     * @return string pusty, gdy blokada wolna
     */
    public function lockedAt($name)
    {
        return (string) $this->db->getValue(
            'SELECT `locked_at` FROM ' . $this->table . ' WHERE `name` = ' . $this->quote($name),
            false
        );
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private function quote($value)
    {
        return '\'' . pSQL($value) . '\'';
    }
}
