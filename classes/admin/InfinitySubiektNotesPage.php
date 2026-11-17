<?php
/**
 * Zakładka „Wiedza dla AI” strony konfiguracji: notatki o tym, jak firma prowadzi Subiekta, które narzędzie MCP
 * subiekt_query oddaje modelowi (InfinitySubiektQueryNotes). Pracownik dopisuje, poprawia, wyłącza i usuwa notatki;
 * notatki zaproponowane przez model trafiają tu po akceptacji paczki zmian.
 *
 * Formularz wysyła się na adres zakładki (token AdminModules w adresie), więc po zapisie zostajemy tutaj.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektNotesPage
{
    const SUBMIT_SAVE = 'submitInfsubiektNote';
    const SUBMIT_DELETE = 'deleteInfsubiektNote';
    const PARAM_EDIT = 'edit_note';

    const TRANSLATION_SOURCE = 'infinitysubiektnotespage';

    /** @var Module */
    private $module;

    /** @var InfinitySubiektQueryNotes */
    private $notes;

    /** @var InfinitySubiektView */
    private $view;

    /** @var Context */
    private $context;

    /** @var array|null wartości formularza po nieudanym zapisie */
    private $submitted = null;

    public function __construct(Module $module, InfinitySubiektQueryNotes $notes, InfinitySubiektView $view, Context $context)
    {
        $this->module = $module;
        $this->notes = $notes;
        $this->view = $view;
        $this->context = $context;
    }

    /**
     * @return string komunikat HTML ('' bez wysłanego formularza)
     */
    public function process()
    {
        if (Tools::isSubmit(self::SUBMIT_DELETE)) {
            $id = (int) Tools::getValue('id_note');

            return $id && $this->notes->delete($id)
                ? $this->module->displayConfirmation($this->l('Notatka usunięta.'))
                : $this->module->displayError($this->l('Nie udało się usunąć notatki.'));
        }

        if (!Tools::isSubmit(self::SUBMIT_SAVE)) {
            return '';
        }

        $id = (int) Tools::getValue('id_note');
        $title = trim((string) Tools::getValue('note_title'));
        $content = trim((string) Tools::getValue('note_content'));
        $active = (bool) Tools::getValue('note_active');

        $errors = [];
        foreach (InfinitySubiektQueryNotes::invalidFields($title, $content) as $field) {
            $errors[] = $field === 'title'
                ? sprintf($this->l('Tytuł: od 1 do %d znaków.'), InfinitySubiektQueryNotes::MAX_TITLE)
                : sprintf($this->l('Treść: od 1 do %d znaków.'), InfinitySubiektQueryNotes::MAX_CONTENT);
        }
        if ($id && $this->notes->find($id) === null) {
            $errors[] = $this->l('Ta notatka już nie istnieje.');
        }
        if ($errors) {
            $this->submitted = ['id_note' => $id, 'title' => $title, 'content' => $content, 'active' => $active];

            return $this->module->displayError(implode('<br>', array_map([$this, 'escape'], $errors)));
        }

        $idEmployee = isset($this->context->employee->id) ? (int) $this->context->employee->id : null;
        $ok = $id
            ? $this->notes->update($id, $title, $content, $active, $idEmployee)
            : $this->notes->create($title, $content, $active, InfinitySubiektQueryNotes::SOURCE_EMPLOYEE, $idEmployee) > 0;

        return $ok
            ? $this->module->displayConfirmation($this->l('Notatka zapisana.'))
            : $this->module->displayError($this->l('Nie udało się zapisać notatki.'));
    }

    /**
     * @return string
     */
    public function render()
    {
        $tabUrl = InfinitySubiektSettingsPage::tabUrl($this->module, InfinitySubiektSettingsPage::TAB_NOTES);

        $form = $this->submitted;
        if ($form === null) {
            $edit = (int) Tools::getValue(self::PARAM_EDIT);
            $note = $edit ? $this->notes->find($edit) : null;
            $form = $note
                ? ['id_note' => (int) $note['id_note'], 'title' => $note['title'], 'content' => $note['content'], 'active' => (bool) $note['active']]
                : ['id_note' => 0, 'title' => '', 'content' => '', 'active' => true];
        }

        $notes = [];
        foreach ($this->notes->all() as $row) {
            $row['edit_url'] = $tabUrl . '&' . self::PARAM_EDIT . '=' . (int) $row['id_note'];
            $notes[] = $row;
        }

        return $this->view->render('admin/tab_notes.tpl', [
            'notes' => $notes,
            'form' => $form,
            'action_url' => $tabUrl,
            'new_url' => $tabUrl,
            'submit_save' => self::SUBMIT_SAVE,
            'submit_delete' => self::SUBMIT_DELETE,
            'max_title' => InfinitySubiektQueryNotes::MAX_TITLE,
            'max_content' => InfinitySubiektQueryNotes::MAX_CONTENT,
            'max_for_model' => InfinitySubiektQueryNotes::MAX_FOR_MODEL,
            'source_ai' => InfinitySubiektQueryNotes::SOURCE_AI,
        ]);
    }

    /**
     * @param string $text
     *
     * @return string
     */
    private function escape($text)
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
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
