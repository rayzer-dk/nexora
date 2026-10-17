<?php

declare(strict_types=1);

namespace Commerce\Modules\Integration\Crm;

use Commerce\Core\Security\OutboundUrlPolicy;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Sends one order to one connection: renders the owner's template, signs the body and posts it. A failure is thrown so the queue tries again. */
final class CrmDispatcher
{
    public function __construct(
        private readonly CrmConnectionRepository $connections,
        private readonly OrderSnapshotBuilder $snapshots,
        private readonly CrmTemplateRenderer $renderer,
        private readonly HttpClientInterface $http,
        private readonly OutboundUrlPolicy $policy,
        private readonly bool $allowPrivateHosts = false,
    ) {
    }

    /** The queue job: {connection_id, order, event}. */
    public function handle(array $job): void
    {
        $payload = json_decode((string) ($job['payload'] ?? ''), true);
        if (!is_array($payload)) {
            return;
        }
        $connection = $this->connections->find((int) ($payload['connection_id'] ?? 0));
        if ($connection === null || !$connection['enabled']) {
            return;
        }
        $snapshot = $this->snapshots->build((string) ($payload['order'] ?? ''));
        if ($snapshot === null) {
            return;
        }
        $this->deliver($connection, $snapshot, (string) ($payload['event'] ?? 'placed'));
    }

    /**
     * @param array<string,mixed> $connection
     * @param array<string,mixed> $snapshot
     * @return array{status:int,body:string}
     */
    public function deliver(array $connection, array $snapshot, string $event): array
    {
        $number = (string) ($snapshot['order']['number'] ?? '');
        try {
            $result = $this->send($connection, $snapshot, $event);
        } catch (\Throwable $e) {
            $this->connections->log((int) $connection['id'], $number, $event, 'failed', null, $e->getMessage());
            throw $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage(), 0, $e);
        }
        $ok = $result['status'] >= 200 && $result['status'] < 300;
        $this->connections->log((int) $connection['id'], $number, $event, $ok ? 'ok' : 'failed', $result['status'], $result['body']);
        if (!$ok) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.error.integration.crm.http_prefix') . $result['status'] . ': ' . mb_substr($result['body'], 0, 200));
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $connection
     * @param array<string,mixed> $snapshot
     * @return array{status:int,body:string}
     */
    private function send(array $connection, array $snapshot, string $event): array
    {
        $body = $this->renderer->render((string) $connection['body_template'], $snapshot, $event);
        $url = (string) $connection['url'];
        if (!$this->allowPrivateHosts) {
            $this->policy->assertPublicHttps($url);
        } elseif (preg_match('~^https?://~i', $url) !== 1) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.error.integration.crm.url'));
        }
        $headers = [
            'Content-Type' => 'application/json', 'Accept' => 'application/json', 'User-Agent' => 'Nexora-Commerce/' . \Commerce\Core\Platform\PlatformVersion::VERSION,
            'X-Nexora-Event' => $event, 'X-Nexora-Signature' => 'sha256=' . hash_hmac('sha256', $body, (string) $connection['signing_secret']),
        ] + (array) $connection['headers'];
        $response = $this->http->request((string) $connection['method'], $url, ['body' => $body, 'headers' => $headers, 'timeout' => 10, 'max_redirects' => 0]);
        $status = $response->getStatusCode();

        return ['status' => $status, 'body' => trim((string) preg_replace('/\s+/u', ' ', $response->getContent(false)))];
    }
}
