<?php

declare(strict_types=1);

namespace Commerce\Modules\Security\Network;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads what the shop can see about its own transport (TLS, HTTP version, HSTS, a proxy in front) and what the
 * mail domain publishes in DNS (SPF, DKIM, DMARC). Nothing is changed; each line is ok, warn or info.
 */
final class NetworkSecurityChecker
{
    private const DKIM_SELECTORS = ['default', 'google', 'mail', 'selector1', 'selector2', 'k1', 's1', 'dkim'];

    /** @return list<array{code:string,status:string,value:string}> */
    public function transport(Request $request): array
    {
        $secure = $request->isSecure();
        $tls = (string) $request->server->get('SSL_PROTOCOL', '');
        $protocol = strtoupper((string) $request->server->get('SERVER_PROTOCOL', ''));
        $proxy = $request->headers->has('CF-Ray') ? 'Cloudflare' : ($request->headers->has('X-Sucuri-ID') ? 'Sucuri' : ($request->headers->has('X-Amz-Cf-Id') ? 'CloudFront' : ''));

        $rows = [];
        $rows[] = ['code' => 'https', 'status' => $secure ? 'ok' : 'warn', 'value' => $secure ? 'HTTPS' : 'HTTP'];
        $rows[] = ['code' => 'tls', 'status' => $tls === '' ? 'info' : (in_array($tls, ['TLSv1.3', 'TLSv1.2'], true) ? ($tls === 'TLSv1.3' ? 'ok' : 'info') : 'warn'), 'value' => $tls !== '' ? $tls : '—'];
        $rows[] = ['code' => 'http', 'status' => str_contains($protocol, '/2') || str_contains($protocol, '/3') ? 'ok' : 'info', 'value' => $protocol !== '' ? $protocol : '—'];
        $rows[] = ['code' => 'hsts', 'status' => $secure ? 'ok' : 'warn', 'value' => $secure ? 'max-age=31536000; includeSubDomains' : '—'];
        $rows[] = ['code' => 'proxy', 'status' => $proxy !== '' ? 'ok' : 'info', 'value' => $proxy !== '' ? $proxy : '—'];

        return $rows;
    }

    /** @return list<array{code:string,status:string,value:string}> */
    public function mail(string $domain, string $selector = ''): array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) || !function_exists('dns_get_record')) {
            return [];
        }

        $spf = '';
        foreach ($this->txt($domain) as $record) {
            if (stripos($record, 'v=spf1') === 0) {
                $spf = $record;
            }
        }
        $dmarc = '';
        foreach ($this->txt('_dmarc.' . $domain) as $record) {
            if (stripos($record, 'v=DMARC1') === 0) {
                $dmarc = $record;
            }
        }
        $policy = preg_match('/\bp\s*=\s*(none|quarantine|reject)/i', $dmarc, $m) === 1 ? strtolower($m[1]) : '';

        $selector = preg_replace('/[^a-z0-9._-]/i', '', $selector) ?? '';
        $dkim = '';
        $found = '';
        foreach ($selector !== '' ? [$selector] : self::DKIM_SELECTORS as $candidate) {
            foreach ($this->txt($candidate . '._domainkey.' . $domain) as $record) {
                if (stripos($record, 'v=DKIM1') !== false || stripos($record, 'k=rsa') !== false || stripos($record, 'p=') !== false) {
                    $dkim = $record;
                    $found = $candidate;
                    break 2;
                }
            }
        }

        return [
            ['code' => 'spf', 'status' => $spf === '' ? 'warn' : (str_contains($spf, '-all') || str_contains($spf, '~all') ? 'ok' : 'info'), 'value' => $spf !== '' ? $spf : '—'],
            ['code' => 'dkim', 'status' => $dkim === '' ? 'warn' : 'ok', 'value' => $found !== '' ? $found . '._domainkey' : '—'],
            ['code' => 'dmarc', 'status' => $policy === '' ? 'warn' : ($policy === 'none' ? 'info' : 'ok'), 'value' => $dmarc !== '' ? $dmarc : '—'],
        ];
    }

    /** @return list<string> */
    private function txt(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);
        if (!is_array($records)) {
            return [];
        }
        $out = [];
        foreach ($records as $record) {
            if (isset($record['txt']) && is_string($record['txt'])) {
                $out[] = $record['txt'];
            }
        }

        return $out;
    }
}
