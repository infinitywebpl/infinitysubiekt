<?php
/**
 * Notatki o tym, jak firma prowadzi Subiekta (np. które dokumenty niosą ilości zakupu), dla narzędzia MCP subiekt_query.
 *
 * Kod narzędzia i agenta jest ogólny dla Subiekta GT; wiedzę o konkretnej firmie trzyma ta tabela. Notatkę dopisuje
 * albo poprawia pracownik w zakładce „Wiedza dla AI”, a model proponuje ją operacją paczki zmian subiekt.save_query_note,
 * którą zapisuje dopiero akceptacja w BO. Aktywne notatki wracają do modelu w describe i profile.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektQueryNotes
{
    const TABLE = 'infinitysubiekt_query_note';

    const MAX_TITLE = 128;
    const MAX_CONTENT = 4000;

    /** Tyle aktywnych notatek dostaje model; więcej to znak, że trzeba je scalić. */
    const MAX_FOR_MODEL = 50;

    const SOURCE_EMPLOYEE = 'employee';
    const SOURCE_AI = 'ai';

    /** @var Db */
    private $db;

    /** @var InfinitySubiektClock */
    private $clock;

    /** @var string */
    private $table;

    /**
     * @param Db $db
     * @param InfinitySubiektClock $clock
     * @param string $prefix
     */
    public function __construct(Db $db, InfinitySubiektClock $clock, $prefix)
    {
        $this->db = $db;
        $this->clock = $clock;
        $this->table = '`' . $prefix . self::TABLE . '`';
    }

    /**
     * @param bool $activeOnly
     * @param int|null $limit
     *
     * @return array wiersze tabeli, od ostatnio zmienionych
     */
    public function all($activeOnly = false, $limit = null)
    {
        $rows = $this->db->executeS(
            'SELECT * FROM ' . $this->table
            . ($activeOnly ? ' WHERE `active` = 1' : '')
            . ' ORDER BY `updated_at` DESC, `id_note` DESC'
            . ($limit ? ' LIMIT ' . (int) $limit : ''),
            true,
            false
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Aktywne notatki w postaci dla modelu.
     *
     * @return array lista ['id_note', 'title', 'content', 'source', 'updated_at']
     */
    public function forModel()
    {
        $notes = [];
        foreach ($this->all(true, self::MAX_FOR_MODEL) as $row) {
            $notes[] = [
                'id_note' => (int) $row['id_note'],
                'title' => $row['title'],
                'content' => $row['content'],
                'source' => $row['source'],
                'updated_at' => $row['updated_at'],
            ];
        }

        return $notes;
    }

    /**
     * @return int
     */
    public function countActive()
    {
        return (int) $this->db->getValue('SELECT COUNT(*) FROM ' . $this->table . ' WHERE `active` = 1', false);
    }

    /**
     * @param int $id
     *
     * @return array|null
     */
    public function find($id)
    {
        $row = $this->db->getRow('SELECT * FROM ' . $this->table . ' WHERE `id_note` = ' . (int) $id, false);

        return $row ?: null;
    }

    /**
     * @param string $title
     * @param string $content
     * @param bool $active
     * @param string $source SOURCE_*
     * @param int|null $idEmployee kto zapisał albo zaakceptował
     *
     * @return int id nowej notatki, 0 przy błędzie
     */
    public function create($title, $content, $active, $source, $idEmployee)
    {
        $now = $this->clock->now();
        $ok = $this->db->insert(self::TABLE, [
            'title' => pSQL($title, true),
            'content' => pSQL($content, true),
            'active' => (int) (bool) $active,
            'source' => pSQL($source === self::SOURCE_AI ? self::SOURCE_AI : self::SOURCE_EMPLOYEE),
            'id_employee' => $idEmployee ? (int) $idEmployee : null,
            'created_at' => pSQL($now),
            'updated_at' => pSQL($now),
        ], true);

        return $ok ? (int) $this->db->Insert_ID() : 0;
    }

    /**
     * @param int $id
     * @param string $title
     * @param string $content
     * @param bool $active
     * @param int|null $idEmployee
     *
     * @return bool
     */
    public function update($id, $title, $content, $active, $idEmployee)
    {
        return (bool) $this->db->update(self::TABLE, [
            'title' => pSQL($title, true),
            'content' => pSQL($content, true),
            'active' => (int) (bool) $active,
            'id_employee' => $idEmployee ? (int) $idEmployee : null,
            'updated_at' => pSQL($this->clock->now()),
        ], '`id_note` = ' . (int) $id, 0, true);
    }

    /**
     * @param int $id
     *
     * @return bool
     */
    public function delete($id)
    {
        return (bool) $this->db->delete(self::TABLE, '`id_note` = ' . (int) $id);
    }

    /**
     * @param mixed $title
     * @param mixed $content
     *
     * @return string[] nazwy błędnych pól: title, content (komunikat dobiera strona BO albo operacja MCP)
     */
    public static function invalidFields($title, $content)
    {
        $fields = [];
        if (!is_string($title) || trim($title) === '' || Tools::strlen(trim($title)) > self::MAX_TITLE) {
            $fields[] = 'title';
        }
        if (!is_string($content) || trim($content) === '' || Tools::strlen(trim($content)) > self::MAX_CONTENT) {
            $fields[] = 'content';
        }

        return $fields;
    }
}
