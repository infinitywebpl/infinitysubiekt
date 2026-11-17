<?php
/**
 * Endpoint agenta:
 * GET  {endpoint}?op=poll&wait=N
 * POST {endpoint}?op=result&id=N
 * GET  {endpoint}?op=release           podpisany manifest wydania (zdalna aktualizacja)
 * GET  {endpoint}?op=file&path=…       plik z manifestu
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektAgentEndpoint
{
    /** @var InfinitySubiektTokenAuth */
    private $auth;

    /** @var InfinitySubiektTaskQueue */
    private $queue;

    /** @var InfinitySubiektAgentStatus */
    private $status;

    /** @var InfinitySubiektResponse */
    private $response;

    /** @var InfinitySubiektAgentRelease */
    private $release;

    public function __construct(
        InfinitySubiektTokenAuth $auth,
        InfinitySubiektTaskQueue $queue,
        InfinitySubiektAgentStatus $status,
        InfinitySubiektResponse $response,
        InfinitySubiektAgentRelease $release
    ) {
        $this->auth = $auth;
        $this->queue = $queue;
        $this->status = $status;
        $this->response = $response;
        $this->release = $release;
    }

    /**
     * Zawsze kończy żądanie (exit w InfinitySubiektResponse).
     *
     * @param InfinitySubiektRequest $request
     */
    public function handle(InfinitySubiektRequest $request)
    {
        if (!$this->auth->isValid($request->header('X-Agent-Token'))) {
            $this->response->json(403, ['error' => 'forbidden']);
        }

        switch ($request->query('op')) {
            case 'poll':
                if ($request->method() !== 'GET') {
                    $this->response->json(405, ['error' => 'method not allowed']);
                }
                $this->poll((int) $request->query('wait', 0), $request->header('User-Agent'));
                break;
            case 'release':
                $raw = $this->release->raw();
                if ($raw === null) {
                    $this->response->json(404, ['error' => 'no release']);
                }
                $this->response->text(200, $raw);
                break;
            case 'file':
                $path = $this->release->filePath((string) $request->query('path', ''));
                if ($path === null) {
                    $this->response->json(404, ['error' => 'not found']);
                }
                $this->response->file($path, basename($path), 'application/octet-stream', 'attachment');
                break;
            case 'result':
                if ($request->method() !== 'POST') {
                    $this->response->json(405, ['error' => 'method not allowed']);
                }
                $this->result((int) $request->query('id', 0), $request);
                break;
        }

        $this->response->json(404, ['error' => 'not found']);
    }

    /**
     * Poll: heartbeat, porządki, czekanie na zadanie do `wait` s (nie dłużej niż POLL_WAIT).
     *
     * @param int $wait
     * @param string|null $userAgent
     */
    private function poll($wait, $userAgent)
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(0);

        $this->status->touch($userAgent);
        $this->queue->maintenance();

        $wait = $wait > 0 ? min($wait, InfinitySubiektConfig::POLL_WAIT) : InfinitySubiektConfig::POLL_WAIT;
        $deadline = time() + $wait;

        while (true) {
            $task = $this->queue->claimNext();
            if ($task !== null) {
                $this->response->json(200, ['task' => $task]);
            }
            if (time() >= $deadline) {
                break;
            }
            sleep(1);
        }

        $this->response->noContent();
    }

    /**
     * Wynik zadania od agenta.
     *
     * @param int $id
     * @param InfinitySubiektRequest $request
     */
    private function result($id, InfinitySubiektRequest $request)
    {
        $body = $request->jsonBody();
        if ($body === null) {
            $this->response->json(400, ['error' => 'invalid json']);
        }

        list($code, $data) = $this->queue->saveResult($id, $body);
        $this->response->json($code, $data);
    }
}
