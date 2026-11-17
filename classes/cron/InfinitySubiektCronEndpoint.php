<?php
/**
 * Wejście HTTP crona (wget/curl): ?token=…&jobs=a,b&dry_run=1.
 * Odpowiedź text/plain: 403 zły token, 400 nieznane zadanie, 409 przebieg w toku, inaczej 200.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektCronEndpoint
{
    /** @var InfinitySubiektTokenAuth */
    private $auth;

    /** @var InfinitySubiektCron */
    private $cron;

    /** @var InfinitySubiektResponse */
    private $response;

    public function __construct(
        InfinitySubiektTokenAuth $auth,
        InfinitySubiektCron $cron,
        InfinitySubiektResponse $response
    ) {
        $this->auth = $auth;
        $this->cron = $cron;
        $this->response = $response;
    }

    public function handle(InfinitySubiektRequest $request)
    {
        if (!$this->auth->isValid($request->query('token'))) {
            $this->response->text(403, "forbidden\n");
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(0);
        ignore_user_abort(true);

        $result = $this->cron->run(
            InfinitySubiektCron::parseJobs($request->query('jobs')),
            (bool) $request->query('dry_run'),
            InfinitySubiektCron::SOURCE_HTTP
        );

        $codes = [
            InfinitySubiektCron::STATUS_INVALID => 400,
            InfinitySubiektCron::STATUS_BUSY => 409,
        ];
        $code = isset($codes[$result['status']]) ? $codes[$result['status']] : 200;

        $this->response->text($code, implode("\n", $result['lines']) . "\n");
    }
}
