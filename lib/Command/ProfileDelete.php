<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\Command;

use OCA\DAVCLdapProvisioning\Config\AppConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class ProfileDelete extends Command {
    public function __construct(private readonly AppConfig $config) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('davc-ldap:profile:delete')
            ->setDescription('Delete a provisioning profile without deleting its existing DAV services')
            ->addArgument('id', InputArgument::REQUIRED, 'Profile ID')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Delete without an interactive confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $id = strtolower(trim((string)$input->getArgument('id')));
        if ($this->config->profile($id, true) === null) {
            $output->writeln('<error>Profile not found: ' . $id . '</error>');
            return self::FAILURE;
        }

        if (!(bool)$input->getOption('force')) {
            if (!$input->isInteractive()) {
                $output->writeln('<error>Use --force in non-interactive mode.</error>');
                return self::INVALID;
            }
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                sprintf('Delete profile %s? Existing DAV services will be left untouched. [y/N] ', $id),
                false,
            );
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('<comment>Cancelled.</comment>');
                return self::SUCCESS;
            }
        }

        $profiles = array_values(array_filter(
            $this->config->profiles(),
            static fn(array $profile): bool => $profile['id'] !== $id,
        ));
        $this->config->saveProfiles($profiles);
        $output->writeln('<info>Profile deleted. Existing DAV services were not changed.</info>');
        return self::SUCCESS;
    }
}
