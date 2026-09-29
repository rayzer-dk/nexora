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
            throw new RuntimeException('Key id must be 3-64 characters: lowercase letters, digits, dot, dash or underscore.');
        }
        $out = (string) ($input->getOption('out') ?: $keyId . '.secret.key');
        if (file_exists($out)) {
            throw new RuntimeException('Refusing to overwrite an existing key file: ' . $out);
        }
        $pair = sodium_crypto_sign_keypair();
        $secret = base64_encode(sodium_crypto_sign_secretkey($pair));
        $public = base64_encode(sodium_crypto_sign_publickey($pair));
        $old = umask(0177);
        try {
            if (file_put_contents($out, $secret . "\n") === false) {
                throw new RuntimeException('Cannot write the secret key file: ' . $out);
            }
        } finally {
            umask($old);
        }

        $output->writeln('Secret key written to <info>' . $out . '</info> (mode 0600). Keep it out of version control and never upload it to a store.');
        $output->writeln('');
        $output->writeln('1. Put this in the module manifest.json:');
        $output->writeln(sprintf('   "publisher": {"name": "Your Company", "key_id": "%1$s"}, "signature": {"algorithm": "ed25519", "key_id": "%1$s", "file": "SIGNATURE.ed25519"}', $keyId));
        $output->writeln('2. Register the public key on every store that should run your modules, in config/extensions/trusted-publishers.json:');
        $output->writeln(sprintf('   "%s": {"name": "Your Company", "public_key": "%s"}', $keyId, $public));
        $output->writeln('3. Pack, then sign: commerce:extension:pack <dir> --output=module.zip && commerce:extension:sign module.zip ' . $out);

        return Command::SUCCESS;
    }
}
