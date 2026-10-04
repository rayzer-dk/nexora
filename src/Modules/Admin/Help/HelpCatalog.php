<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Help;

/** The documents an administrator can read inside the admin. Only files listed here are ever served. */
final class HelpCatalog
{
    /** @var array<string,array{file:string,group:string}> */
    private const DOCS = [
        'install' => ['file' => 'docs/INSTALLATION.md', 'group' => 'system'],
        'update-and-restore' => ['file' => 'docs/UPDATE_AND_RESTORE.md', 'group' => 'system'],
        'language-packs' => ['file' => 'docs/LANGUAGE_PACKS.md', 'group' => 'system'],
        'backup-feeds-sitemap' => ['file' => 'docs/BACKUP_FEEDS_SITEMAP.md', 'group' => 'system'],
        'security' => ['file' => 'docs/SECURITY.md', 'group' => 'system'],
        'modules-quickstart' => ['file' => 'docs/QUICKSTART_MODULE.md', 'group' => 'modules'],
        'modules-lifecycle' => ['file' => 'docs/EXTENSION_LIFECYCLE.md', 'group' => 'modules'],
        'developer-guide' => ['file' => 'docs/DEVELOPER_GUIDE_UK.md', 'group' => 'developers'],
        'sdk' => ['file' => 'docs/EXTENSION_SDK.md', 'group' => 'developers'],
        'slots' => ['file' => 'docs/EXTENSION_SLOTS.md', 'group' => 'developers'],
        'settings-schema' => ['file' => 'docs/EXTENSION_SETTINGS_SCHEMA.md', 'group' => 'developers'],
        'providers' => ['file' => 'docs/EXTENSION_PROVIDER_CONTRACTS.md', 'group' => 'developers'],
        'examples' => ['file' => 'docs/examples/extensions/README.md', 'group' => 'developers'],
    ];

    public function __construct(private readonly string $projectDir)
    {
    }

    /** @return list<array{slug:string,title:string,group:string}> documents that exist in this installation */
    public function all(): array
    {
        $list = [];
        foreach (self::DOCS as $slug => $doc) {
            $source = $this->read($slug);
            if ($source !== null) {
                $list[] = ['slug' => $slug, 'title' => $this->title($source, $slug), 'group' => $doc['group']];
            }
        }

        return $list;
    }

    /** @return array{slug:string,title:string,source:string}|null */
    public function find(string $slug): ?array
    {
        $source = $this->read($slug);

        return $source === null ? null : ['slug' => $slug, 'title' => $this->title($source, $slug), 'source' => $source];
    }

    /** Help URL for a link such as `UPDATE_AND_RESTORE.md` written inside a document. */
    public function urlForFile(string $basename): ?string
    {
        foreach (self::DOCS as $slug => $doc) {
            if (basename($doc['file']) === $basename) {
                return '/admin/help/' . $slug;
            }
        }

        return null;
    }

    private function read(string $slug): ?string
    {
        $file = self::DOCS[$slug]['file'] ?? null;
        if ($file === null) {
            return null;
        }
        $path = $this->projectDir . '/' . $file;
        $raw = is_file($path) ? @file_get_contents($path) : false;

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    private function title(string $source, string $slug): string
    {
        return preg_match('/^#\s+(.+)$/m', $source, $m) === 1 ? trim($m[1]) : $slug;
    }
}
