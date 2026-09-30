<?php

declare(strict_types=1);

namespace Commerce\Modules\Automation\Application;

use Commerce\Core\Security\OutboundUrlPolicy;
use Commerce\Modules\Notification\Application\NotificationOutbox;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "When X happens, if Y, do Z". Each (rule, event reference) pair runs at most once, so a retried
 * domain event or a double submit never fires the same action twice. A failing action is recorded
 * and never breaks the business operation that triggered it.
 */
final readonly class AutomationEngine
{
    public function __construct(
        private Connection $db,
        private NotificationOutbox $notifications,
        private OutboundUrlPolicy $urls,
        private HttpClientInterface $http,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param array<string,mixed> $context keys: number, total_minor, currency, name, text
     * @return int number of rules that ran
     */
    public function fire(int $storeId, string $event, string $ref, array $context): int
    {
        if (!in_array($event, AutomationCatalog::EVENTS, true) || $ref === '') {
            return 0;
        }
        try {
            $rules = $this->db->fetchAllAssociative('SELECT * FROM mc_automation_rule WHERE store_id=? AND event_name=? AND enabled=1 ORDER BY id', [$storeId, $event]);
        } catch (\Throwable) {
            return 0;
        }
        $ran = 0;
        foreach ($rules as $rule) {
            $min = $rule['min_total_minor'] !== null ? (int) $rule['min_total_minor'] : null;
            if ($min !== null && in_array($event, AutomationCatalog::ORDER_EVENTS, true) && (int) ($context['total_minor'] ?? 0) < $min) {
                continue;
            }
            $now = gmdate('Y-m-d H:i:s.u');
            $claimed = $this->db->executeStatement("INSERT IGNORE INTO mc_automation_run (rule_id,event_ref,status,message,created_at) VALUES (?,?,'running','',?)", [(int) $rule['id'], mb_substr($ref, 0, 190), $now]);
            if ($claimed !== 1) {
                continue;
            }
            $status = 'ok';
            $message = '';
            try {
                $this->execute($rule, $event, $ref, $context);
            } catch (\Throwable $e) {
                $status = 'failed';
                $message = mb_substr($e->getMessage() !== '' ? $e->getMessage() : $e::class, 0, 300);
                $this->logger?->warning('Automation rule failed', ['rule' => (int) $rule['id'], 'error' => $message]);
            }
            $this->db->executeStatement('UPDATE mc_automation_run SET status=?,message=? WHERE rule_id=? AND event_ref=?', [$status, $message, (int) $rule['id'], mb_substr($ref, 0, 190)]);
            $this->db->executeStatement('UPDATE mc_automation_rule SET run_count=run_count+1,last_run_at=? WHERE id=?', [$now, (int) $rule['id']]);
            $ran++;
        }

        return $ran;
    }

    /** @param array<string,mixed> $rule @param array<string,mixed> $context */
    private function execute(array $rule, string $event, string $ref, array $context): void
    {
        $text = $this->render((string) $rule['action_text'], $rule, $event, $context);
        $subject = mb_substr((string) $rule['name'], 0, 120);
        $dedupe = 'automation:' . (int) $rule['id'] . ':' . md5($ref);
        $message = new NotificationMessage('automation.' . $event, $subject, $text, ['url' => (string) ($context['url'] ?? '/admin')], 'generic');
        switch ((string) $rule['action_type']) {
            case AutomationCatalog::ACTION_EMAIL:
                $to = trim((string) $rule['action_target']);
                if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                    throw new \InvalidArgumentException('automation_invalid_email_target');
                }
                $this->notifications->enqueue(NotificationChannel::Email, $message, $to, null, $dedupe);
                break;
            case AutomationCatalog::ACTION_TELEGRAM:
                $this->notifications->enqueue(NotificationChannel::Telegram, $message, trim((string) $rule['action_target']), null, $dedupe);
                break;
            case AutomationCatalog::ACTION_PUSH:
                $this->notifications->enqueue(NotificationChannel::WebPush, $message, 'admin:' . (int) $rule['store_id'], null, $dedupe);
                break;
            case AutomationCatalog::ACTION_WEBHOOK:
                $url = trim((string) $rule['action_target']);
                $this->urls->assertPublicHttps($url, false);
                $response = $this->http->request('POST', $url, [
                    'json' => ['event' => $event, 'reference' => $ref, 'text' => $text, 'context' => array_diff_key($context, ['url' => 1])],
                    'headers' => ['User-Agent' => 'Nexora-Commerce-Automation/1'],
                    'timeout' => 3.0,
                    'max_redirects' => 0,
                ]);
                if ($response->getStatusCode() >= 300) {
                    throw new \RuntimeException('webhook_http_' . $response->getStatusCode());
                }
                break;
            default:
                throw new \InvalidArgumentException('automation_unknown_action');
        }
    }

    /** @param array<string,mixed> $rule @param array<string,mixed> $context */
    private function render(string $template, array $rule, string $event, array $context): string
    {
        $currency = (string) ($context['currency'] ?? '');
        $total = isset($context['total_minor']) ? number_format(((int) $context['total_minor']) / 100, 2, '.', ' ') . ($currency !== '' ? ' ' . $currency : '') : '';
        $fallback = trim((string) ($context['text'] ?? ''));
        $text = $template !== '' ? $template : ($fallback !== '' ? $fallback : $event);

        return mb_substr(strtr($text, [
            '{number}' => (string) ($context['number'] ?? ''),
            '{total}' => $total,
            '{name}' => (string) ($context['name'] ?? ''),
            '{event}' => $event,
        ]), 0, 1000);
    }
}
