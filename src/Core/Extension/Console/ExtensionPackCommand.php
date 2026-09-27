<?php

declare(strict_types=1);

namespace Commerce\Core\Extension\Console;

use Commerce\Core\Extension\ExtensionPackageValidator;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use ZipArchive;

#[AsCommand(name: 'commerce:extension:pack', description: 'Create and validate an extension ZIP from a module directory.')]
final class ExtensionPackCommand extends Command
{
    public function __construct(private readonly ExtensionPackageValidator $validator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('directory', InputArgument::REQUIRED, 'Extension source directory')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output ZIP path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = realpath((string) $input->getArgument('directory'));
        if ($source === false || !is_dir($source) || !is_file($source . '/manifest.json')) {
            $output->writeln('<error>Source must be an extension directory containing manifest.json.</error>');
            return Command::FAILURE;
        }
        if (!class_exists(ZipArchive::class)) {
            $output->writeln('<error>PHP zip extension is required.</error>');
            return Command::FAILURE;
        }
        $manifest = json_decode((string) file_get_contents($source . '/manifest.json'), true);
        $code = is_array($manifest) ? preg_replace('/[^a-z0-9_.-]+/i', '-', (string) ($manifest['code'] ?? 'extension')) : 'extension';
        $version = is_array($manifest) ? preg_replace('/[^0-9A-Za-z_.-]+/', '-', (string) ($manifest['version'] ?? '1.0.0')) : '1.0.0';
        $target = (string) ($input->getOption('output') ?: (getcwd() . '/' . $code . '-' . $version . '.zip'));
        if (!str_ends_with(strtolower($target), '.zip')) {
            $target .= '.zip';
        }
        @unlink($target);
        $zip = new ZipArchive();
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            $output->writeln('<error>Cannot create output ZIP.</error>');
            return Command::FAILURE;
        }
        try {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                if ($file->isLink()) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.sdk.symlink_forbidden'));
                }
                $path = $file->getRealPath();
                if ($path === false) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.sdk.file_resolve_failed'));
                }
                $relative = str_replace('\\', '/', substr($path, strlen($source) + 1));
                if (str_starts_with(basename($relative), '.') || preg_match('#(^|/)(vendor|node_modules|\.git)(/|$)#', $relative)) {
                    continue;
                }
                $zip->addFile($path, $relative);
            }
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($target);
            $output->writeln('<error>PACK FAILED: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $zip->close();

        try {
            $inspection = $this->validator->inspect($target);
        } catch (\Throwable $e) {
            @unlink($target);
            $output->writeln('<error>PACKED ZIP FAILED VALIDATION: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $output->writeln('<info>Package created and validated: ' . $target . '</info>');
        if ($inspection->quarantined) {
            $output->writeln('<comment>Note: trusted executable package is not activatable until its publisher signature is trusted.</comment>');
        }
        return Command::SUCCESS;
    }
}
