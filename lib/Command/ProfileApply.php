<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\Command;

use OCA\DAVCLdapProvisioning\Config\AppConfig;
use OCA\DAVCLdapProvisioning\Service\Provisioner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProfileApply extends Command {
    public function __construct(
        private readonly AppConfig $config,
        private readonly Provisioner $provisioner,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('davc-ldap:profile:apply')
            ->setDescription('Apply one profile now: connect when enabled, disconnect when disabled')
            ->addArgument('id', InputArgument::REQUIRED, 'Profile ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $profileId = strtolower(trim((string)$input->getArgument('id')));
        $profile = $this->config->profile($profileId, true);
        if ($profile === null) {
            $output->writeln('<error>Profile not found: ' . $profileId . '</error>');
            return self::INVALID;
        }

        try {
            $summary = $this->provisioner->reconcileProfile($profile);
            $this->config->markProfileBackgroundRun($profileId);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }

        foreach ($summary['results'] as $result) {
            $uid = (string)($result['uid'] ?? '?');
            $status = (string)($result['status'] ?? '?');
            $detail = $status === 'failed'
                ? (string)($result['error'] ?? 'unknown error')
                : (string)($result['action'] ?? $status);
            $output->writeln(sprintf('%s: %s (%s)', $uid, $status, $detail));
        }
        $output->writeln(sprintf(
            '<info>mode=%s processed=%d ok=%d skipped=%d failed=%d</info>',
            $profile['enabled'] ? 'connect' : 'disconnect',
            $summary['processed'],
            $summary['ok'],
            $summary['skipped'],
            $summary['failed'],
        ));

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
