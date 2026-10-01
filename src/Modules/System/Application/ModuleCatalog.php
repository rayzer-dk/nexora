<?php

declare(strict_types=1);

namespace Commerce\Modules\System\Application;

/** What is installed: the built-in modules (folders of src/Modules) and the languages shipped with the platform. */
final class ModuleCatalog
{
    public function __construct(private readonly string $projectDir)
    {
    }

    /** @return list<array{name:string,label:string,classes:int,routes:int,has_admin:bool}> */
    public function builtIn(): array
    {
        $rows = [];
        foreach (glob($this->projectDir . '/src/Modules/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $classes = 0;
            $routes = 0;
            $admin = false;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $classes++;
                if (str_contains($file->getPathname(), '/Http/')) {
                    $source = (string) file_get_contents($file->getPathname());
                    $count = substr_count($source, '#[Route(');
                    $routes += $count;
                    $admin = $admin || str_contains($source, "'/admin");
                }
            }
            $name = basename($dir);
            $rows[] = [
                'name' => $name,
                'label' => trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $name)),
                'classes' => $classes,
                'routes' => $routes,
                'has_admin' => $admin,
            ];
        }

        return $rows;
    }

    /** @return list<array{code:string,storefront:bool,admin:bool}> */
    public function languages(): array
    {
        $rows = [];
        foreach (glob($this->projectDir . '/resources/translations/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $rows[] = [
                'code' => basename($dir),
                'storefront' => is_file($dir . '/storefront.php'),
                'admin' => is_file($dir . '/admin.php'),
            ];
        }

        return $rows;
    }
}
