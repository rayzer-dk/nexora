<?php

declare(strict_types=1);

namespace Commerce\Modules\Api\Console;

use Commerce\Core\I18n\CanonicalUiText;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'commerce:api:token:revoke')]
final class ApiTokenRevokeCommand extends Command
{
    public function __construct(private readonly Connection $db) { parent::__construct(); }
    protected function configure(): void { $this->setDescription(CanonicalUiText::get('api.cli.revoke.description'))->addArgument('prefix', InputArgument::REQUIRED, CanonicalUiText::get('api.cli.revoke.arg_prefix')); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output); $prefix = trim((string) $input->getArgument('prefix'));
        $count = $this->db->executeStatement("UPDATE mc_api_token SET status='revoked',revoked_at=NOW(6) WHERE token_prefix=? AND status='active'", [$prefix]);
        if ($count !== 1) { $io->error(CanonicalUiText::get('api.cli.revoke.not_found')); return Command::FAILURE; }
        $io->success(CanonicalUiText::get('api.cli.revoke.done')); return Command::SUCCESS;
    }
}
