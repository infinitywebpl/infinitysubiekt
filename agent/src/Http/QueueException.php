<?php

declare(strict_types=1);

namespace InfinitySubiektAgent\Http;

if (PHP_SAPI !== 'cli') {
    exit;
}

use InfinitySubiektAgent\AgentException;

/**
 * Błąd sieci przy połączeniu ze sklepem (DNS, timeout, SSL). Agent czeka i próbuje ponownie.
 */
final class QueueException extends AgentException
{
}
