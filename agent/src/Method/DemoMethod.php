<?php

declare(strict_types=1);

namespace InfinitySubiektAgent\Method;

if (PHP_SAPI !== 'cli') {
    exit;
}

use InfinitySubiektAgent\AgentException;

/**
 * Wersja demo: metoda z kontraktu, która działa tylko w pełnej wersji. Zadanie kończy się błędem
 * z kontaktem, a moduł pokazuje go tam, gdzie każdy inny błąd zadania (panel, kolejka, log crona).
 */
final readonly class DemoMethod implements Method
{
    public const CONTACT = 'biuro@infinityweb.pl';

    public function __construct(private string $name)
    {
    }

    public function execute(array $params): array
    {
        // Pełna wersja.
        throw new AgentException(self::message($this->name));
    }

    public static function message(string $name): string
    {
        return sprintf('Wersja demo: metoda %s działa tylko w pełnej wersji modułu. Kontakt: %s', $name, self::CONTACT);
    }
}
