<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Infrastructure;

use Commerce\Core\I18n\CanonicalUiText;
use Doctrine\DBAL\Connection;

/**
 * Public contact details of a store (address, working hours, social links, map) and its self-pickup points.
 * Phone and e-mail are read from the store profile (Settings → Store data), so they are entered in one place only.
 */
final readonly class StorefrontContactSettings
{
    /** code => [allowed hosts, profile URL prefix used when only a handle is entered] */
    public const SOCIAL = [
        'instagram' => [['instagram.com', 'www.instagram.com'], 'https://instagram.com/'],
        'facebook' => [['facebook.com', 'www.facebook.com', 'fb.com', 'm.facebook.com'], 'https://facebook.com/'],
        'telegram' => [['t.me', 'telegram.me'], 'https://t.me/'],
        'viber' => [['viber.com', 'invite.viber.com'], ''],
        'whatsapp' => [['wa.me', 'whatsapp.com', 'api.whatsapp.com'], 'https://wa.me/'],
        'youtube' => [['youtube.com', 'www.youtube.com', 'youtu.be'], 'https://youtube.com/@'],
        'tiktok' => [['tiktok.com', 'www.tiktok.com'], 'https://tiktok.com/@'],
        'x' => [['x.com', 'twitter.com', 'www.twitter.com'], 'https://x.com/'],
        'linkedin' => [['linkedin.com', 'www.linkedin.com'], ''],
    ];

    public function __construct(private Connection $db)
    {
    }

    /** @return array{address:string,working_hours:string,phone_secondary:string,social:array<string,string>,map_lat:?float,map_lng:?float,map_zoom:int,map_input:string} */
    public function get(int $storeId): array
    {
        $defaults = ['address' => '', 'working_hours' => '', 'phone_secondary' => '', 'social' => [], 'map_lat' => null, 'map_lng' => null, 'map_zoom' => 16, 'map_input' => ''];
        try {
            $row = $this->db->fetchAssociative('SELECT address,working_hours,phone_secondary,social_links,map_lat,map_lng,map_zoom FROM mc_storefront_contact WHERE store_id=?', [$storeId]);
        } catch (\Throwable) {
            return $defaults; // schema can be older during a rolling upgrade
        }
        if (!is_array($row)) {
            return $defaults;
        }
        $social = json_decode((string) ($row['social_links'] ?? ''), true);
        $lat = $row['map_lat'] !== null ? (float) $row['map_lat'] : null;
        $lng = $row['map_lng'] !== null ? (float) $row['map_lng'] : null;

        return [
            'address' => (string) ($row['address'] ?? ''),
            'working_hours' => (string) ($row['working_hours'] ?? ''),
            'phone_secondary' => (string) ($row['phone_secondary'] ?? ''),
            'social' => is_array($social) ? array_filter(array_map('strval', $social)) : [],
            'map_lat' => $lat,
            'map_lng' => $lng,
            'map_zoom' => max(3, min(19, (int) ($row['map_zoom'] ?? 16))),
            'map_input' => $lat !== null && $lng !== null ? sprintf('%.6F, %.6F', $lat, $lng) : '',
        ];
    }

    /**
     * Storefront view: everything the contact page and footer need, already normalised and safe to print.
     *
     * @return array<string,mixed>
     */
    public function publicView(int $storeId): array
    {
        $s = $this->get($storeId);
        try {
            $profile = $this->db->fetchAssociative('SELECT email,phone,registration_address FROM mc_store_profile WHERE store_id=?', [$storeId]);
        } catch (\Throwable) {
            $profile = false;
        }
        $profile = is_array($profile) ? $profile : [];
        $address = trim($s['address']) !== '' ? trim($s['address']) : trim((string) ($profile['registration_address'] ?? ''));
        $phones = [];
        foreach ([(string) ($profile['phone'] ?? ''), $s['phone_secondary']] as $phone) {
            $digits = preg_replace('/[^0-9+]/', '', $phone) ?? '';
            if ($digits !== '' && !in_array($digits, array_column($phones, 'href'), true)) {
                $phones[] = ['label' => trim($phone), 'href' => $digits];
            }
        }
        $hours = array_values(array_filter(array_map('trim', preg_split('/\R/u', $s['working_hours']) ?: []), static fn (string $l): bool => $l !== ''));
        $social = [];
        foreach (self::SOCIAL as $code => $_) {
            if (($s['social'][$code] ?? '') !== '') {
                $social[] = ['code' => $code, 'url' => $s['social'][$code]];
            }
        }
        $map = null;
        if ($s['map_lat'] !== null && $s['map_lng'] !== null) {
            $lat = $s['map_lat'];
            $lng = $s['map_lng'];
            $span = 0.0035 * (2 ** (16 - $s['map_zoom']));
            $map = [
                'lat' => $lat,
                'lng' => $lng,
                'embed_url' => sprintf('https://www.openstreetmap.org/export/embed.html?bbox=%.6F%%2C%.6F%%2C%.6F%%2C%.6F&layer=mapnik&marker=%.6F%%2C%.6F', $lng - $span * 1.6, $lat - $span, $lng + $span * 1.6, $lat + $span, $lat, $lng),
                'link_url' => sprintf('https://www.openstreetmap.org/?mlat=%.6F&mlon=%.6F#map=%d/%.6F/%.6F', $lat, $lng, $s['map_zoom'], $lat, $lng),
            ];
        }
        $searchUrl = $address !== '' ? 'https://www.openstreetmap.org/search?query=' . rawurlencode(preg_replace('/\s+/u', ' ', $address) ?? $address) : '';

        return [
            'address' => $address,
            'email' => trim((string) ($profile['email'] ?? '')),
            'phones' => $phones,
            'hours' => $hours,
            'social' => $social,
            'map' => $map,
            'map_search_url' => $map === null ? $searchUrl : '',
            'has_any' => $address !== '' || $phones !== [] || $hours !== [] || $social !== [] || $map !== null || trim((string) ($profile['email'] ?? '')) !== '',
        ];
    }

    /**
     * schema.org Organization fields that come from the contact settings (sameAs, telephone, e-mail, address, geo, hours).
     * Only what the owner filled in is returned; the caller merges it into the Organization node.
     *
     * @return array<string,mixed>
     */
    public function organizationData(int $storeId): array
    {
        $v = $this->publicView($storeId);
        $out = [];
        if ($v['social'] !== []) {
            $out['sameAs'] = array_values(array_map(static fn (array $s): string => (string) $s['url'], $v['social']));
        }
        if ($v['phones'] !== []) {
            $out['telephone'] = (string) $v['phones'][0]['href'];
        }
        if ($v['email'] !== '') {
            $out['email'] = $v['email'];
        }
        if ($v['address'] !== '') {
            $out['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $v['address']];
        }
        if (is_array($v['map'])) {
            $out['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $v['map']['lat'], 'longitude' => $v['map']['lng']];
        }
        if ($v['hours'] !== []) {
            $out['openingHours'] = array_values($v['hours']);
        }

        return $out;
    }

    /** @param array<string,mixed> $in */
    public function save(int $storeId, array $in): void
    {
        $address = mb_substr(trim((string) ($in['address'] ?? '')), 0, 500);
        $hours = mb_substr(trim(str_replace("\r", '', (string) ($in['working_hours'] ?? ''))), 0, 1000);
        $phone = trim((string) ($in['phone_secondary'] ?? ''));
        if ($phone !== '' && preg_match('/^\+?[0-9 ()\-]{5,32}$/', $phone) !== 1) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.cw.error.phone'));
        }
        $social = [];
        foreach (self::SOCIAL as $code => [$hosts, $prefix]) {
            $url = self::socialUrl(trim((string) ($in['social_' . $code] ?? '')), $hosts, $prefix);
            if ($url === null) {
                throw new \InvalidArgumentException(CanonicalUiText::get('admin.storefront_contacts.error.social', ['network' => $code]));
            }
            if ($url !== '') {
                $social[$code] = $url;
            }
        }
        [$lat, $lng] = self::parseCoordinates((string) ($in['map_input'] ?? ''));
        if (trim((string) ($in['map_input'] ?? '')) !== '' && $lat === null) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.storefront_contacts.error.map'));
        }
        $row = [
            'address' => $address !== '' ? $address : null,
            'working_hours' => $hours !== '' ? $hours : null,
            'phone_secondary' => $phone !== '' ? $phone : null,
            'social_links' => $social !== [] ? json_encode($social, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : null,
            'map_lat' => $lat,
            'map_lng' => $lng,
            'map_zoom' => max(3, min(19, (int) ($in['map_zoom'] ?? 16))),
            'updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
        ];
        $exists = (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_storefront_contact WHERE store_id=?', [$storeId]) > 0;
        if ($exists) {
            $this->db->update('mc_storefront_contact', $row, ['store_id' => $storeId]);
        } else {
            $this->db->insert('mc_storefront_contact', ['store_id' => $storeId] + $row);
        }
    }

    /** @return array{0:?float,1:?float} "50.45, 30.52", an OpenStreetMap link (#map=z/lat/lng or mlat/mlon) or a Google Maps link (@lat,lng / !3d!4d) */
    public static function parseCoordinates(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return [null, null];
        }
        $patterns = [
            '/mlat=(-?\d{1,2}(?:\.\d+)?)&(?:amp;)?mlon=(-?\d{1,3}(?:\.\d+)?)/i',
            '/#map=\d{1,2}\/(-?\d{1,2}(?:\.\d+)?)\/(-?\d{1,3}(?:\.\d+)?)/i',
            '/@(-?\d{1,2}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)/',
            '/!3d(-?\d{1,2}(?:\.\d+)?)!4d(-?\d{1,3}(?:\.\d+)?)/',
            '/^\s*(-?\d{1,2}(?:\.\d+)?)\s*[,;\s]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input, $m) === 1) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];
                if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                    return [round($lat, 6), round($lng, 6)];
                }
            }
        }

        return [null, null];
    }

    /** @param list<string> $hosts @return string|null '' when empty, null when invalid */
    private static function socialUrl(string $value, array $hosts, string $prefix): ?string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $value) === 1) {
            $parts = parse_url($value);
            $host = strtolower((string) ($parts['host'] ?? ''));
            if (!is_array($parts) || !in_array($host, $hosts, true) || isset($parts['user']) || isset($parts['pass'])) {
                return null;
            }

            return mb_strlen($value) <= 500 ? 'https://' . substr($value, (int) strpos($value, '://') + 3) : null;
        }
        $handle = ltrim($value, '@');
        if ($prefix === '' || preg_match('/^[A-Za-z0-9._\-+]{2,64}$/', $handle) !== 1) {
            return null;
        }

        return $prefix . $handle;
    }
}
