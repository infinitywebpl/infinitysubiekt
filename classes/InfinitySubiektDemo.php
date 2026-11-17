<?php
/**
 * Wersja demo: adresy pełnej wersji.
 *
 * Moduł działa w demie w całości: konfiguracja, kolejka, panele w BO i cron. Różnicę robi agent demo:
 * działają w nim ping, formy płatności, cechy towarów i aktualizacja agenta z panelu, a pozostałe metody
 * kończą zadanie błędem „Wersja demo: …” z kontaktem. Narzędzia i operacje MCP są w pełnej wersji.
 *
 * Po wgraniu pełnej wersji na demo przycisk „Zaktualizuj agenta” instaluje agenta pełnej wersji
 * (agent demo ma numer 0.0.x, niższy od każdej pełnej wersji).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

final class InfinitySubiektDemo
{
    /** Kontakt w sprawie pełnej wersji modułu. */
    const FULL_VERSION_URL = 'https://www.infinityweb.pl/oferta/moduly-prestashop';

    const CONTACT_EMAIL = 'biuro@infinityweb.pl';

    const SITE_URL = 'https://www.infinityweb.pl';

    const GITHUB_URL = 'https://github.com/infinitywebpl';
}
