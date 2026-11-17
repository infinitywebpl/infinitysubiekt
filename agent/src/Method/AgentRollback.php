<?php

declare(strict_types=1);

namespace InfinitySubiektAgent\Method;

if (PHP_SAPI !== 'cli') {
    exit;
}

use InfinitySubiektAgent\AgentException;
use InfinitySubiektAgent\Config;
use InfinitySubiektAgent\Log;
use InfinitySubiektAgent\Release;
use InfinitySubiektAgent\Restart;

/**
 * agent/rollback: powrót do poprzedniej wersji z releases\previous.txt, a gdy jej nie ma,
 * do kodu z src\ obok launchera (układ z paczki ZIP). Restart po wysłaniu wyniku.
 */
final readonly class AgentRollback implements Method
{
    public function __construct(
        private Config $config,
        private Release $release,
        private Restart $restart,
        private Log $log,
    ) {
    }

    public function execute(array $params): array
    {
        if (!$this->config->updateEnabled) {
            throw new AgentException('zdalna aktualizacja jest wyłączona: ustaw update_enabled => true w config.php agenta');
        }

        $previous = $this->release->previous();
        if (Release::validVersion($previous) && is_file($this->release->path($previous) . '/src/autoload.php')) {
            $this->release->setPending($previous);
            $target = $previous;
        } elseif ($this->release->fromRelease() && is_file($this->release->root() . '/src/autoload.php')) {
            $this->release->useRoot();
            $target = 'src';
        } else {
            throw new AgentException('brak poprzedniej wersji do przywrócenia');
        }

        $this->restart->request('powrót do ' . $target);
        $this->log->info('agent/rollback: powrót do ' . $target . ', restart po wysłaniu wyniku');

        return ['status' => 'rollback', 'version' => $target];
    }
}
