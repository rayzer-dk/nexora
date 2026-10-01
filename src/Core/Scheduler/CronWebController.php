<?php

declare(strict_types=1);

namespace Commerce\Core\Scheduler;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Web-cron: hosting without SSH can call this URL every 5 minutes. The secret token is the only credential. */
final class CronWebController
{
    public function __construct(private readonly CronRunner $runner, private readonly CronSettings $settings)
    {
    }

    #[Route('/cron/{token}', name: 'cron_web_run', requirements: ['token' => '[a-f0-9]{40}'], methods: ['GET', 'POST', 'HEAD'])]
    public function run(string $token): Response
    {
        if (!$this->settings->tokenMatches($token)) {
            return $this->json(['status' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        @ignore_user_abort(true);
        @set_time_limit(120);
        try {
            $result = $this->runner->run('web', null, false, 50.0);
        } catch (\Throwable) {
            return $this->json(['status' => 'error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        if ($result['locked']) {
            return $this->json(['status' => 'busy']);
        }

        return $this->json(['status' => $result['failed'] === [] ? 'ok' : 'partial', 'ran' => count($result['ran']), 'failed' => count($result['failed']), 'skipped' => $result['skipped']]);
    }

    /** @param array<string,mixed> $data */
    private function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
