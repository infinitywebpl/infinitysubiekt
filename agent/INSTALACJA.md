# Agent Subiekta GT: instalacja i uruchamianie na serwerze Windows

Agent to skrypt PHP CLI działający w pętli. Pobiera zadania z modułu `infinitysubiekt` w sklepie (long polling), wykonuje je w Subiekcie GT przez Sferę i odsyła wynik. Łączy się tylko **ze** sklepem, więc serwer Windows nie musi być dostępny z internetu.

Agent musi działać cały czas. Zalecany sposób to usługa Windows przez NSSM (§4). Zapasowy to Harmonogram zadań jako „cron” co 5 minut (§5). Kolejne wersje instaluje przycisk w module (§7), więc ręczna instalacja z paczki ZIP jest jednorazowa.

## 0. Wersja demo

To jest **agent w wersji demo** (numer `0.0.x`). Łączy się ze sklepem, pobiera zadania i odpowiada na nie, ale w Subiekcie działa tylko część metod:

| metoda | demo |
|---|---|
| `ping`: wersja agenta, stan Sfery i SQL | działa |
| `payment/forms`, `product/features`: formy płatności i cechy towarów do konfiguracji modułu | działa (odczyt SQL) |
| `agent/update`, `agent/rollback`: aktualizacja z modułu i powrót do poprzedniej wersji | działa |
| kontrahenci, towary, stany, ceny zakupu, dokumenty sprzedaży, PDF, odczyty dla czatu AI | pełna wersja |

Metoda z pełnej wersji kończy zadanie błędem `Wersja demo: metoda … działa tylko w pełnej wersji modułu. Kontakt: biuro@infinityweb.pl`. Moduł pokazuje go w panelu i na liście zadań. W Subiekcie nic się nie zmienia.

**Ustaw od razu `'update_enabled' => true` w `config.php` (§2) i `extension=sodium` w `php.ini` (§1).** Po zakupie pełnej wersji wgrywasz ją na sklep i klikasz **Zaktualizuj agenta** w zakładce **Agent**. Agent sam pobierze podpisane wydanie pełnej wersji, sprawdzi je i się przełączy, a `config.php` zostaje bez zmian (§7). Bez tych dwóch ustawień przejście wymaga ręcznej podmiany plików na serwerze.

## 1. Wymagania

- Windows z zainstalowanym **Subiektem GT** i **Sferą** (licencja Sfery w podmiocie). Agent musi działać na koncie użytkownika, na którym Subiekt GT jest skonfigurowany i uruchamia się poprawnie.
- **PHP 8.4 NTS**, zalecane **x86 (32-bit)**: Sfera bywa zarejestrowana tylko dla programów 32-bitowych. Wersja 64-bit też może działać; rozstrzyga `php agent.php diag` (§3). Rozszerzenia w `php.ini`:

  ```ini
  extension=com_dotnet
  extension=curl
  extension=mbstring
  extension=openssl
  extension=iconv
  extension=pdo_sqlsrv   ; opcjonalne: bez niego agent czyta SQL przez ADODB (sterownik MSOLEDBSQL)
  extension=sodium       ; zdalna aktualizacja z modułu (§7), w wersji demo obowiązkowe
  curl.cainfo = "C:\php84\extras\ssl\cacert.pem"
  ```

  `cacert.pem` pobierz z https://curl.se/docs/caextract.html. Bez niego połączenie HTTPS ze sklepem kończy się błędem certyfikatu.
  Sprawdzenie: `C:\php84\php.exe -v` (w opisie ma być `x86`) i `C:\php84\php.exe -m`.
- Wychodzący HTTPS z serwera Windows do sklepu (firewall).
- [NSSM](https://nssm.cc/download): jeden plik `nssm.exe`, bez instalatora. Potrzebny tylko dla §4.

## 2. Pliki

1. Kliknij **Pobierz ZIP** w zakładce **Agent** w konfiguracji modułu i rozpakuj zawartość katalogu `infinitysubiekt-agent` z paczki na serwerze, np. do `C:\subiekt-agent\`. W katalogu głównym mają być `agent.php` (launcher) i `release-key.txt` (klucz publiczny autora, którym agent sprawdza podpis aktualizacji). Bez `release-key.txt` zdalna aktualizacja nie zadziała: poproś autora o plik.
2. Skopiuj `config.sample.php` jako `config.php` i uzupełnij:
   - `endpoint`: adres z zakładki **Agent** w konfiguracji modułu (pole „Endpoint dla agenta”). Nie składaj go ręcznie;
   - `token`: „Token agenta” z zakładki **Ustawienia** modułu;
   - `subiekt`: serwer SQL, baza podmiotu, użytkownik SQL, operator Subiekta i hasła;
   - `fiscal_printer_id`: drukarka fiskalna dla paragonów (gdy moduł wystawia paragony);
   - `update_enabled`: ustaw `true`, żeby kolejne wersje instalował przycisk w module (§7). Przy `false` (wartość z `config.sample.php`) każda aktualizacja to ręczne wgranie paczki ZIP. **W wersji demo ustaw `true` od razu**, bo od tego zależy przejście na pełną wersję jednym przyciskiem (§0).
3. `config.php` zawiera hasła. Trzymaj go tylko na serwerze Windows, nigdy w sklepie ani w repozytorium.

## 3. Test ręczny (przed rejestracją usługi)

W konsoli na serwerze, na koncie, na którym ma działać agent:

```bat
cd C:\subiekt-agent
C:\php84\php.exe agent.php diag
```

`diag` sprawdza po kolei PHP i rozszerzenia, certyfikaty, `config.php`, połączenie ze Sferą i z SQL. Każdy wiersz `[BŁĄD]` mówi, co poprawić. Zwróć uwagę na dwie rzeczy:

- nazwa podmiotu przy „Sfera” ma mieć polskie znaki takie jak w Subiekcie; jeśli są zepsute, ustaw `'codepage' => 'cp1250'` w sekcji `subiekt`;
- „polskie znaki przez SQL” ma być `OK`.

Polecenie `agent.php schema` (czy kolumny SQL używane przez agenta istnieją w bazie) jest w pełnej wersji.

Potem `C:\php84\php.exe agent.php ping`: wynik `"subiekt": "ok"` oznacza, że agent łączy się z Subiektem. To polecenie nie łączy się ze sklepem. Przycisk **Ping** w module zadziała dopiero przy uruchomionym `agent.php run`.

Metody demo można sprawdzić bez sklepu: `C:\php84\php.exe agent.php call payment/forms` i `C:\php84\php.exe agent.php call product/features` wypisują formy płatności i cechy towarów z Subiekta.

Następnie uruchom pętlę:

```bat
C:\php84\php.exe agent.php run
```

Skrypt wypisuje log na konsolę. Sprawdź, że:

- nie ma komunikatu o odrzuconym tokenie (403);
- w zakładce **Agent** modułu status zmienia się na **online** (do 2 minut);
- przycisk **Ping** w tej zakładce zwraca odpowiedź agenta.

Przerwij przez Ctrl+C: agent dokończy bieżące zadanie i się zatrzyma. Drugie Ctrl+C kończy go od razu.

Pojedyncze zadanie do testu: `C:\php84\php.exe agent.php once` (jedno pobranie z kolejki i najwyżej jedno zadanie).

## 4. Usługa Windows (NSSM), sposób zalecany

W konsoli **administratora** (ścieżki dopasuj do serwera):

```bat
nssm install InfinitySubiektAgent "C:\php84\php.exe" "C:\subiekt-agent\agent.php run"
nssm set InfinitySubiektAgent AppDirectory "C:\subiekt-agent"
nssm set InfinitySubiektAgent DisplayName "Subiekt GT - agent sklepu (infinitysubiekt)"
nssm set InfinitySubiektAgent Start SERVICE_AUTO_START

nssm set InfinitySubiektAgent ObjectName ".\UZYTKOWNIK" "HASLO"

nssm set InfinitySubiektAgent AppStdout "C:\subiekt-agent\logs\service.log"
nssm set InfinitySubiektAgent AppStderr "C:\subiekt-agent\logs\service.log"
nssm set InfinitySubiektAgent AppRotateFiles 1
nssm set InfinitySubiektAgent AppRotateOnline 1
nssm set InfinitySubiektAgent AppRotateBytes 10485760

nssm set InfinitySubiektAgent AppExit Default Restart
nssm set InfinitySubiektAgent AppThrottle 5000
nssm set InfinitySubiektAgent AppStopMethodConsole 120000

nssm start InfinitySubiektAgent
```

Co robią te ustawienia:

- `Start SERVICE_AUTO_START`: usługa wstaje sama po restarcie serwera.
- `ObjectName`: konto Windows, na którym działa Subiekt GT i Sfera. Konto systemowe (LocalSystem) zwykle nie ma konfiguracji InsERT GT i Sfera się nie uruchomi.
- `AppStdout`, `AppStderr`, `AppRotate*`: wyjście procesu (w tym błędy krytyczne PHP) do `logs\service.log` z rotacją co 10 MB. Zwykły log pracy agent pisze sam do `logs\agent-RRRR-MM-DD.log`.
- `AppExit Default Restart` i `AppThrottle 5000`: po zakończeniu procesu NSSM uruchamia go od nowa, z 5 s przerwy przy szybkich awariach. Restart jest **zamierzony**: agent sam kończy pracę po `max_runtime_hours` (domyślnie 12 h) albo po przekroczeniu `max_memory_mb`, żeby zwolnić pamięć Sfery.
- `AppStopMethodConsole 120000`: przy zatrzymaniu usługi NSSM wysyła Ctrl+C i czeka do 120 s. Agent przerywa wtedy czekanie na zadania od razu, ale **zadanie w toku dokańcza** i odsyła wynik do sklepu (wystawienie dokumentu trwa do 90 s). Domyślne 1,5 s NSSM ubiłoby agenta w trakcie wystawiania faktury.

Zarządzanie:

```bat
nssm status InfinitySubiektAgent
nssm stop InfinitySubiektAgent        & rem w module agent przejdzie w offline
nssm start InfinitySubiektAgent
nssm restart InfinitySubiektAgent     & rem po zmianie config.php
nssm edit InfinitySubiektAgent        & rem okno z konfiguracją usługi
nssm remove InfinitySubiektAgent confirm
```

Usługa jest widoczna także w `services.msc`.

## 5. Harmonogram zadań („cron” Windows), sposób zapasowy

Gdy nie można użyć NSSM, Harmonogram zadań działa jak watchdog. Uruchamia agenta co 5 minut, a gdy proces już działa, nic nie robi.

Utwórz zadanie w konsoli **administratora**:

```bat
schtasks /Create /TN "InfinitySubiektAgent" /SC MINUTE /MO 5 ^
  /TR "\"C:\php84\php.exe\" \"C:\subiekt-agent\agent.php\" run" ^
  /RU UZYTKOWNIK /RP HASLO /F
```

Potem w `taskschd.msc` → zadanie → **Właściwości** ustaw:

- **Ogólne**: „Uruchom niezależnie od tego, czy użytkownik jest zalogowany” (konto z §4, na którym działa Subiekt GT).
- **Ustawienia**: „Jeśli zadanie jest już uruchomione: **Nie uruchamiaj nowego wystąpienia**”. To kluczowe: ma działać tylko jeden agent.
- **Ustawienia**: odznacz „Zatrzymaj zadanie, jeśli jest uruchomione dłużej niż…”. Agent kończy się sam.
- **Warunki**: odznacz „Uruchom zadanie tylko wtedy, gdy komputer jest zasilany z sieci” i opcje bezczynności.

W `config.php` ustaw krótszy czas życia procesu, np. `'max_runtime_hours' => 1`, żeby proces regularnie startował od nowa. Po awarii albo zakończeniu następny start nastąpi najpóźniej po 5 minutach. W tym czasie agent jest offline, a zadania czekają w kolejce (wygasają po 60 minutach).

Niezależnie od ustawień harmonogramu agent pilnuje jednej instancji blokadą pliku `logs\agent.lock`. Drugi proces kończy się od razu z komunikatem w logu.

Uruchomienie od razu, bez czekania 5 minut: `schtasks /Run /TN "InfinitySubiektAgent"`.

## 6. Diagnostyka

| Objaw | Przyczyna, co sprawdzić |
|---|---|
| w module agent **offline** | usługa nie działa (`nssm status`) albo zadanie harmonogramu nie startuje (historia zadania); firewall blokuje wyjście; zły `endpoint` (skopiuj ponownie z zakładki Agent) |
| `agent.php ping` daje `"pong": true`, a **Ping** w module `timeout` | w konsoli ping działa lokalnie, bez sklepu. Moduł czeka na proces `agent.php run`: uruchom go i sprawdź w jego logu błędy pollu |
| w logu `403` | `token` w `config.php` inny niż „Token agenta” w module, albo nagłówek `X-Agent-Token` gubi się po stronie sklepu (`README.md` modułu, „Serwer”) |
| w logu błąd SSL / certyfikatu | brak `curl.cainfo` w `php.ini` albo `ca_file` w `config.php` |
| `Class "COM" not found` | brak `extension=com_dotnet` |
| `Nie można utworzyć obiektu InsERT.GT` | Sfera niezainstalowana na tym koncie albo zarejestrowana tylko dla 32-bit: użyj PHP x86 |
| `Nie udało się połączyć z Subiektem GT: błąd bez opisu (0x80020009)` | zły serwer SQL, baza, użytkownik SQL, operator albo hasło w `config.php` |
| SQL: `Error Locating Server/Instance` albo błąd certyfikatu | zły `subiekt.server`; przy certyfikacie zostaw `sql.trust_server_certificate = true` |
| `Agent już działa (PID …)` przy `call` | usługa albo inny proces agenta działa; zatrzymaj go (`nssm stop`) na czas testów |
| `ping`: `0x800411F2` / `0x80041248` | brak licencji Sfery / licencja wygasła |
| `ping` działa w konsoli, a w usłudze nie | usługa działa na innym koncie niż test (ustaw `ObjectName` albo „Uruchom jako” w harmonogramie) |
| krzaki zamiast polskich znaków w Subiekcie | najpierw `'codepage' => 'cp1250'` w `config.php` (§3); gdy nie pomoże, zgłoś autorowi |
| PDF: `wydruk … nie jest PDF: domyślny wzorzec wydruku … jest tekstowy` | w Subiekcie ustaw dla tego rodzaju dokumentu domyślny wzorzec graficzny (Crystal Reports) zamiast tekstowego |
| zadanie wisi w `processing`, a agent po 2 minutach jest **offline** | proces zawisł w wywołaniu Sfery. Ostatni wpis w `logs\agent-*.log` pokazuje krok (przy `order/add`: kontrahenci, tworzenie dokumentu, przeliczanie, zapis, druk paragonu). Zatrzymanie czeka do 120 s, potem NSSM kończy proces: `nssm restart InfinitySubiektAgent`. Sprawdź w Subiekcie, czy dokument powstał. Żeby zadanie nie wróciło, kliknij **Anuluj** przy nim na liście zadań. Bez anulowania po 10 minutach moduł odda je ponownie, najwyżej 3 razy, a agent nie wystawi drugiego dokumentu z tym samym podtytułem |
| `kod "X" jest w Subiekcie kodem kreskowym towaru Z` | referencja produktu w sklepie jest kodem kreskowym innego towaru, a EAN go nie wskazał. Popraw referencję albo EAN w sklepie |
| aktualizacja: `zdalna aktualizacja jest wyłączona` | ustaw `'update_enabled' => true` w `config.php` i zrestartuj usługę (§2) |
| aktualizacja: `brak rozszerzenia sodium` albo `brak klucza publicznego` | `extension=sodium` w `php.ini` PHP używanego przez usługę (`php -m` pokazuje `sodium`); `release-key.txt` z paczki ZIP w katalogu głównym agenta |
| aktualizacja: `podpis release.json nie pasuje` / `inną sumę niż w podpisanym manifeście` | pliki na sklepie zmienione po podpisie, podpisane innym kluczem niż ten w `release-key.txt` albo zmienione przez serwer (np. Cloudflare): zgłoś autorowi; działająca wersja się nie zmienia |
| aktualizacja: `test nowej wersji nie przeszedł` | szczegóły w `logs\selftest.log`; działająca wersja się nie zmienia |
| aktualizacja wisi w kolejce | agent zawieszony albo offline, więc zadania nie pobierze: `nssm restart InfinitySubiektAgent` |
| `towar X (symbol)`, `(dane towaru)` albo `(zapis)`: `Wartość musi być unikalna` | nie chodzi o kod kreskowy innego aktywnego towaru. Sprawdź w Subiekcie towary o tym symbolu, także usunięte, i unikalność pól w parametrach towarów |
| zadania kończą się `failed` po 10 minutach | agent przetwarzał zadanie za długo albo padł w trakcie; szczegóły w `logs\agent-*.log` i w kolumnie „Błąd” listy zadań modułu |

## 7. Aktualizacja

Przy `update_enabled => true` (§2), `extension=sodium` (§1) i `release-key.txt` (§2) kolejne wersje instaluje przycisk **Zaktualizuj agenta** w zakładce **Agent** modułu, bez paczki ZIP i konsoli. Agent przyjmuje tylko wydanie podpisane kluczem autora i nowsze od działającego. Po kliknięciu agent:

1. pobiera ze sklepu podpisaną listę plików i sprawdza podpis kluczem z `release-key.txt`;
2. pobiera pliki do `releases\<wersja>.tmp\` i sprawdza sumę każdego;
3. testuje nową wersję w osobnym procesie (`agent.php selftest`, wynik w `logs\selftest.log`);
4. przenosi ją do `releases\<wersja>\`, odsyła wynik i kończy pracę, a NSSM uruchamia go od nowa (Harmonogram zadań najpóźniej po 5 minutach).

Błąd w którymkolwiek kroku przerywa aktualizację, a działająca wersja zostaje bez zmian. Po chwili **Ping** pokazuje nową wersję w `release.running`. Zostaje ona na stałe przy pierwszym udanym połączeniu ze sklepem.

Gdy coś pójdzie nie tak:

- **Nowa wersja nie startuje:** po trzech nieudanych startach launcher sam wraca do poprzedniej i zapisuje powód w `releases\update-failed.txt` (w **Ping** jako `release.failed`).
- **Wersja startuje, ale psuje jedną metodę** (np. wystawianie dokumentów): kliknij **Przywróć poprzednią wersję**. Automatyczny powrót tego nie wykryje, bo agent łączy się ze sklepem poprawnie.

Katalog `releases\` trzyma wersję bieżącą i poprzednią, starsze usuwa sam. `config.php`, `logs\` i `release-key.txt` zostają w katalogu głównym i aktualizacja ich nie zmienia.

**Ręcznie** zmienia się tylko launcher `agent.php` i `release-key.txt` (z nowej paczki ZIP), `config.php`, `php.ini` i samo PHP. Nowa paczka ZIP: zatrzymaj usługę (`nssm stop InfinitySubiektAgent`), rozpakuj paczkę na istniejący katalog (nie zawiera `config.php` ani logów, więc ich nie nadpisze) i uruchom usługę ponownie.
