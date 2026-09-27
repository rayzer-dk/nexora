<?php

declare(strict_types=1);

namespace Commerce\Modules\Api\Console;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Api\Application\ApiAccessService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'commerce:api:token:issue')]
final class ApiTokenIssueCommand extends Command
{
    public function __construct(private readonly Connection $db) { parent::__construct(); }

    protected function configure(): void
    {
        $this->setDescription(CanonicalUiText::get('api.cli.issue.description'))
            ->addArgument('name', InputArgument::REQUIRED, CanonicalUiText::get('api.cli.issue.arg_name'))
            ->addOption('store', null, InputOption::VALUE_REQUIRED, CanonicalUiText::get('api.cli.issue.opt_store'))
            ->addOption('scope', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, CanonicalUiText::get('api.cli.issue.opt_scope'))
            ->addOption('rate-limit', null, InputOption::VALUE_REQUIRED, CanonicalUiText::get('api.cli.issue.opt_rate_limit'), '120')
            ->addOption('expires', null, InputOption::VALUE_REQUIRED, CanonicalUiText::get('api.cli.issue.opt_expires'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $scopes = array_values(array_unique(array_map('strval', (array) $input->getOption('scope'))));
        if ($scopes === []) { $scopes = ['catalog:read']; }
        foreach ($scopes as $scope) {
            if ($scope !== '*' && !in_array($scope, ApiAccessService::SCOPES, true)) { $io->error(CanonicalUiText::get('api.cli.issue.unknown_scope',['scope'=>$scope])); return Command::INVALID; }
        }
        $storeId = $input->getOption('store') !== null ? (int) $input->getOption('store') : null;
        if ($storeId !== null && $storeId < 1) { $io->error(CanonicalUiText::get('api.cli.issue.invalid_store')); return Command::INVALID; }
        $limit = max(1, min(5000, (int) $input->getOption('rate-limit')));
        $expires = null;
        if (is_string($input->getOption('expires')) && trim((string) $input->getOption('expires')) !== '') {
            $timestamp = strtotime((string) $input->getOption('expires'));
            if ($timestamp === false || $timestamp <= time()) { $io->error(CanonicalUiText::get('api.cli.issue.invalid_expiry')); return Command::INVALID; }
            $expires = date('Y-m-d H:i:s', $timestamp);
        }
        if ($storeId !== null && (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_store WHERE id=?', [$storeId]) !== 1) { $io->error(CanonicalUiText::get('api.cli.issue.store_not_found')); return Command::INVALID; }
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->db->insert('mc_api_token', [
            'public_id' => Uuid::v7()->toBinary(), 'store_id' => $storeId, 'name' => mb_substr((string) $input->getArgument('name'), 0, 190),
            'token_prefix' => substr($raw, 0, 12), 'token_hash' => hash('sha256', $raw, true), 'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'status' => 'active', 'rate_limit_per_minute' => $limit, 'expires_at' => $expires, 'created_at' => date('Y-m-d H:i:s.u'),
        ]);
        $io->success(CanonicalUiText::get('api.cli.issue.created'));
        $io->writeln($raw);
        return Command::SUCCESS;
    }
}
