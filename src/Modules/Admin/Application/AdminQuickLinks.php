<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Application;

use Commerce\Core\Configuration\SystemSettingStore;

/** Icon buttons in the admin header that every administrator sets up for themselves: a page and an icon, a handful at most. */
final class AdminQuickLinks
{
    public const MAX = 6;

    public function __construct(private readonly SystemSettingStore $store)
    {
    }

    /** @return list<array{label:string,href:string,icon:string}> */
    public function forAdmin(int $adminId): array
    {
        $out = [];
        foreach ($this->store->getArray($this->key($adminId)) ?? [] as $item) {
            if (is_array($item) && self::validHref((string) ($item['href'] ?? '')) && trim((string) ($item['label'] ?? '')) !== '') {
                $out[] = ['label' => (string) $item['label'], 'href' => (string) $item['href'], 'icon' => (string) ($item['icon'] ?? '') ?: 'star'];
            }
        }

        return array_slice($out, 0, self::MAX);
    }

    public function add(int $adminId, string $label, string $href, string $icon): void
    {
        $label = mb_substr(trim(strip_tags($label)), 0, 60);
        $href = trim($href);
        if ($label === '' || !self::validHref($href)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.quicklinks.invalid'));
        }
        $items = array_values(array_filter($this->forAdmin($adminId), static fn (array $i): bool => $i['href'] !== $href));
        if (count($items) >= self::MAX) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.quicklinks.limit'));
        }
        $items[] = ['label' => $label, 'href' => $href, 'icon' => preg_match('/^[a-z0-9-]{1,40}$/D', $icon) === 1 ? $icon : 'star'];
        $this->store->setArray($this->key($adminId), $items);
    }

    public function remove(int $adminId, int $index): void
    {
        $items = $this->forAdmin($adminId);
        unset($items[$index]);
        $this->store->setArray($this->key($adminId), array_values($items));
    }

    /** Only admin pages of this installation: a path, no scheme, no protocol-relative address. */
    public static function validHref(string $href): bool
    {
        return preg_match('#^/admin(?:/[A-Za-z0-9._~%!$&\'()*+,;=:@/-]*)?(?:\?[A-Za-z0-9._~%!$&\'()*+,;=:@/?-]*)?$#D', $href) === 1 && !str_contains($href, '//');
    }

    private function key(int $adminId): string
    {
        return 'admin.quicklinks.' . $adminId;
    }
}
