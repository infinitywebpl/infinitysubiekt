<?php

declare(strict_types=1);

namespace InfinitySubiektAgent;

if (PHP_SAPI !== 'cli') {
    exit;
}

use InfinitySubiektAgent\Http\QueueClient;
use InfinitySubiektAgent\Method\Registry;
use InfinitySubiektAgent\Subiekt\Sfera;
use InfinitySubiektAgent\Subiekt\Sql;

/**
 * Usługi agenta tworzone przy pierwszym użyciu; Sfera i SQL żyją tak długo jak proces.
 */
final class Services
{
    private ?Sfera $sfera = null;

    private ?Sql $sql = null;

    private ?Registry $registry = null;

    private ?QueueClient $queue = null;

    public readonly StopSignal $stop;

    public readonly Restart $restart;

    public function __construct(
        public readonly Config $config,
        public readonly Log $log,
        public readonly Release $release,
    ) {
        $this->stop = new StopSignal();
        $this->restart = new Restart();
    }

    public function agent(): Agent
    {
        return new Agent(
            $this->config,
            $this->log,
            $this->queue(),
            $this->taskRunner(),
            $this->sfera(),
            $this->stop,
            $this->release,
            $this->restart
        );
    }

    public function queue(): QueueClient
    {
        return $this->queue ??= new QueueClient($this->config, $this->stop);
    }

    public function sfera(): Sfera
    {
        return $this->sfera ??= new Sfera($this->config->subiekt, $this->log);
    }

    public function sql(): Sql
    {
        return $this->sql ??= new Sql($this->config->subiekt, $this->config->sql, $this->log);
    }

    public function registry(): Registry
    {
        return $this->registry ??= new Registry($this);
    }

    public function taskRunner(): TaskRunner
    {
        return new TaskRunner($this->registry(), $this->sfera(), $this->log);
    }

    public function lock(): ProcessLock
    {
        return new ProcessLock($this->config->logDir);
    }
}
