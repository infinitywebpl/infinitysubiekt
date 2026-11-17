<?php

declare(strict_types=1);

namespace InfinitySubiektAgent\Http;

if (PHP_SAPI !== 'cli') {
    exit;
}

use InfinitySubiektAgent\Config;
use InfinitySubiektAgent\StopSignal;
use InfinitySubiektAgent\Text;
use InfinitySubiektAgent\Version;

/**
 * Kolejka w sklepie przez HTTP. Adres to endpoint z modułu bez zmian:
 * agent dokleja tylko parametry, po ? albo &, zależnie od przyjaznych URL-i.
 */
final class QueueClient
{
    private const CONNECT_TIMEOUT = 10;

    // wynik może nieść PDF w base64, więc wysyłka ma dłuższy limit niż poll
    private const RESULT_TIMEOUT = 60;

    private ?\CurlHandle $curl = null;

    public function __construct(
        private readonly Config $config,
        private readonly StopSignal $stop,
    ) {
    }

    /**
     * GET op=poll. Przerywany od razu, gdy agent ma się zatrzymać (status ABORTED).
     *
     * @throws QueueException błąd sieci
     */
    public function poll(): Response
    {
        return $this->request('GET', ['op' => 'poll', 'wait' => $this->config->pollWait], null, $this->config->httpTimeout, true);
    }

    /**
     * POST op=result z body {"result": …} albo {"error": …}. Nie jest przerywany zatrzymaniem.
     *
     * @param array{result: array<string, mixed>}|array{error: string} $body
     *
     * @throws QueueException błąd sieci
     */
    public function sendResult(int $taskId, array $body): Response
    {
        // pusty wynik ma być obiektem JSON ({}), nie listą ([])
        if (array_key_exists('result', $body) && $body['result'] === []) {
            $body['result'] = new \stdClass();
        }

        return $this->request(
            'POST',
            ['op' => 'result', 'id' => $taskId],
            Text::json($body),
            max($this->config->httpTimeout, self::RESULT_TIMEOUT),
            false
        );
    }

    /**
     * GET pliku aktualizacji: op=release (podpisany manifest) albo op=file&path=….
     * Nie jest przerywany zatrzymaniem.
     *
     * @param array<string, scalar> $query
     *
     * @throws QueueException błąd sieci
     */
    public function download(array $query): Response
    {
        return $this->request('GET', $query, null, max($this->config->httpTimeout, self::RESULT_TIMEOUT), false);
    }

    /**
     * @param array<string, scalar> $query
     *
     * @throws QueueException
     */
    private function request(string $method, array $query, ?string $body, int $timeout, bool $abortable): Response
    {
        $curl = $this->curl ??= curl_init();
        curl_reset($curl);

        $headers = [
            'X-Agent-Token: ' . $this->config->token,
            'Accept: ' . ($query['op'] === 'file' ? 'application/octet-stream' : 'application/json'),
        ];
        $options = [
            CURLOPT_URL => $this->url($query),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'InfinitySubiektAgent/' . Version::NUMBER,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($this->config->caFile !== null) {
            $options[CURLOPT_CAINFO] = $this->config->caFile;
        }
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = (string) $body;
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        }
        if ($abortable) {
            // curl woła funkcję mniej więcej co sekundę także w trakcie czekania na odpowiedź
            $options[CURLOPT_NOPROGRESS] = false;
            $options[CURLOPT_XFERINFOFUNCTION] = fn (): int => $this->stop->requested() ? 1 : 0;
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($curl, $options);

        $responseBody = curl_exec($curl);
        if ($responseBody === false) {
            if (curl_errno($curl) === CURLE_ABORTED_BY_CALLBACK) {
                return new Response(Response::ABORTED, '');
            }

            throw new QueueException(sprintf('%s %s: %s (curl %d)', $method, $query['op'], curl_error($curl), curl_errno($curl)));
        }

        return new Response((int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), (string) $responseBody);
    }

    /**
     * @param array<string, scalar> $query
     */
    private function url(array $query): string
    {
        $endpoint = $this->config->endpoint;

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($query);
    }
}
