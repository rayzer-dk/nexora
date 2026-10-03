<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Console;

use Commerce\Modules\Admin\Security\AdminPasswordResetService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'commerce:admin:reset-password', description: 'Set a new password for an administrator (works without outgoing mail).')]
final class ResetAdminPasswordCommand extends Command
{
    public function __construct(private readonly AdminPasswordResetService $resets)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Administrator email');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $password = (string) (getenv('NEXORA_ADMIN_NEW_PASSWORD') ?: '');
        if ($password === '') {
            $question = new Question('New password (min 12 characters): ');
            $question->setHidden(true);
            $helper = $this->getHelper('question');
            if (!$helper instanceof QuestionHelper) {
                throw new \LogicException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.console.no_question_helper'));
            }
            $password = (string) $helper->ask($input, $output, $question);
        }
        try {
            $ok = $this->resets->setPasswordForEmail((string) $input->getArgument('email'), $password);
        } catch (\DomainException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln($ok ? 'Password updated.' : '<error>Administrator not found.</error>');

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
