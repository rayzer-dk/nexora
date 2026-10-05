<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Doctrine\DBAL\Connection;

/**
 * Merchant-editable subject and text for the main transactional e-mails. Templates use %placeholders%; when no
 * override is stored (or it is switched off) the built-in localized text is sent unchanged.
 */
final class NotificationTemplateService
{
    /** @var array<string,list<string>> code => allowed placeholders */
    public const CATALOG = [
        'order.created' => ['order_number', 'customer_name', 'total', 'store_name'],
        'order.status_updated' => ['order_number', 'customer_name', 'status', 'payment_status', 'fulfillment_status', 'tracking_number', 'total', 'store_name'],
        'return.status' => ['order_number', 'status', 'store_name'],
        'inquiry_received' => ['store_name'],
        'newsletter.confirm' => ['store_name'],
    ];

    /** @var array{id:int,name:string,locale:string}|null */
    private ?array $primary = null;

    public function __construct(
        private readonly Connection $db,
        private readonly \Commerce\Core\I18n\StorefrontUiTranslator $translator,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'html_sanitizer.sanitizer.commerce.rich_text')] private readonly ?\Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface $sanitizer = null,
    ) {
    }

    /**
     * The built-in text (with %placeholders%) that is sent while no override is stored, so the editor never opens empty.
     *
     * @return array{subject:string,body:string}
     */
    public function defaults(string $code, string $locale): array
    {
        $t = fn (string $key, array $r = []): string => $this->translator->translate($key, $locale, $r);
        return match ($code) {
            'order.created' => ['subject' => $t('order_number', ['number' => '%order_number%']), 'body' => $t('order_received_plain')],
            'order.status_updated' => [
                'subject' => $t('order_update_subject', ['number' => '%order_number%']),
                'body' => implode(' ', [$t('plain_order_status', ['status' => '%status%']), $t('plain_payment_status', ['status' => '%payment_status%']), $t('plain_delivery_status', ['status' => '%fulfillment_status%']), $t('plain_tracking_number', ['number' => '%tracking_number%'])]),
            ],
            'return.status' => ['subject' => $t('return_status_subject', ['number' => '%order_number%', 'status' => '%status%']), 'body' => $t('return_status_text', ['number' => '%order_number%', 'status' => '%status%'])],
            'inquiry_received' => ['subject' => CanonicalUiText::get('customer.inquiry.received_subject'), 'body' => CanonicalUiText::get('customer.inquiry.received_text')],
            'newsletter.confirm' => ['subject' => CanonicalUiText::get('php.modules.marketing.http.newslettercontroller.pidtverdit_pidpysku'), 'body' => CanonicalUiText::get('php.modules.marketing.http.newslettercontroller.pidtverdit_email_shchob_otrymuvaty_novyny_ta_propozy')],
            default => ['subject' => '', 'body' => ''],
        };
    }

    /** Sample values for the live preview. @return array<string,string> */
    public function sampleVariables(string $storeName): array
    {
        return ['store_name' => $storeName, 'order_number' => 'A-10025', 'customer_name' => 'Iryna Kovalenko', 'total' => '1 249,00 UAH', 'status' => 'shipped', 'payment_status' => 'paid', 'fulfillment_status' => 'shipped', 'tracking_number' => '20450123456789'];
    }

    /** @return array<string,array{subject:string,body:string,enabled:bool}> code => stored override */
    public function forLocale(int $storeId, string $locale): array
    {
        $out = [];
        try {
            $rows = $this->db->fetchAllAssociative('SELECT template_code,subject,body,enabled,is_html FROM mc_notification_template WHERE store_id=? AND locale=?', [$storeId, $locale]);
        } catch (\Throwable) {
            return [];
        }
        foreach ($rows as $row) {
            $out[(string) $row['template_code']] = ['subject' => (string) $row['subject'], 'body' => (string) $row['body'], 'enabled' => (bool) $row['enabled'], 'is_html' => (bool) ($row['is_html'] ?? false)];
        }

        return $out;
    }

    public function save(int $storeId, string $code, string $locale, string $subject, string $body, bool $enabled, bool $html = false): void
    {
        if (!isset(self::CATALOG[$code])) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.tpl.error.code'));
        }
        $subject = trim(preg_replace('/[\r\n]+/', ' ', strip_tags($subject)) ?? '');
        $body = $html ? $this->cleanHtml($body) : trim(str_replace("\r\n", "\n", strip_tags($body)));
        if ($subject === '' || mb_strlen($subject, 'UTF-8') > 255) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.tpl.error.subject'));
        }
        if ($body === '' || mb_strlen($body, 'UTF-8') > ($html ? 30000 : 8000)) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.tpl.error.body'));
        }
        if (preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $locale) !== 1) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.tpl.error.locale'));
        }
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $this->db->executeStatement(
            'INSERT INTO mc_notification_template (store_id,template_code,locale,subject,body,enabled,is_html,updated_at) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE subject=VALUES(subject),body=VALUES(body),enabled=VALUES(enabled),is_html=VALUES(is_html),updated_at=VALUES(updated_at)',
            [$storeId, $code, $locale, $subject, $body, $enabled ? 1 : 0, $html ? 1 : 0, $now],
        );
    }

    public function reset(int $storeId, string $code, string $locale): void
    {
        $this->db->delete('mc_notification_template', ['store_id' => $storeId, 'template_code' => $code, 'locale' => $locale]);
    }

    /**
     * Rendered override for an outgoing e-mail, or null to keep the built-in text.
     *
     * @return array{subject:string,body:string,html:bool}|null
     */
    public function resolve(NotificationMessage $message): ?array
    {
        if (!isset(self::CATALOG[$message->type])) {
            return null;
        }
        try {
            $store = $this->primary();
            if ($store === null) {
                return null;
            }
            $locale = trim((string) ($message->context['locale'] ?? '')) ?: $store['locale'];
            $row = $this->db->fetchAssociative('SELECT subject,body,is_html FROM mc_notification_template WHERE store_id=? AND template_code=? AND locale=? AND enabled=1', [$store['id'], $message->type, $locale]);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($row)) {
            return null;
        }
        $vars = $this->variables($message, $store['name']);

        return ['subject' => $this->render((string) $row['subject'], $vars), 'body' => $this->render((string) $row['body'], $vars), 'html' => (bool) ($row['is_html'] ?? false)];
    }

    /** The sanitizer of the rich-text profile; without it every tag is removed. */
    public function cleanHtml(string $html): string
    {
        $html = trim(str_replace("\r\n", "\n", $html));

        return $this->sanitizer !== null ? trim($this->sanitizer->sanitize($html)) : trim(strip_tags($html));
    }

    /** @param array<string,string> $vars */
    public function render(string $template, array $vars): string
    {
        return preg_replace_callback('/%([a-z_]+)%/', static fn (array $m): string => $vars[$m[1]] ?? $m[0], $template) ?? $template;
    }

    /** @return array<string,string> */
    private function variables(NotificationMessage $message, string $storeName): array
    {
        $c = $message->context;
        $vars = ['store_name' => $storeName];
        foreach (['order_number', 'customer_name', 'status', 'payment_status', 'fulfillment_status', 'tracking_number'] as $key) {
            $vars[$key] = trim((string) ($c[$key] ?? ''));
        }
        $vars['total'] = isset($c['total_minor'], $c['currency']) ? number_format(((int) $c['total_minor']) / 100, 2, ',', ' ') . ' ' . (string) $c['currency'] : '';

        return $vars;
    }

    /** @return array{id:int,name:string,locale:string}|null */
    private function primary(): ?array
    {
        if ($this->primary === null) {
            $row = $this->db->fetchAssociative("SELECT id,name,default_locale FROM mc_store WHERE status='active' ORDER BY id LIMIT 1");
            if (!is_array($row)) {
                return null;
            }
            $this->primary = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'locale' => (string) $row['default_locale']];
        }

        return $this->primary;
    }
}
