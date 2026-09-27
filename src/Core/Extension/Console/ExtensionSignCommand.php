<?php

declare(strict_types=1);

namespace Commerce\Core\Extension\Console;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ZipArchive;

#[AsCommand(name: 'commerce:extension:sign', description: 'Sign a trusted Nexora extension package with an Ed25519 publisher key.')]
final class ExtensionSignCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('archive', InputArgument::REQUIRED, 'Path to extension ZIP')
            ->addArgument('secret-key', InputArgument::REQUIRED, 'Path to base64 Ed25519 secret key file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $archive = (string) $input->getArgument('archive');
        $keyFile = (string) $input->getArgument('secret-key');
        if (!is_file($archive) || !is_file($keyFile)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.sign_input_missing'));
        }
        $key = base64_decode(trim((string) file_get_contents($keyFile)), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.sign_key_invalid'));
        }
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.sign_archive_open_failed'));
        }
        try {
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) continue;
                $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
                if ($name === '' || str_ends_with($name, '/') || $name === 'SIGNATURE.ed25519') continue;
                $contents = $zip->getFromIndex($i);
                if (!is_string($contents)) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.sign_entry_read_failed') . $name);
                $entries[$name] = hash('sha256', $contents);
            }
            ksort($entries, SORT_STRING);
            $payload = '';
            foreach ($entries as $name => $hash) $payload .= $name . "\0" . $hash . "\n";
            $digest = hash('sha256', $payload, true);
            $signature = base64_encode(sodium_crypto_sign_detached($digest, $key)) . "\n";
            if (!$zip->addFromString('SIGNATURE.ed25519', $signature)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.sign_write_failed'));
            }
        } finally {
            $zip->close();
        }
        $output->writeln('Signed: ' . $archive);
        return Command::SUCCESS;
    }
}
