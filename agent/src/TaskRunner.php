<?php

declare(strict_types=1);

namespace InfinitySubiektAgent;

if (PHP_SAPI !== 'cli') {
    exit;
}

use InfinitySubiektAgent\Method\Registry;
use InfinitySubiektAgent\Subiekt\Sfera;

/**
 * Wykonanie jednej metody i zamiana wyniku na body dla op=result:
 * {"result": …} albo {"error": …}. Wspólne dla pętli (etap 2) i polecenia `call`.
 */
final readonly class TaskRunner
{
    public function __construct(
        private Registry $registry,
        private Sfera $sfera,
        private Log $log,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{result: array<string, mixed>}|array{error: string}
     */
    public function run(string $method, array $params): array
    {
        $handler = $this->registry->get($method);
        if ($handler === null) {
            $this->log->error('Nieznana metoda: ' . $method);

            return ['error' => 'unknown method'];
        }

        $start = microtime(true);
        try {
            $result = $handler->execute($params);
        } catch (AgentException $e) {
            $this->log->error($method . ': ' . $e->getMessage());

            return ['error' => Text::utf8($e->getMessage())];
        } catch (\Throwable $e) {
            // zadania nie ponawiamy: o ponowieniu decyduje moduł
            if (Sfera::isConnectionLost($e)) {
                $this->sfera->disconnect();
            }
            $error = (new \ReflectionClass($e))->getShortName() . ': ' . Sfera::describe($e);
            $this->log->error($method . ': ' . $error, ['file' => $e->getFile() . ':' . $e->getLine()]);

            return ['error' => $error];
        }

        $this->log->info(sprintf('%s: wykonano w %.1f s', $method, microtime(true) - $start));

        // wynik z kluczem error to też błąd; zostawiamy go modułowi w tej postaci
        return ['result' => $result];
    }
}
