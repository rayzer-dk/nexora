<?php

declare(strict_types=1);

namespace Commerce\Modules\Security\Captcha;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Core\Security\SecretVault;
use Doctrine\DBAL\Connection;

/**
 * Per-store captcha configuration: provider, keys (secret encrypted at rest) and the list of forms that require it.
 * Without a stored row the legacy TURNSTILE_* environment variables keep working (register + withdrawal).
 */
final readonly class CaptchaSettings
{
    public const PROVIDERS = ['builtin', 'recaptcha_v2', 'recaptcha_v3', 'turnstile'];

    /** Forms that can be protected; keys are used in templates, controllers and the admin UI. */
    public const FORMS = ['register', 'password_recovery', 'contact', 'callback', 'price_request', 'quick_order', 'newsletter', 'stock_notify', 'review', 'question', 'forum', 'withdrawal', 'login'];

    private const CONTEXT = 'captcha.secret';

    public function __construct(
        private Connection $db,
        private SecretVault $vault,
        private bool $envTurnstileEnabled,
        private string $envTurnstileSiteKey,
        private string $envTurnstileSecret,
    ) {
    }

    /** @return array{provider:string,site_key:string,secret:string,score:int,forms:list<string>,has_secret:bool,source:string,fail_mode:string} */
    public function get(int $storeId): array
    {
        try {
            $row = $this->db->fetchAssociative('SELECT provider,site_key,secret_enc,score_threshold,forms,fail_mode FROM mc_captcha_settings WHERE store_id=?', [$storeId]);
        } catch (\Throwable) {
            $row = false;
        }
        if (is_array($row)) {
            $secret = '';
            if ((string) ($row['secret_enc'] ?? '') !== '') {
                try {
                    $secret = $this->vault->decrypt((string) $row['secret_enc'], self::CONTEXT);
                } catch (\Throwable) {
                    $secret = '';
                }
            }
            $forms = json_decode((string) $row['forms'], true);
            $provider = in_array((string) $row['provider'], self::PROVIDERS, true) ? (string) $row['provider'] : 'none';

            return [
                'provider' => $provider,
                'site_key' => (string) $row['site_key'],
                'secret' => $secret,
                'score' => max(1, min(99, (int) $row['score_threshold'])),
                'forms' => is_array($forms) ? array_values(array_intersect(self::FORMS, array_map('strval', $forms))) : [],
                'has_secret' => $secret !== '',
                'source' => 'admin',
                'fail_mode' => (string) ($row['fail_mode'] ?? 'open') === 'closed' ? 'closed' : 'open',
            ];
        }
        if ($this->envTurnstileEnabled && $this->envTurnstileSecret !== '' && $this->envTurnstileSiteKey !== '') {
            return ['provider' => 'turnstile', 'site_key' => $this->envTurnstileSiteKey, 'secret' => $this->envTurnstileSecret, 'score' => 50, 'forms' => ['register', 'withdrawal'], 'has_secret' => true, 'source' => 'env', 'fail_mode' => 'open'];
        }

        return ['provider' => 'none', 'site_key' => '', 'secret' => '', 'score' => 50, 'forms' => [], 'has_secret' => false, 'source' => 'none', 'fail_mode' => 'open'];
    }

    /** @param array<string,mixed> $input */
    public function save(int $storeId, array $input): void
    {
        $current = $this->get($storeId);
        $provider = (string) ($input['provider'] ?? 'none');
        if ($provider !== 'none' && !in_array($provider, self::PROVIDERS, true)) {
            $provider = 'none';
        }
        $siteKey = trim((string) ($input['site_key'] ?? ''));
        $secret = trim((string) ($input['secret'] ?? ''));
        $forms = array_values(array_intersect(self::FORMS, array_map('strval', (array) ($input['forms'] ?? []))));

        $external = in_array($provider, ['recaptcha_v2', 'recaptcha_v3', 'turnstile'], true);
        if ($external) {
            if (preg_match('/^[A-Za-z0-9_-]{16,255}$/', $siteKey) !== 1) {
                throw new \InvalidArgumentException(CanonicalUiText::get('admin.captcha.error.site_key'));
            }
            if ($secret === '' && !$current['has_secret']) {
                throw new \InvalidArgumentException(CanonicalUiText::get('admin.captcha.error.secret'));
            }
            if ($secret !== '' && preg_match('/^[A-Za-z0-9_\-:.]{16,255}$/', $secret) !== 1) {
                throw new \InvalidArgumentException(CanonicalUiText::get('admin.captcha.error.secret'));
            }
        } else {
            $siteKey = '';
        }
        $score = max(1, min(99, (int) ($input['score'] ?? 50)));
        $failMode = (string) ($input['fail_mode'] ?? 'open') === 'closed' ? 'closed' : 'open';
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $secretEnc = null;
        if ($external) {
            $plain = $secret !== '' ? $secret : $current['secret'];
            $secretEnc = $this->vault->encrypt($plain, self::CONTEXT);
        }
        $this->db->executeStatement(
            'INSERT INTO mc_captcha_settings (store_id,provider,site_key,secret_enc,score_threshold,forms,fail_mode,updated_at) VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE provider=VALUES(provider),site_key=VALUES(site_key),secret_enc=VALUES(secret_enc),score_threshold=VALUES(score_threshold),forms=VALUES(forms),fail_mode=VALUES(fail_mode),updated_at=VALUES(updated_at)',
            [$storeId, $provider, $siteKey, $secretEnc, $score, json_encode($forms, JSON_THROW_ON_ERROR), $failMode, $now],
        );
    }
}
