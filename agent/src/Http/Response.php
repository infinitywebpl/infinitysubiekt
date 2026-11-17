<?php

declare(strict_types=1);

namespace InfinitySubiektAgent\Http;

if (PHP_SAPI !== 'cli') {
    exit;
}

/**
 * Odpowiedź HTTP sklepu. status 0 = żądanie przerwane zatrzymaniem agenta.
 */
final readonly class Response
{
    public const ABORTED = 0;

    public function __construct(
        public int $status,
        public string $body,
    ) {
    }

    public function json(): mixed
    {
        return $this->body === '' ? null : json_decode($this->body, true);
    }

    /**
     * Początek body do logu (np. strona błędu serwera zamiast JSON).
     */
    public function excerpt(int $length = 200): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($this->body)));

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '…' : $text;
    }
}
