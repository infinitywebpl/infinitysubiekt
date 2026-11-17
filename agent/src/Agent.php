<?php

declare(strict_types=1);

namespace InfinitySubiektAgent;

if (PHP_SAPI !== 'cli') {
    exit;
}

use InfinitySubiektAgent\Http\QueueClient;
use InfinitySubiektAgent\Http\QueueException;
use InfinitySubiektAgent\Http\Response;
use InfinitySubiektAgent\Http\Task;
use InfinitySubiektAgent\Subiekt\Sfera;

/**
 * Pętla agenta: poll → wykonaj → result, zadania jedno po drugim.
 */
final class Agent
{
    // s czekania po kolejnych błędach pollu; po udanym pollu od początku
    private const BACKOFF = [1, 2, 5, 10, 30, 60];

    // zły token: nie ma sensu pytać częściej
    private const FORBIDDEN_WAIT = 60;

    // wysyłka wyniku: kolejne próby i przerwy między nimi (s)
    private const RESULT_RETRY = [1, 2, 5, 10, 30];

    // po takiej przerwie bez zadań sprawdzamy połączenie ze Sferą przed zadaniem (s)
    private const IDLE_CHECK = 600;

    private int $failures = 0;

    private int $lastTask;

    private readonly int $startedAt;

    private bool $confirmed = false;

    public function __construct(
        private readonly Config $config,
        private readonly Log $log,
        private readonly QueueClient $queue,
        private readonly TaskRunner $runner,
        private readonly Sfera $sfera,
        private readonly StopSignal $stop,
        private readonly Release $release,
        private readonly Restart $restart,
    ) {
        $this->startedAt = time();
        $this->lastTask = time();
    }

    /**
     * @param bool $once jeden poll i najwyżej jedno zadanie (test)
     *
     * @return int kod wyjścia (Cli::EXIT_*)
     */
    public function run(bool $once): int
    {
        $this->log->info(sprintf(
            'Agent %s start (PID %d): %s, poll %d s, limity %d h / %d MB',
            Version::NUMBER,
            getmypid(),
            $this->config->endpoint,
            $this->config->pollWait,
            $this->config->maxRuntimeHours,
            $this->config->maxMemoryMb
        ));
        $this->warmUp();

        while (!$this->stop->requested()) {
            $limit = $this->limitReached();
            if ($limit !== null) {
                $this->log->info('Koniec pracy: ' . $limit . '. Usługa uruchomi agenta od nowa.');

                return Cli::EXIT_OK;
            }

            try {
                $response = $this->queue->poll();
            } catch (QueueException $e) {
                $this->log->error('Poll: ' . $e->getMessage());
                if ($once) {
                    return Cli::EXIT_ERROR;
                }
                $this->backoff();
                continue;
            }

            switch ($response->status) {
                case Response::ABORTED:
                    break;

                case 204:
                    $this->failures = 0;
                    $this->confirmRelease();
                    if ($once) {
                        $this->log->info('Brak zadań w kolejce.');

                        return Cli::EXIT_OK;
                    }
                    break;

                case 200:
                    $this->failures = 0;
                    $this->confirmRelease();
                    $task = Task::fromResponse($response->json());
                    if ($task === null) {
                        $this->log->error('Poll: niepoprawna odpowiedź 200: ' . $response->excerpt());
                        if ($once) {
                            return Cli::EXIT_ERROR;
                        }
                        $this->backoff();
                        break;
                    }
                    $delivered = $this->handle($task);
                    $restart = $this->restart->reason();
                    if ($restart !== null) {
                        $this->log->info('Koniec pracy: ' . $restart . '. Usługa uruchomi agenta od nowa.');

                        return Cli::EXIT_OK;
                    }
                    if ($once) {
                        return $delivered ? Cli::EXIT_OK : Cli::EXIT_ERROR;
                    }
                    break;

                case 403:
                    $this->log->error('Poll: sklep odrzucił token (403). Sprawdź token w config.php i w zakładce Ustawienia modułu.');
                    if ($once) {
                        return Cli::EXIT_ERROR;
                    }
                    $this->stop->sleep(self::FORBIDDEN_WAIT);
                    break;

                default:
                    $this->log->error(sprintf('Poll: HTTP %d: %s', $response->status, $response->excerpt()));
                    if ($once) {
                        return Cli::EXIT_ERROR;
                    }
                    $this->backoff();
            }
        }

        $this->log->info('Agent zatrzymany.');

        return Cli::EXIT_OK;
    }

    /**
     * Wykonanie zadania i dostarczenie wyniku.
     *
     * @return bool czy wynik dotarł do sklepu
     */
    private function handle(Task $task): bool
    {
        $this->log->info(sprintf('Zadanie #%d %s', $task->id, $task->method));

        if ($this->sfera->isConnected() && time() - $this->lastTask > self::IDLE_CHECK) {
            try {
                $this->sfera->ensureAlive();
            } catch (\Throwable $e) {
                // zadanie i tak się wykona i zgłosi błąd połączenia w wyniku
                $this->log->error('Sfera po bezczynności: ' . Sfera::describe($e));
            }
        }

        $body = $this->runner->run($task->method, $task->params);
        $this->lastTask = time();

        return $this->deliver($task, $body);
    }

    /**
     * POST result z ponawianiem przy błędach sieci i 5xx. Serwer jest idempotentny.
     *
     * @param array{result: array<string, mixed>}|array{error: string} $body
     */
    private function deliver(Task $task, array $body): bool
    {
        $attempts = count(self::RESULT_RETRY) + 1;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->queue->sendResult($task->id, $body);

                switch (true) {
                    case $response->status === 200:
                        $this->log->info(sprintf('Zadanie #%d: wynik przyjęty (%s)', $task->id, isset($body['error']) ? 'błąd' : 'OK'));

                        return true;

                    case in_array($response->status, [404, 409], true):
                        // zadanie zniknęło albo nie jest już nasze (np. maintenance oddało je ponownie)
                        $this->log->error(sprintf('Zadanie #%d: sklep nie przyjął wyniku (HTTP %d): %s', $task->id, $response->status, $response->excerpt()));

                        return false;

                    case $response->status === 403:
                        $this->log->error(sprintf('Zadanie #%d: sklep odrzucił token przy wysyłce wyniku (403)', $task->id));

                        return false;

                    // 3xx (np. przekierowanie z http na https) i inne 4xx: ponowienie nic nie zmieni
                    case $response->status < 500:
                        $this->log->error(sprintf('Zadanie #%d: wynik odrzucony (HTTP %d): %s', $task->id, $response->status, $response->excerpt()));

                        return false;
                }

                $this->log->error(sprintf('Zadanie #%d: wysyłka wyniku, HTTP %d (próba %d/%d)', $task->id, $response->status, $attempt, $attempts));
            } catch (QueueException $e) {
                $this->log->error(sprintf('Zadanie #%d: wysyłka wyniku: %s (próba %d/%d)', $task->id, $e->getMessage(), $attempt, $attempts));
            }

            if ($attempt < $attempts) {
                // bez przerywania zatrzymaniem: wynik (np. numer faktury) ważniejszy niż szybki stop
                sleep(self::RESULT_RETRY[$attempt - 1]);
            }
        }

        $this->log->error(sprintf('Zadanie #%d: wynik nie dotarł do sklepu. Moduł odda zadanie ponownie po 10 min (maintenance).', $task->id));

        return false;
    }

    /**
     * Pierwszy udany poll potwierdza wersję na próbie (launcher przestaje liczyć starty).
     */
    private function confirmRelease(): void
    {
        if ($this->confirmed) {
            return;
        }
        $this->confirmed = true;

        try {
            $this->release->confirm($this->log);
        } catch (\Throwable $e) {
            $this->log->error('Potwierdzenie wersji: ' . $e->getMessage());
        }
    }

    /**
     * Połączenie ze Sferą od razu, żeby pierwsze zadanie nie czekało na start Sfery.
     */
    private function warmUp(): void
    {
        try {
            $this->sfera->ensureAlive();
        } catch (\Throwable $e) {
            // agent pracuje dalej: ping zgłosi problem w module, a każde zadanie spróbuje połączyć się od nowa
            $this->log->error('Sfera przy starcie: ' . Sfera::describe($e));
        }
    }

    /**
     * Czas życia i pamięć. 0 w konfiguracji wyłącza limit.
     */
    private function limitReached(): ?string
    {
        $hours = $this->config->maxRuntimeHours;
        if ($hours > 0 && time() - $this->startedAt >= $hours * 3600) {
            return sprintf('minęło %d h', $hours);
        }

        $memoryMb = (int) (memory_get_usage(true) / 1048576);
        if ($this->config->maxMemoryMb > 0 && $memoryMb >= $this->config->maxMemoryMb) {
            return sprintf('pamięć %d MB', $memoryMb);
        }

        return null;
    }

    private function backoff(): void
    {
        $seconds = self::BACKOFF[min($this->failures, count(self::BACKOFF) - 1)];
        $this->failures++;
        $this->stop->sleep($seconds);
    }
}
