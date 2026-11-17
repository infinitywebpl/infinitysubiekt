<?php
/**
 * Rozszerzenie serwera MCP (moduł infinitymcp, hook actionInfinityMcpExtend), wariant demo: tylko opis.
 * Model dowiaduje się, że moduł jest w wersji demo i co daje wersja pełna. Bez narzędzi, pól, filtrów
 * i odczytów bazy. Klasa ładuje się wyłącznie w tym hooku, więc moduł działa też bez infinitymcp.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektMcpExtension
{
    /** @var InfinitySubiekt */
    private $module;

    public function __construct(InfinitySubiekt $module)
    {
        $this->module = $module;
    }

    public function register(\InfinityMcp\McpExtensions $extensions)
    {
        $extensions->addInstructions(
            'Module infinitysubiekt (Subiekt GT integration) is installed in its DEMO version: the AI tools of the full '
            . 'version are not available here. If the user asks about Subiekt GT data or actions, say that they need '
            . 'the full version of the module and give the contact from get_shop_context extra.subiekt.'
        );

        $extensions->addSection('shop', 'subiekt', function () {
            return $this->context();
        }, 'Subiekt GT integration (module infinitysubiekt): demo version, what the full version adds');

        // Pełna wersja: pola i filtry produktu oraz zamówienia, narzędzia subiekt_tasks i subiekt_query.
    }

    /**
     * @return array
     */
    private function context()
    {
        return [
            'module_version' => $this->module->version,
            'demo' => true,
            'full_version' => [
                'subiekt_query: live read-only questions about Subiekt GT documents (sales, purchases, orders, corrections, '
                    . 'warehouse documents and their lines), computed by Subiekt',
                'subiekt_tasks: the task queue of the Subiekt agent',
                'Subiekt fields and filters in list_products, get_product and list_orders (cards sent, documents issued)',
                'changeset operations (module infinitymcp_changes, accepted by an employee): pull purchase prices and stock '
                    . 'from Subiekt, fetch an order document PDF, inspect the Subiekt database structure, save notes about '
                    . 'how the company keeps Subiekt',
                'in the back office: customer and product cards, sales documents with PDF, stock and purchase price '
                    . 'synchronisation',
            ],
            'contact' => [
                'email' => InfinitySubiektDemo::CONTACT_EMAIL,
                'url' => InfinitySubiektDemo::FULL_VERSION_URL,
            ],
        ];
    }
}
