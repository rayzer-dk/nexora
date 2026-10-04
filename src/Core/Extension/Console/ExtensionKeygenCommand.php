<?php

declare(strict_types=1);

namespace Commerce\Core\Extension\Console;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:extension:keygen', description: 'Generate an Ed25519 publisher key pair for signing trusted Nexora extensions.')]
final class ExtensionKeygenCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('key-id', InputArgument::REQUIRED, 'Publisher key id, e.g. acme.2026')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Where to write the secret key file', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $keyId = (string) $input->getArgument('key-id');
        if (preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/D', $keyId) !== 1) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.keygen_id_invalid'));
        }
        $out = (string) ($input->getOption('out') ?: $keyId . '.secret.key');
        if (file_exists($out)) {
            throw new RuntimeException(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.keygen_exists'), $out));
        }
        $pair = sodium_crypto_sign_keypair();
        $secret = base64_encode(sodium_crypto_sign_secretkey($pair));
        $public = base64_encode(sodium_crypto_sign_publickey($pair));
        $old = umask(0177);
        try {
            if (file_put_contents($out, $secret . "\n") === false) {
                throw new RuntimeException(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.keygen_write_failed'), $out));
            }
        } finally {
            umask($old);
        }

        $output->writeln('Secret key written to <info>' . $out . '</info> (mode 0600). Keep it out of version control and never upload it to a store.');
        $output->writeln('');
        $output->writeln('1. Put this in the module manifest.json:');
        $output->writeln(sprintf('   "publisher": {"name": "Your Company", "key_id": "%1$s"}, "signature": {"algorithm": "ed25519", "key_id": "%1$s", "file": "SIGNATURE.ed25519"}', $keyId));
        $output->writeln('2. Register the public key on every store that should run your modules, in var/config/trusted-publishers.json (create the file; updates replace config/extensions/, not var/):');
        $output->writeln(sprintf('   {"schema_version": 2, "publishers": {"%s": {"name": "Your Company", "public_key": "%s"}}}', $keyId, $public));
        $output->writeln('3. Pack, then sign: commerce:extension:pack <dir> --output=module.zip && commerce:extension:sign module.zip ' . $out);

        return Command::SUCCESS;
    }
}
