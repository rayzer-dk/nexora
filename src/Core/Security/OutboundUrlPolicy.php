<?php

declare(strict_types=1);

namespace Commerce\Core\Security;

use RuntimeException;

final class OutboundUrlPolicy
{
    /**
     * Validate administrator/configuration supplied outbound HTTP(S) endpoints.
     * DNS is resolved at validation time and every answer must be globally routable.
     * Callers should still disable redirects in their HTTP client to avoid redirect-based SSRF.
     */
    public function assertPublicHttps(string $url, bool $allowHttp = false): void
    {
        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.20aa377a3619'));
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.20aa377a3619'));
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https' && !($allowHttp && $scheme === 'http')) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9fcaf7464f09'));
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.e41242e3fcb2'));
        }
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d15edc7083a1'));
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (!$this->isPublicIp($host)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.02d780ba83fe'));
            }
            return;
        }

        // Purely syntactic validation remains deterministic when DNS is unavailable.
        if (preg_match('/^[a-z0-9.-]+$/D', $host) !== 1 || !str_contains($host, '.')) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.7911228d8491'));
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records) || $records === []) {
            return;
        }
        foreach ($records as $record) {
            $ip = isset($record['ip']) ? (string) $record['ip'] : (isset($record['ipv6']) ? (string) $record['ipv6'] : '');
            if ($ip !== '' && !$this->isPublicIp($ip)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.02d780ba83fe'));
            }
        }
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
