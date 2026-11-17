<?php
/**
 * Cron modułu. Wspólna logika dla wejść HTTP (wget/curl), CLI i BO.
 *
 * Nowe zadanie crona = nowa pozycja w JOBS i metoda job…($dryRun, $explicit),
 * zwracająca linie wyniku. Wejścia się nie zmieniają.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektCron
{
    const LOCK = 'cron';

    const SOURCE_HTTP = 'http';
    const SOURCE_CLI = 'cli';
    const SOURCE_BO = 'bo';

    const STATUS_OK = 'ok';
    const STATUS_FAILED = 'failed';     // co najmniej jedno zadanie rzuciło wyjątek
    const STATUS_BUSY = 'busy';         // inny przebieg trzyma blokadę
    const STATUS_INVALID = 'invalid';   // nieznana nazwa zadania

    const LOG_MAX = 10000;

    // szczegółów na grupę raportu pobierania w logu (log ma limit LOG_MAX)
    const REPORT_DETAILS = 20;

    // początek linii zadania, które czeka na swój czas z harmonogramu
    const NOT_DUE = 'następny przebieg od ';
    // początek linii zadania tylko na żądanie, uruchomionego bez wskazania
    const ON_DEMAND = 'tylko na żądanie';

    /**
     * Rejestr zadań w kolejności wykonywania: nazwa => metoda.
     */
    const JOBS = [
        'maintenance' => 'jobMaintenance',
        'cleanup' => 'jobCleanup',
        'stock_pull' => 'jobStockPull',
        'price_pull' => 'jobPricePull',
        'price_pull_full' => 'jobPricePullFull',
    ];

    // zadania z własnym harmonogramem: log pamięta ich ostatni wynik
    const SCHEDULED = ['stock_pull', 'price_pull', 'price_pull_full'];

    // stara nazwa zadania z istniejących crontabów
    const JOB_ALIASES = [
        'product_pull' => ['stock_pull', 'price_pull'],
    ];

    /** @var InfinitySubiektTaskQueue */
    private $queue;

    /** @var InfinitySubiektLock */
    private $lock;

    /** @var InfinitySubiektCatalogPull */
    private $catalogPull;

    /** @var InfinitySubiektConfig */
    private $config;

    /** @var InfinitySubiektClock */
    private $clock;

    public function __construct(
        InfinitySubiektTaskQueue $queue,
        InfinitySubiektLock $lock,
        InfinitySubiektCatalogPull $catalogPull,
        InfinitySubiektConfig $config,
        InfinitySubiektClock $clock
    ) {
        $this->queue = $queue;
        $this->lock = $lock;
        $this->catalogPull = $catalogPull;
        $this->config = $config;
        $this->clock = $clock;
    }

    /**
     * @return string[]
     */
    public static function jobNames()
    {
        return array_keys(self::JOBS);
    }

    /**
     * Lista zadań z parametru `maintenance,cleanup` (puste = wszystkie).
     *
     * @param string $value
     *
     * @return string[]
     */
    public static function parseJobs($value)
    {
        $jobs = [];
        foreach (array_filter(array_map('trim', explode(',', (string) $value)), 'strlen') as $name) {
            $jobs = array_merge($jobs, isset(self::JOB_ALIASES[$name]) ? self::JOB_ALIASES[$name] : [$name]);
        }

        return array_values(array_unique($jobs));
    }

    /**
     * @param string[] $jobs pusta lista = wszystkie zadania z rejestru
     * @param bool $dryRun tylko liczy, nic nie zmienia i nie zapisuje logu (pobierania pytają agenta o listę)
     * @param string $source http | cli | bo
     *
     * @return array ['status' => STATUS_*, 'lines' => string[]]
     */
    public function run(array $jobs, $dryRun, $source)
    {
        $explicit = (bool) $jobs;
        $unknown = array_diff($jobs, self::jobNames());
        if ($unknown) {
            return $this->result(self::STATUS_INVALID, [
                'nieznane zadanie: ' . implode(', ', $unknown) . ' (dostępne: ' . implode(', ', self::jobNames()) . ')',
            ]);
        }

        // kolejność zawsze z rejestru, niezależnie od kolejności w parametrze
        $selected = $explicit ? array_values(array_intersect(self::jobNames(), $jobs)) : self::jobNames();

        $lines = [$this->clock->now() . ' ' . $source . ($dryRun ? ' dry-run' : '')];

        // dry-run nic nie zmienia, więc nie bierze blokady i działa obok przebiegu
        if (!$dryRun && !$this->lock->acquire(self::LOCK, InfinitySubiektConfig::CRON_LOCK * 60)) {
            $lines[] = 'przebieg w toku od ' . $this->lock->lockedAt(self::LOCK);

            return $this->result(self::STATUS_BUSY, $lines);
        }

        if (!$dryRun) {
            // stany i ceny trwają długo: każde zadanie agenta odświeża blokadę
            $lock = $this->lock;
            $this->catalogPull->setHeartbeat(function () use ($lock) {
                $lock->touch(self::LOCK);
            });
        }

        $status = self::STATUS_OK;
        try {
            $previous = $dryRun ? [] : $this->jobLog();
            $jobLog = $previous;
            foreach ($selected as $name) {
                $method = self::JOBS[$name];
                try {
                    $jobLines = $this->$method($dryRun, $explicit);
                } catch (Throwable $e) {
                    $status = self::STATUS_FAILED;
                    $jobLines = ['BŁĄD ' . $e->getMessage()];
                }

                // zadanie czeka na swój czas: w logu jego ostatni wynik
                $waiting = count($jobLines) === 1 && (strpos($jobLines[0], self::NOT_DUE) === 0 || strpos($jobLines[0], self::ON_DEMAND) === 0);
                if ($waiting && isset($previous[$name])) {
                    $jobLines = array_merge($jobLines, array_map(function ($line) use ($previous, $name) {
                        return '(ostatnio ' . $previous[$name]['at'] . ') ' . $line;
                    }, $previous[$name]['lines']));
                } elseif (!$waiting && !$dryRun && in_array($name, self::SCHEDULED, true)) {
                    $jobLog[$name] = ['at' => $this->clock->now(), 'lines' => array_slice($jobLines, 0, 100)];
                }

                foreach ($jobLines as $line) {
                    $lines[] = $name . ': ' . $line;
                }
            }
            if (!$dryRun && $jobLog !== $previous) {
                $this->config->set(InfinitySubiektConfig::CRON_JOB_LOG, InfinitySubiektJson::encode($jobLog));
            }

            if (!$dryRun) {
                $this->saveLog($lines);
            }
        } finally {
            if (!$dryRun) {
                $this->lock->release(self::LOCK);
            }
        }

        return $this->result($status, $lines);
    }

    /**
     * @param bool $dryRun
     * @param bool $explicit
     *
     * @return string[]
     */
    private function jobMaintenance($dryRun, $explicit)
    {
        $stats = $dryRun ? $this->queue->previewMaintenance() : $this->queue->maintenance();

        return [sprintf(
            '%sponownie w kolejce %d, failed %d, expired %d',
            $dryRun ? 'zmieniłby: ' : '',
            $stats['requeued'],
            $stats['failed'],
            $stats['expired']
        )];
    }

    /**
     * Raz na dobę, chyba że zadanie wskazano wprost.
     *
     * @param bool $dryRun
     * @param bool $explicit
     *
     * @return string[]
     */
    private function jobCleanup($dryRun, $explicit)
    {
        $today = $this->clock->today();
        if (!$explicit && $this->config->get(InfinitySubiektConfig::CLEANUP_DATE) === $today) {
            return ['już wykonane dziś'];
        }

        if ($dryRun) {
            return [sprintf(
                'usunąłby %d zadań starszych niż %d dni',
                $this->queue->countOlderThan(InfinitySubiektConfig::CLEANUP_DAYS),
                InfinitySubiektConfig::CLEANUP_DAYS
            )];
        }

        $deleted = $this->queue->cleanup(InfinitySubiektConfig::CLEANUP_DAYS);
        $this->config->set(InfinitySubiektConfig::CLEANUP_DATE, $today);

        return [sprintf('usunięto %d zadań starszych niż %d dni', $deleted, InfinitySubiektConfig::CLEANUP_DAYS)];
    }

    /**
     * Stany z Subiekta do sklepu, tylko gdy włączone, co CRON_STOCK_INTERVAL minut (wskazane wprost: od razu).
     * Cron nigdy nie wysyła stanów do Subiekta (to tworzy dokumenty PW/RW).
     *
     * @param bool $dryRun
     * @param bool $explicit
     *
     * @return string[]
     */
    private function jobStockPull($dryRun, $explicit)
    {
        if (!$this->config->getBool(InfinitySubiektConfig::CRON_STOCK_PULL)) {
            return ['wyłączone w konfiguracji'];
        }

        $next = $this->stockNextRun();
        // także dry-run: pyta agenta o listę (strona po stronie)
        @set_time_limit(0);
        if ($dryRun) {
            return array_merge([$next === null ? 'harmonogram: przy tym przebiegu' : 'harmonogram: następny przebieg od ' . $next], $this->catalogPull->previewStock());
        }
        if (!$explicit && $next !== null) {
            return [self::NOT_DUE . $next];
        }

        $this->config->set(InfinitySubiektConfig::CRON_STOCK_LAST, $this->clock->now());

        return $this->reportLines($this->catalogPull->pullStock());
    }

    /**
     * Ceny zakupu towarów z nowymi dokumentami zakupu, tylko gdy włączone, raz na dobę od CRON_PRICE_HOUR
     * (wskazane wprost: od razu).
     *
     * @param bool $dryRun
     * @param bool $explicit
     *
     * @return string[]
     */
    private function jobPricePull($dryRun, $explicit)
    {
        if (!$this->config->getBool(InfinitySubiektConfig::CRON_PRICE_PULL)) {
            return ['wyłączone w konfiguracji'];
        }

        $next = $this->priceNextRun();
        if ($dryRun) {
            return array_merge([$next === null ? 'harmonogram: przy tym przebiegu' : 'harmonogram: następny przebieg od ' . $next], $this->pricePull(true, false));
        }
        if (!$explicit && $next !== null) {
            return [self::NOT_DUE . $next];
        }

        // jedna próba na dobę, także nieudana: błąd nie powtarza ciężkiego przebiegu co kilka minut
        $this->config->set(InfinitySubiektConfig::PRICE_PULL_TRIED, $this->clock->today());

        return $this->pricePull(false, false);
    }

    /**
     * @return array zadanie => ['at' => …, 'lines' => string[]]
     */
    private function jobLog()
    {
        $log = InfinitySubiektJson::decode($this->config->get(InfinitySubiektConfig::CRON_JOB_LOG));
        $clean = [];
        foreach (is_array($log) ? $log : [] as $name => $entry) {
            if (is_array($entry) && isset($entry['at'], $entry['lines']) && is_array($entry['lines'])) {
                $clean[(string) $name] = ['at' => (string) $entry['at'], 'lines' => array_map('strval', $entry['lines'])];
            }
        }

        return $clean;
    }

    /**
     * @return string|null kiedy następny stock_pull z harmonogramu, null = teraz
     */
    private function stockNextRun()
    {
        $interval = max(0, (int) $this->config->get(InfinitySubiektConfig::CRON_STOCK_INTERVAL));
        $last = strtotime($this->config->get(InfinitySubiektConfig::CRON_STOCK_LAST));
        if ($interval === 0 || $last === false) {
            return null;
        }

        $next = $last + $interval * 60;
        // minuta zapasu: cron co 5 min z przebiegiem trwającym chwilę nie przesuwa godziny co raz
        return $next - 60 <= strtotime($this->clock->now()) ? null : date('Y-m-d H:i', $next);
    }

    /**
     * @return string|null kiedy następny price_pull z harmonogramu, null = teraz
     */
    private function priceNextRun()
    {
        $hour = min(23, max(0, (int) $this->config->get(InfinitySubiektConfig::CRON_PRICE_HOUR)));
        $now = strtotime($this->clock->now());
        $today = $this->clock->today();
        $start = sprintf('%s %02d:00', $today, $hour);

        if ($this->config->get(InfinitySubiektConfig::PRICE_PULL_TRIED) === $today) {
            return sprintf('%s %02d:00', date('Y-m-d', strtotime($today . ' +1 day')), $hour);
        }

        return $now < strtotime($start) ? $start : null;
    }

    /**
     * Ceny zakupu wszystkich kupowanych towarów: tylko wskazane wprost (jobs=price_pull_full), także przy wyłączonym
     * pobieraniu cen. Dry-run pokazuje liczby zawsze.
     *
     * @param bool $dryRun
     * @param bool $explicit
     *
     * @return string[]
     */
    private function jobPricePullFull($dryRun, $explicit)
    {
        if (!$explicit && !$dryRun) {
            return [self::ON_DEMAND . ' (jobs=price_pull_full)'];
        }
        if (!$explicit) {
            // dopóki price_pull jest pełny, dry-run nie liczy tego drugi raz
            return $this->catalogPull->pricePullIsFull()
                ? ['tylko na żądanie (jobs=price_pull_full); teraz to samo co price_pull, bo nie było jeszcze udanego przebiegu cen']
                : array_merge(['tylko na żądanie (jobs=price_pull_full), objąłby:'], $this->pricePull(true, true));
        }

        return $this->pricePull($dryRun, true);
    }

    /**
     * @param bool $dryRun
     * @param bool $full
     *
     * @return string[]
     */
    private function pricePull($dryRun, $full)
    {
        @set_time_limit(0);
        if ($dryRun) {
            return $this->catalogPull->previewPrices($full);
        }

        return $this->reportLines($this->catalogPull->pullPrices($full));
    }

    /**
     * @param array $report
     *
     * @return string[]
     */
    private function reportLines(array $report)
    {
        return $this->catalogPull->reportLines($report, self::REPORT_DETAILS);
    }

    /**
     * @param string[] $lines
     */
    private function saveLog(array $lines)
    {
        $log = implode("\n", $lines);
        if (Tools::strlen($log) > self::LOG_MAX) {
            $log = Tools::substr($log, 0, self::LOG_MAX) . "\n…";
        }

        $this->config->set(InfinitySubiektConfig::CRON_LAST_RUN, $this->clock->now());
        $this->config->set(InfinitySubiektConfig::CRON_LOG, $log);
    }

    /**
     * @param string $status
     * @param string[] $lines
     *
     * @return array
     */
    private function result($status, array $lines)
    {
        return ['status' => $status, 'lines' => $lines];
    }
}
