<?php

declare(strict_types=1);

namespace Commerce\Modules\Security\Captcha;

use Commerce\Modules\Security\Http\CspExtra;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Single entry point for every storefront form: is a captcha required here, how is it rendered, is the answer valid. */
final class CaptchaService implements CaptchaVerifier
{
    private const VERIFY_URL = [
        'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'recaptcha_v2' => 'https://www.google.com/recaptcha/api/siteverify',
        'recaptcha_v3' => 'https://www.google.com/recaptcha/api/siteverify',
    ];

    private const CSP = [
        'turnstile' => ['script' => ['https://challenges.cloudflare.com'], 'frame' => ['https://challenges.cloudflare.com'], 'connect' => ['https://challenges.cloudflare.com']],
        'recaptcha_v2' => [
            'script' => ['https://www.google.com', 'https://www.gstatic.com', 'https://www.recaptcha.net'],
            'frame' => ['https://www.google.com', 'https://recaptcha.google.com', 'https://www.recaptcha.net'],
            'connect' => ['https://www.google.com'],
            'style' => ['https://www.gstatic.com'],
        ],
        'recaptcha_v3' => [
            'script' => ['https://www.google.com', 'https://www.gstatic.com', 'https://www.recaptcha.net'],
            'frame' => ['https://www.google.com', 'https://recaptcha.google.com', 'https://www.recaptcha.net'],
            'connect' => ['https://www.google.com'],
            'style' => ['https://www.gstatic.com'],
        ],
    ];

    /** @var array<int,array<string,mixed>> */
    private array $cache = [];

    public function __construct(
        private readonly CaptchaSettings $settings,
        private readonly BuiltinCaptcha $builtin,
        private readonly HttpClientInterface $http,
        private readonly StorefrontContextResolver $contexts,
    ) {
    }

    /** @return array<string,mixed> */
    private function config(Request $request): array
    {
        $storeId = $this->contexts->resolve($request)->storeId;

        return $this->cache[$storeId] ??= $this->settings->get($storeId);
    }

    public function required(Request $request, string $form): bool
    {
        $c = $this->config($request);

        return $c['provider'] !== 'none' && in_array($form, $c['forms'], true);
    }

    /** Template data for the form, or null when no captcha is needed. Registers the provider hosts in the page CSP. */
    /** @return array{provider:string,site_key:string,form:string,token:string,mode:string,question:string}|null */
    public function widget(Request $request, string $form): ?array
    {
        if (!$this->required($request, $form)) {
            return null;
        }
        $c = $this->config($request);
        if (isset(self::CSP[$c['provider']])) {
            CspExtra::merge($request, self::CSP[$c['provider']]);
        }
        $issue = $c['provider'] === 'builtin' ? $this->builtin->issue() : ['token' => '', 'mode' => '', 'question' => ''];

        return ['provider' => $c['provider'], 'site_key' => $c['site_key'], 'form' => $form, 'token' => $issue['token'], 'mode' => $issue['mode'], 'question' => $issue['question']];
    }

    /** True when the form does not need a captcha or the submitted answer is valid. */
    public function verify(Request $request, string $form): bool
    {
        if (!$this->required($request, $form)) {
            return true;
        }
        $c = $this->config($request);
        $post = $request->request;
        if ($c['provider'] === 'builtin') {
            return $this->builtin->verify((string) $post->get('mc_captcha_token', ''), (string) $post->get('mc_captcha_answer', ''));
        }
        $response = (string) $post->get($c['provider'] === 'turnstile' ? 'cf-turnstile-response' : 'g-recaptcha-response', '');
        if ($response === '' || strlen($response) > 4096 || $c['secret'] === '') {
            return false;
        }
        $body = ['secret' => $c['secret'], 'response' => $response];
        if (($ip = $request->getClientIp()) !== null) {
            $body['remoteip'] = $ip;
        }
        try {
            $reply = $this->http->request('POST', self::VERIFY_URL[$c['provider']], ['body' => $body, 'timeout' => 4.0]);
            $status = $reply->getStatusCode();
            if ($status >= 500) {
                return true; // provider outage must not stop orders; honeypot, timing and rate limit remain active
            }
            if ($status >= 300) {
                return false;
            }
            $data = $reply->toArray(false);
            if (($data['success'] ?? false) !== true) {
                return false;
            }
            if ($c['provider'] === 'recaptcha_v3') {
                return (float) ($data['score'] ?? 0) * 100 >= $c['score'] && (string) ($data['action'] ?? $form) === $form;
            }

            return true;
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface) {
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function builtin(): BuiltinCaptcha
    {
        return $this->builtin;
    }
}
