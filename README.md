# Subiekt GT - integracja (infinitysubiekt) — wersja demo

Moduł PrestaShop 1.6.1–8.x (PHP ≥ 7.3), który łączy sklep z **Subiektem GT** przez agenta na serwerze Windows z Subiektem i Sferą. Agent sam łączy się ze sklepem (long polling), więc serwer z Subiektem nie musi być dostępny z internetu.

**To wersja demo.** Moduł działa w całości: konfiguracja, kolejka zadań, panele w zamówieniu, kliencie i produkcie oraz cron. Agent demo łączy się ze sklepem, odpowiada na „Ping”, pobiera z Subiekta formy płatności i cechy towarów i aktualizuje się przyciskiem z panelu. Pozostałe zadania (kontrahenci, towary, stany, ceny zakupu, dokumenty i PDF) kończą się komunikatem o pełnej wersji i niczego nie zmieniają w Subiekcie. Kontakt w sprawie pełnej wersji: https://www.infinityweb.pl/oferta/moduly-prestashop, biuro@infinityweb.pl

### [⬇ Pobierz demo (ZIP do wgrania w PrestaShop)](https://github.com/infinitywebpl/infinitysubiekt/releases/latest)

W wydaniu pobierz plik `infinitysubiekt-<wersja>-demo.zip`, a nie „Source code”: tylko on ma katalog, który PrestaShop zainstaluje.

Panel: **Moduły → Subiekt GT - integracja (demo) → Konfiguruj**.

## Co działa w demie

| funkcja | demo | pełna wersja |
|---|---|---|
| Konfiguracja: wszystkie ustawienia, tokeny, mapa form płatności, teksty dokumentu | ✓ | ✓ |
| Agent na Windows: połączenie ze sklepem, status online, „Ping” (stan Sfery i SQL) | ✓ | ✓ |
| Pobranie form płatności i cech towarów z Subiekta | ✓ | ✓ |
| Aktualizacja agenta przyciskiem z panelu, powrót do poprzedniej wersji | ✓ | ✓ |
| Kolejka zadań: lista, szczegóły, anulowanie, czasy wykonania | ✓ | ✓ |
| Cron (HTTP i CLI), dry-run, log | ✓ (zadania agenta kończą się komunikatem demo) | ✓ |
| Podgląd danych kontrahenta, towaru i dokumentu wysyłanych do Subiekta | ✓ | ✓ |
| Kartoteki kontrahentów i towarów wysyłane do Subiekta | — | ✓ |
| Faktury, paragony i inne dokumenty sprzedaży z zamówienia, z ochroną przed duplikatem | — | ✓ |
| PDF dokumentu w panelu zamówienia | — | ✓ |
| Stany z Subiekta do sklepu (cron i ręcznie), ręczne wysłanie stanu do Subiekta (PW/RW) | — | ✓ |
| Ceny zakupu z Subiekta do ceny hurtowej produktu | — | ✓ |
| Dopasowanie dokumentów wystawionych w Subiekcie przed modułem | — | ✓ |
| Czat AI (moduł `infinitymcp`): odczyt dokumentów Subiekta, kolejka, paczki zmian | opis | ✓ |

Zadanie z pełnej wersji kończy się błędem `Wersja demo: metoda … działa tylko w pełnej wersji modułu. Kontakt: biuro@infinityweb.pl`, widocznym w panelu i na liście zadań.

## Przejście na pełną wersję

1. Przy instalacji agenta demo ustaw w jego `config.php` `'update_enabled' => true`, a w `php.ini` `extension=sodium` ([agent/INSTALACJA.md](agent/INSTALACJA.md) §0).
2. Po zakupie wgraj pełną wersję na demo (bez odinstalowywania). Ustawienia, mapowania, notatki i kolejka zostają.
3. W zakładce **Agent** kliknij **Zaktualizuj agenta**. Agent pobierze podpisane wydanie pełnej wersji, sprawdzi podpis, przetestuje je i się przełączy. `config.php` na serwerze zostaje bez zmian.

Bez kroku 1 pliki agenta trzeba podmienić ręcznie na serwerze Windows.

## 1. Wymagania

- PrestaShop 1.6.1–8.x, PHP 7.3 lub nowszy (także w CLI, jeśli cron ma działać z linii komend).
- MySQL/MariaDB z InnoDB (pobranie zadania używa `SELECT … FOR UPDATE` w transakcji).
- HTTPS na adresie sklepu. Agent wysyła token w nagłówku, więc bez szyfrowania token jest jawny.
- Serwer Windows z Subiektem GT, Sferą i PHP 8.4 dla agenta: [agent/INSTALACJA.md](agent/INSTALACJA.md).

## 2. Instalacja

1. Wgraj ZIP w PrestaShop: **Moduły → Menedżer modułów → Wgraj moduł** (PS 1.6: **Moduły → Dodaj nowy moduł**).
2. Instalacja tworzy tabele, ukrytą zakładkę `AdminInfinitySubiekt`, hooki i losowy token crona.
3. Reset i odinstalowanie **nie usuwają** tabel ani ustawień (numery dokumentów, symbole kontrahentów, tokeny). Usuwany jest tylko stan działania: ostatni sygnał agenta, log crona i data porządków. Pełne usunięcie danych opisuje §10.

## 3. Ustawienia (zakładka „Ustawienia”)

Ustawienia są podzielone na panele. Każdy przycisk „Zapisz” zapisuje wszystkie panele naraz. Kolejność ma znaczenie:

1. **Prefiks symbolu kontrahenta**: krótki skrót sklepu, np. `SK`. Ustaw go raz, przed pierwszą synchronizacją. Zmiana później tworzy w Subiekcie duplikaty kontrahentów. Symbol ma postać `{prefiks} {id klienta} {id adresu}`.
2. **Kontrahent**: `symbol` (zawsze własny symbol) albo `match` (najpierw szukaj po symbolu, NIP i e-mailu). Adres z NIP-em albo nazwą firmy trafia do Subiekta jako firma, bez wymaganego imienia i nazwiska.
3. **Rodzaj dokumentu** i **KSeF**. **Dopasowanie w Subiekcie: dni po zamówieniu** (domyślnie 60): okno dat dla przycisku „Dopasuj w Subiekcie” na karcie zamówienia (§7.1).
4. **Płatności w dokumencie**: tabela aktywnych modułów płatności sklepu, przy każdym select formy w Subiekcie. Najpierw kliknij **Pobierz formy płatności z Subiekta** (agent online): select dostaje rodzaje kart (np. „Karta: Karta płatnicza”) i terminy kredytu kupieckiego (np. „Kredyt kupiecki: 14 dni”). Pobranie nie zmienia mapy, wybory zapisuje przycisk **Zapisz**. Opcje: forma domyślna Subiekta (bez formy w dokumencie), gotówka, karta (rodzaj domyślny albo z listy), kredyt kupiecki (termin domyślny albo z listy), **Zapłacono przelewem** (dokument od razu rozliczony, więc nie dla przelewu, na który się czeka). Wybór, którego nie ma na pobranej liście, zostaje jako „#id (brak na liście z Subiekta)”. Po dodaniu formy w Subiekcie pobierz listę ponownie. Moduł płatności wyłączony albo odinstalowany znika z tabeli, ale jego forma zostaje w mapie i wraca po włączeniu modułu.
5. **Teksty dokumentu**: szablony pól **Podtytuł**, **Uwagi** i **Numer oryginału** dokumentu w Subiekcie. Tagi: `{id_order}`, `{reference}`, `{date}` (dd.mm.rrrr), `{total_paid}` i `{total_shipping}` (brutto, przecinek dziesiętny), `{currency}`, `{payment}` (nazwa płatności z zamówienia), `{carrier}`, `{shop_name}`, `{shop_domain}`. Tabela tagów jest pod polami; kliknięcie tagu wstawia go w miejscu kursora w ostatnio wybranym polu.
   - Wszystkie trzy są dowolne, mogą być puste.
   - Zalecane: `{id_order}` albo `{reference}` w pierwszych 50 znakach podtytułu. Gdy agent padnie w trakcie wystawiania, po takim podtytule rozpozna zapisany już dokument, a moduł bezpiecznie ponowi zadanie. Gdy kilka sklepów wysyła dokumenty do jednego Subiekta, dodaj `{shop_domain}`.
   - Numer oryginału ma najwyżej 30 znaków, dłuższy agent obcina. Pusty szablon = pole puste. Pusty szablon uwag = dokument bez uwag.
6. **Zakładaj brakujące towary**, **jednostka**, **magazyn**, **stan pobierany do sklepu**, **cena zakupu**, opcjonalna **cecha towarów do pobierania stanów** (select; listę cech pobiera przycisk **Pobierz cechy z Subiekta**; „bez cechy” = towary ze stanem > 0) i **zeruj magazyn sklepu dla pozycji spoza listy** (§6).
   **Magazyn sklepu** dotyczy produktów z zaawansowanym zarządzaniem magazynem. Pobrany stan trafia do tego magazynu. Bez wybranego magazynu takie produkty są pomijane, także w cronie.
   Moduł szuka towaru w Subiekcie najpierw po EAN, a gdy EAN jest pusty albo go nie ma w Subiekcie, po referencji. Znaleziony towar zachowuje swój symbol, a nazwę, stawkę VAT i cenę detaliczną aktualizuje **Wyślij kartotekę**. Kombinacja bez własnego EAN szukana jest po referencji.
   **Wysyłka w dokumencie**: rodzaj pozycji z kosztem dostawy.
   - **Usługa jednorazowa** (domyślnie): pozycja o nazwie z pola **Nazwa wysyłki**, bez kartoteki w Subiekcie, ze stawką VAT z pola **Stawka VAT wysyłki** (domyślnie 23; puste = stawka przewoźnika z zamówienia). Stawka musi istnieć w Subiekcie, a operator Subiekta z konfiguracji agenta musi mieć uprawnienie do dodawania usług jednorazowych.
   - **Zwykły towar**: pozycja z kartoteki o symbolu z pola **Kod towaru wysyłki**. Stawkę VAT bierze Subiekt z kartoteki.
   - Przy pustym polu wybranego rodzaju koszt dostawy nie trafia do dokumentu.
   **Rabat zamówienia** (kupony, reguły koszyka) rozkłada Subiekt: towary idą po cenach z zamówienia, a Subiekt obniża je do kwoty zapłaconej za towary. Suma dokumentu jest równa zamówieniu. Rabat na samą dostawę może przekazać moduł sklepu przez hook `actionInfinitySubiektShippingDiscounts`.
7. **Token agenta**: kliknij „Generuj token agenta”. Pusty token wyłącza endpoint (403). **Token crona** jest obok i dotyczy wywołania crona przez HTTP.

Przełączniki pobierania stanów i cen zakupu są w zakładce „Cron” (§6).

## 4. Agent

Pliki agenta pobierzesz przyciskiem **Pobierz ZIP** w zakładce „Agent”. Paczka powstaje przy każdym pobraniu z katalogu `agent/` modułu i nie zawiera `config.php` ani logów. Instalacja na serwerze Windows: [agent/INSTALACJA.md](agent/INSTALACJA.md).

**Zdalna aktualizacja agenta** (zakładka „Agent”): przy serwerze przygotowanym według `agent/INSTALACJA.md` (`release-key.txt`, `extension=sodium`, `update_enabled => true`) kolejne wersje instaluje przycisk **Zaktualizuj agenta**. Agent przyjmuje tylko wydanie podpisane kluczem autora i wyższe niż działające, testuje je przed przełączeniem i wraca do poprzedniej wersji, gdy nowa nie wystartuje. **Przywróć poprzednią wersję** cofa ręcznie. Akcje wymagają prawa edycji modułów.

- **Endpoint**: skopiuj z zakładki „Agent”, pole „Endpoint dla agenta”. Nie składaj adresu ręcznie, bo zależy od przyjaznych URL-i, SSL, języków i katalogu sklepu.
- **Token**: wartość z pola „Token agenta” w zakładce „Ustawienia”, wysyłana w nagłówku `X-Agent-Token`.
- **poll_wait** 20 s, **http_timeout** co najmniej 35 s.
- Endpoint działa także w trybie serwisowym sklepu i nie używa sesji ani koszyka.

Test z dowolnej maszyny (podstaw adres i token):

```bash
curl -i "ENDPOINT?op=poll&wait=5"                                  # 403
curl -i -H "X-Agent-Token: TOKEN" "ENDPOINT?op=poll&wait=5"        # 204 po ok. 5 s
```

Jeśli przy poprawnym tokenie wychodzi 403, nagłówek nie dociera do PHP (§5.3).

„Ping” w zakładce „Agent” tworzy zadanie w kolejce, więc odpowiada tylko przy działającym `agent.php run`. Gdy agent nie odpowie w 15 s, moduł pokazuje `timeout` i podpowiada, co sprawdzić.

Kliknięcie wiersza listy zadań otwiera popup ze szczegółami: daty, czas oczekiwania i wykonania, pełny błąd oraz parametry i wynik jako JSON. Parametry i wynik zadań kontrahenta i dokumentu zawierają dane klienta, więc widzi je każdy pracownik z dostępem do strony modułu (albo do zamówień, gdy zadanie dotyczy zamówienia).

Zadanie, które czeka albo wisi w `processing`, można zakończyć przyciskiem **Anuluj**. Dostaje status `failed` i nie wraca do kolejki.

Zakładka **Wiedza dla AI** zawiera notatki o tym, jak firma prowadzi Subiekta. W pełnej wersji czat AI dostaje je przy pytaniach o dokumenty Subiekta. Nie wpisuj danych klientów, bo treść trafia do modelu.

## 5. Serwer

### 5.1 Długie żądania

| żądanie | czeka do |
|---|---|
| poll agenta | 20 s |
| wystawienie dokumentu | 90 s |
| kontrahent, towar, PDF, paczka stanów | 60 s na wywołanie |

Moduł sam zdejmuje `max_execution_time`, ale **nie** limity serwera. Ustaw co najmniej 120 s:

- PHP-FPM: `request_terminate_timeout = 120s` (albo 0) w puli sklepu.
- nginx + FPM: `fastcgi_read_timeout 120s;`. Za reverse proxy dodatkowo `proxy_read_timeout 120s;`.
- Apache + mod_fcgid: `FcgidIOTimeout 120`. Apache + mod_proxy_fcgi: `ProxyTimeout 120`.

### 5.2 Procesy PHP

Agent stale trzyma jeden proces PHP (poll), a każda czekająca akcja w BO trzyma kolejny. Pula FPM (`pm.max_children`) musi mieć na to zapas ponad ruch sklepu.

### 5.3 Nagłówek `X-Agent-Token`

- nginx przekazuje go bez zmian.
- Apache zwykle przekazuje go jako `HTTP_X_AGENT_TOKEN`. Jeśli konfiguracja CGI/FastCGI go gubi, dodaj do `.htaccess` sklepu, przed regułami PrestaShop:

  ```apache
  RewriteRule .* - [E=HTTP_X_AGENT_TOKEN:%{HTTP:X-Agent-Token}]
  ```

  Moduł czyta też `REDIRECT_HTTP_X_AGENT_TOKEN` i `getallheaders()`.
- Reguły WAF (np. mod_security) i niektóre proxy usuwają nieznane nagłówki. Wtedy dodaj wyjątek dla adresu endpointu.

### 5.4 Cloudflare

- Limit odpowiedzi to 100 s (plany Free i Pro). Wystawienie czeka do 90 s, więc mieści się, ale z małym zapasem.
- Cache: reguła **Bypass cache** dla `*/module/infinitysubiekt/*` oraz dla adresów z `module=infinitysubiekt` (przy wyłączonych przyjaznych URL-ach).
- Agent nie przejdzie JS challenge ani CAPTCHA. Dla tych samych adresów (albo IP agenta) dodaj regułę WAF **Skip**: Bot Fight Mode, Super Bot Fight Mode, Managed Challenge i Under Attack Mode.
- To samo dotyczy crona przez HTTP (`…/module/infinitysubiekt/cron`).

### 5.5 Strefa czasowa

Czasy w tabelach modułu liczy PHP w strefie sklepu (`PS_TIMEZONE`), więc różne strefy PHP i bazy nie psują kolejki.

## 6. Cron

Zakładka „Cron” opisuje, co robi cron, i ma osobne przełączniki **Pobieraj stany z Subiekta do sklepu** i **Pobieraj ceny zakupu z Subiekta do sklepu**. Gotowe linie do crontaba są w tej samej zakładce:

- przez HTTP: `wget` albo `curl` z tokenem crona;
- z linii komend: `php …/modules/infinitysubiekt/cron.php`, bez tokenu. Uruchamiaj jako użytkownik serwera WWW.

Zadania: `maintenance` (porządek w kolejce), `cleanup` (raz na dobę usuwa zadania starsze niż 30 dni) oraz, przy włączonych przełącznikach, `stock_pull` (stany) i `price_pull` (ceny zakupu). Listę towarów wyznacza Subiekt, a nie katalog sklepu:

- `stock_pull` bierze z Subiekta towary ze stanem > 0 (albo z wybraną cechą) i zapisuje stan pozycji sklepu dopasowanych po EAN, a potem po referencji. Opcjonalnie pozycje magazynu sklepu spoza listy dostają stan 0.
- `price_pull` raz na dobę bierze towary z dokumentami zakupu od ostatniego przebiegu i zapisuje ich cenę zakupu. `price_pull_full` to pełny przebieg na żądanie.
- **Zestawów** (paczek PrestaShop) cron nie zmienia.
- Najpierw uruchom dry-run: przycisk „Dry-run” albo `php cron.php --dry-run`. Niczego nie zapisuje.
- Wystarczy jedno wejście (HTTP **albo** CLI), co 5 minut. Równoległy przebieg dostaje „przebieg w toku”.
- Cron nigdy nie wysyła stanów do Subiekta i nie wystawia dokumentów.

W demie `stock_pull` i `price_pull` kończą się komunikatem o pełnej wersji, a stany i ceny w sklepie się nie zmieniają.

## 7. PDF (pełna wersja)

- Pliki trafiają do `download/infinitysubiekt/{rok}/{miesiąc}/{id_zamówienia}.pdf`. Katalog `download/` musi być zapisywalny dla PHP.
- `download/` ma w PrestaShop `.htaccess` z `deny`. Na nginx sprawdź, czy katalog jest zablokowany: `curl -I https://SKLEP/download/infinitysubiekt/` powinien dać 403 albo 404.
- „Pobierz PDF” zawsze pobiera aktualną wersję od agenta.

### 7.1 Dokument wystawiony przed modułem

Zamówienie bez numeru w module ma na karcie przycisk **Dopasuj w Subiekcie**. Agent szuka dokumentów z okna dat z ustawień, które mają numer albo id zamówienia w tekstach dokumentu albo kwotę równą zamówieniu. Moduł pokazuje zgodność każdego kandydata z zamówieniem (numer, kwota, NIP, kod pocztowy, produkty), a **Przypisz** zapisuje numer w zamówieniu tak jak po wystawieniu.

## 8. Uprawnienia pracowników

Ukryta zakładka modułu nie jest widoczna w ekranie uprawnień. Moduł sprawdza prawa do zakładki, której dotyczy akcja:

| akcja | wymagane prawo |
|---|---|
| ping, lista zadań | podgląd modułów |
| anulowanie zadania | edycja modułów, a dla zadania zamówienia także edycja zamówień |
| ręczny cron, pobranie i aktualizacja agenta | edycja modułów |
| kontrahent (wysyłka) | edycja klientów albo zamówień |
| wystawienie dokumentu, przypisanie dokumentu z Subiekta | edycja zamówień |
| PDF, podgląd danych dokumentu, „Dopasuj w Subiekcie” | podgląd zamówień (i dostęp do sklepu zamówienia przy multistore) |
| towar, stany, cena zakupu | edycja produktów |

## 9. Odbiór (kolejno, na kopii albo w spokojnych godzinach)

1. **Kolejka**: testy `curl` z §4, „Ping” w zakładce „Agent”, status agenta „online”.
2. **Cron**: dry-run, potem przebieg. Wywołanie `cron.php` przez przeglądarkę powinno dać 403.
3. **Kontrahent**: na karcie klienta „Podgląd danych”, potem „Wyślij do Subiekta”.
4. **Towar**: produkt prosty i z kombinacjami: kartoteka, potem „Pobierz stan” i „Pobierz cenę zakupu”. Wysłanie stanu na towarze testowym utworzy PW albo RW.
5. **Dokument**: na karcie zamówienia „Podgląd danych dokumentu”, potem „Wystaw” na jednym zamówieniu i „Pobierz PDF”.
6. **Duplikaty**: drugie „Wystaw” na tym samym zamówieniu daje „ma już dokument”.
7. Dopiero potem włącz `stock_pull` i `price_pull` w cronie.

W demie kroki 1–2 i podglądy działają w całości, a wysyłki z kroków 3–6 kończą się komunikatem o pełnej wersji.

## 10. Pełne usunięcie danych

Po odinstalowaniu, gdy dane nie są już potrzebne:

```sql
DROP TABLE `PREFIKS_subiekt_tasks`, `PREFIKS_infinitysubiekt_order`, `PREFIKS_infinitysubiekt_customer`,
           `PREFIKS_infinitysubiekt_product`, `PREFIKS_infinitysubiekt_lock`, `PREFIKS_infinitysubiekt_query_note`;
DELETE FROM `PREFIKS_configuration` WHERE `name` LIKE 'INFSUBIEKT\_%';
```

oraz katalog `download/infinitysubiekt/`. Po tej operacji ponowna instalacja nie zna numerów wystawionych dokumentów ani symboli kontrahentów.

## 11. Czat AI (moduł `infinitymcp`)

W demie, przy zainstalowanym module [`infinitymcp`](https://github.com/infinitywebpl/infinitymcp), czat AI dostaje tylko informację, że moduł jest w wersji demo, i opis wersji pełnej. W pełnej wersji czat:

- odpowiada na pytania o dokumenty Subiekta (sprzedaż, zakupy, zamówienia, korekty, dokumenty magazynowe), które liczy Subiekt na żywo, bez danych klientów;
- widzi stan integracji i kolejkę zadań;
- przy module `infinitymcp_changes` proponuje pobranie cen zakupu i stanów, pobranie PDF dokumentu i notatki o tym, jak firma prowadzi Subiekta. Każda akcja działa dopiero po akceptacji pracownika w BO.

Bez `infinitymcp` moduł działa tak samo.

## Licencja

Warunki korzystania z wersji demo: [LICENSE.md](LICENSE.md). Zakładka **„Licencja”** w konfiguracji modułu pokazuje, co jest w wersji pełnej, i kontakt. Wersja demo jest bezpłatna, bez kluczy i bez sprawdzania licencji. Wersja pełna wymaga licencji na domenę sklepu: [biuro@infinityweb.pl](mailto:biuro@infinityweb.pl).
