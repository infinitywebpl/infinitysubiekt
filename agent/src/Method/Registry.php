<?php

declare(strict_types=1);

namespace InfinitySubiektAgent\Method;

if (PHP_SAPI !== 'cli') {
    exit;
}

use InfinitySubiektAgent\Services;

/**
 * Nazwa metody kontraktu → obiekt metody. Wersja demo: działają ping, payment/forms, product/features,
 * agent/update i agent/rollback, pozostałe metody z names() zwracają błąd demo (DemoMethod).
 */
final readonly class Registry
{
    public function __construct(private Services $services)
    {
    }

    /**
     * @return Method|null null = nieznana metoda ({"error":"unknown method"})
     */
    public function get(string $name): ?Method
    {
        return match ($name) {
            'ping' => new Ping($this->services->sfera(), $this->services->sql(), $this->services->log, $this->services->release),
            'payment/forms' => new PaymentForms($this->services->sql()),
            'product/features' => new ProductFeatures($this->services->sql()),
            'agent/update' => new AgentUpdate(
                $this->services->config,
                $this->services->release,
                $this->services->queue(),
                $this->services->restart,
                $this->services->log,
            ),
            'agent/rollback' => new AgentRollback($this->services->config, $this->services->release, $this->services->restart, $this->services->log),
            default => in_array($name, $this->names(), true) ? new DemoMethod($name) : null,
        };
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return ['ping', 'customer/save', 'product/save', 'product/info', 'product/list', 'product/features', 'product/stock_set', 'order/add', 'order/find', 'order/pdf', 'payment/forms', 'subiekt/schema', 'report/query', 'agent/update', 'agent/rollback'];
    }
}
