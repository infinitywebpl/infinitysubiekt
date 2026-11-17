<?php
/**
 * Karta zamówienia: dokument, kontrahenci i zadania zamówienia.
 * Render pokazuje tylko dane z bazy; agenta wołają akcje AJAX.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektOrderPanel
{
    const TRANSLATION_SOURCE = 'infinitysubiektorderpanel';

    /** @var Module */
    private $module;

    /** @var InfinitySubiektConfig */
    private $config;

    /** @var InfinitySubiektRepository */
    private $repository;

    /** @var InfinitySubiektTaskQueue */
    private $queue;

    /** @var InfinitySubiektCustomerPanel */
    private $customerPanel;

    /** @var InfinitySubiektTaskList */
    private $taskList;

    /** @var InfinitySubiektDocumentTypeResolver */
    private $documentType;

    /** @var InfinitySubiektMessages */
    private $messages;

    /** @var InfinitySubiektView */
    private $view;

    /** @var Context */
    private $context;

    public function __construct(
        Module $module,
        InfinitySubiektConfig $config,
        InfinitySubiektRepository $repository,
        InfinitySubiektTaskQueue $queue,
        InfinitySubiektCustomerPanel $customerPanel,
        InfinitySubiektTaskList $taskList,
        InfinitySubiektDocumentTypeResolver $documentType,
        InfinitySubiektMessages $messages,
        InfinitySubiektView $view,
        Context $context
    ) {
        $this->module = $module;
        $this->config = $config;
        $this->repository = $repository;
        $this->queue = $queue;
        $this->customerPanel = $customerPanel;
        $this->taskList = $taskList;
        $this->documentType = $documentType;
        $this->messages = $messages;
        $this->view = $view;
        $this->context = $context;
    }

    /**
     * @param int $idOrder
     *
     * @return string
     */
    public function renderPanel($idOrder)
    {
        return $this->view->render('hook/admin_order.tpl', [
            'bs4' => InfinitySubiektCompat::isPs177(),
            'ajax_url' => $this->context->link->getAdminLink(InfinitySubiektInstaller::ADMIN_TAB),
            'params_json' => InfinitySubiektJson::encode(['id_order' => (int) $idOrder]),
            'body_html' => $this->renderBody($idOrder),
        ]);
    }

    /**
     * Treść panelu, odświeżana w całości po akcjach AJAX.
     *
     * @param int $idOrder
     * @param array|null $match wynik InfinitySubiektDocumentMatch::find() do pokazania pod dokumentem
     *
     * @return string
     */
    public function renderBody($idOrder, ?array $match = null)
    {
        $order = new Order((int) $idOrder);
        if (!Validate::isLoadedObject($order)) {
            return '';
        }

        $row = $this->repository->findOrder($idOrder);
        $number = $row !== null ? (string) $row['invoice_number'] : '';
        $active = $number === '' ? $this->queue->findActive($idOrder, InfinitySubiektClient::METHOD_ORDER_ADD) : null;
        $configured = $this->documentLabel($this->documentType->resolve($order));

        return $this->view->render('hook/order_body.tpl', [
            'number' => $number,
            'document_type' => $number !== '' ? $this->documentLabel((string) $row['document_type']) : '',
            'issued_at' => $number !== '' ? (string) $row['issued_at'] : '',
            'pdf_at' => $number !== '' ? (string) $row['pdf_at'] : '',
            'pdf_url' => $this->context->link->getAdminLink(InfinitySubiektInstaller::ADMIN_TAB)
                . '&ajax=1&action=downloadPdf&id_order=' . (int) $idOrder,
            'configured_type' => $configured,
            'active_task_id' => $active !== null ? (int) $active['id'] : 0,
            'confirm_text' => sprintf($this->l('Wystawić w Subiekcie dokument: %s?'), $configured),
            'match' => $number === '' && $match !== null && !isset($match['error']) ? $this->matchView($match) : null,
            'preview_json' => InfinitySubiektJson::encode(['type' => 'order']),
            'prefix_missing' => $this->config->get(InfinitySubiektConfig::SYMBOL_PREFIX) === '',
            'customer_mode' => $this->config->get(InfinitySubiektConfig::CUSTOMER_MODE),
            'customers_html' => $this->customerPanel->renderOrderTable($idOrder),
            'tasks_html' => $this->taskList->renderTable(null, (int) $idOrder),
        ]);
    }

    /**
     * @param array $result wynik InfinitySubiektClient::addInvoice()
     *
     * @return string
     */
    public function invoiceMessage(array $result)
    {
        $number = isset($result['number']) ? (string) $result['number'] : '';
        $taskId = isset($result['task_id']) ? (int) $result['task_id'] : 0;

        switch ($result['status']) {
            case InfinitySubiektClient::INVOICE_OK:
                return sprintf($this->l('Wystawiono dokument %s.'), $number);
            case InfinitySubiektClient::INVOICE_ALREADY:
                return sprintf($this->l('Zamówienie ma już dokument %s.'), $number);
            case InfinitySubiektClient::INVOICE_ADOPTED:
                return sprintf($this->l('Przejęto numer dokumentu %s wystawionego wcześniej (zadanie #%d).'), $number, $taskId);
            case InfinitySubiektClient::INVOICE_IN_PROGRESS:
                return $taskId
                    ? sprintf($this->l('Dokument jest w trakcie wystawiania (zadanie #%d). Spróbuj ponownie za chwilę.'), $taskId)
                    : $this->l('To zamówienie jest właśnie wystawiane w innym oknie.');
        }

        if ($result['error'] === InfinitySubiektClient::ERROR_TIMEOUT) {
            return sprintf(
                $this->l('Agent nie odpowiedział w czasie. Zadanie #%d zostaje w kolejce. Kliknij „Wystaw” ponownie za chwilę: gotowy numer zostanie przejęty, drugi dokument nie powstanie.'),
                $taskId
            );
        }

        return $this->messages->errorText($result);
    }

    /**
     * Komunikat po wyszukaniu dokumentu w Subiekcie.
     *
     * @param array $match wynik InfinitySubiektDocumentMatch::find()
     *
     * @return string
     */
    public function matchMessage(array $match)
    {
        if (isset($match['error'])) {
            if ($match['error'] === InfinitySubiektClient::ERROR_TIMEOUT) {
                return sprintf($this->l('Agent nie odpowiedział w czasie (zadanie #%d). Spróbuj ponownie za chwilę.'), isset($match['task_id']) ? (int) $match['task_id'] : 0);
            }

            return $this->messages->errorText($match);
        }

        $count = count($match['candidates']);
        $text = $count
            ? sprintf($this->l('Znaleziono w Subiekcie dokumentów: %d. Sprawdź zgodność i przypisz właściwy.'), $count)
            : $this->l('Nie znaleziono w Subiekcie dokumentu pasującego do zamówienia.');
        if ($match['skipped']) {
            $text .= ' ' . sprintf($this->l('Pominięte kolumny (brak w bazie Subiekta): %s.'), implode(', ', $match['skipped']));
        }

        return $text;
    }

    /**
     * Komunikat po przypisaniu dokumentu z Subiekta.
     *
     * @param array $result wynik InfinitySubiektDocumentMatch::assign()
     *
     * @return string
     */
    public function assignMessage(array $result)
    {
        switch ($result['status']) {
            case InfinitySubiektDocumentMatch::ASSIGN_OK:
                return sprintf($this->l('Przypisano dokument %s do zamówienia.'), $result['number']);
            case InfinitySubiektDocumentMatch::ASSIGN_ALREADY:
                return sprintf($this->l('Zamówienie ma już dokument %s.'), $result['number']);
            case InfinitySubiektDocumentMatch::ASSIGN_IN_PROGRESS:
                return !empty($result['task_id'])
                    ? sprintf($this->l('Dokument jest w trakcie wystawiania (zadanie #%d).'), (int) $result['task_id'])
                    : $this->l('To zamówienie jest właśnie wystawiane albo przypisywane w innym oknie.');
        }

        return $this->messages->errorText($result);
    }

    /**
     * Kandydaci do tabeli w szablonie.
     *
     * @param array $match
     *
     * @return array
     */
    private function matchView(array $match)
    {
        $fields = [
            'subtitle' => $this->l('podtytuł'),
            'message' => $this->l('uwagi'),
            'original_number' => $this->l('numer oryginału'),
        ];

        $rows = [];
        foreach ($match['candidates'] as $candidate) {
            $texts = [];
            foreach ($candidate['texts'] as $field => $text) {
                $texts[] = ['label' => $fields[$field], 'text' => $text];
            }
            $in = [];
            foreach (array_unique($candidate['reference_in']) as $field) {
                $in[] = $fields[$field];
            }

            $checks = $candidate['checks'];
            $products = $candidate['products'];
            $rows[] = [
                'number' => $candidate['number'],
                'date' => $candidate['date'],
                'total' => $candidate['total'] === null ? '' : number_format($candidate['total'], 2, ',', ' '),
                'buyer' => $candidate['buyer'],
                'texts' => $texts,
                'checks' => [
                    ['label' => $in ? sprintf($this->l('numer zamówienia (%s)'), implode(', ', $in)) : $this->l('numer zamówienia'), 'ok' => $checks['reference'] || $checks['id_order']],
                    ['label' => $this->l('kwota'), 'ok' => $checks['total']],
                    ['label' => $this->l('NIP'), 'ok' => $checks['vat_number']],
                    ['label' => $this->l('kod pocztowy'), 'ok' => $checks['postcode']],
                    [
                        'label' => $products === null
                            ? $this->l('produkty')
                            : sprintf($this->l('produkty %d/%d (pozycji w dokumencie: %d)'), $products['matched'], $products['lines'], $products['positions']),
                        'ok' => $products === null ? null : $products['lines'] > 0 && $products['matched'] === $products['lines'] && $products['positions'] === $products['lines'],
                    ],
                ],
                'likely' => $candidate['likely'],
                'taken_by' => $candidate['taken_by'],
                'error' => $candidate['error'],
                'params_json' => InfinitySubiektJson::encode(['number' => $candidate['number']]),
                'confirm_text' => sprintf($this->l('Przypisać dokument %s z Subiekta do tego zamówienia? Moduł nie wystawi wtedy nowego dokumentu.'), $candidate['number']),
            ];
        }

        return [
            'rows' => $rows,
            'date_from' => $match['date_from'],
            'date_to' => $match['date_to'],
        ];
    }

    /**
     * @param string $type metoda SuDokumentyManager
     *
     * @return string
     */
    private function documentLabel($type)
    {
        switch ($type) {
            case InfinitySubiektDocumentTypeResolver::INVOICE:
                return $this->l('faktura sprzedaży');
            case InfinitySubiektDocumentTypeResolver::RECEIPT:
                return $this->l('paragon imienny');
            case InfinitySubiektDocumentTypeResolver::ORDER:
                return $this->l('zamówienie od klienta');
            case InfinitySubiektDocumentTypeResolver::ORDER_ADVANCE:
                return $this->l('zamówienie zaliczkowe');
            default:
                return (string) $type;
        }
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
