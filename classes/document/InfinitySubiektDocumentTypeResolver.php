<?php
/**
 * Rodzaj dokumentu Subiekta dla zamówienia wg ustawienia INFSUBIEKT_DOC_MODE.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektDocumentTypeResolver
{
    const INVOICE = 'DodajFS';
    const RECEIPT = 'DodajPAi';
    const ORDER = 'DodajZK';
    const ORDER_ADVANCE = 'DodajZKzal';

    /** @var InfinitySubiektConfig */
    private $config;

    public function __construct(InfinitySubiektConfig $config)
    {
        $this->config = $config;
    }

    /**
     * @param Order $order
     *
     * @return string
     */
    public function resolve(Order $order)
    {
        switch ($this->config->get(InfinitySubiektConfig::DOC_MODE)) {
            case InfinitySubiektConfig::DOC_MODE_RECEIPT:
                return self::RECEIPT;
            case InfinitySubiektConfig::DOC_MODE_INVOICE_IF_NIP:
                return $this->hasVatNumber(new Address((int) $order->id_address_invoice))
                    ? self::INVOICE
                    : self::RECEIPT;
            case InfinitySubiektConfig::DOC_MODE_ORDER:
                return self::ORDER;
            case InfinitySubiektConfig::DOC_MODE_ORDER_ADVANCE:
                return self::ORDER_ADVANCE;
            default:
                return self::INVOICE;
        }
    }

    /**
     * @param Address $address
     *
     * @return bool
     */
    private function hasVatNumber(Address $address)
    {
        return preg_replace('/\D+/', '', (string) $address->vat_number) !== '';
    }
}
