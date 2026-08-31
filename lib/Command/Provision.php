<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\Command;

use OCA\DAVCLdapProvisioning\Service\Provisioner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Provision extends Command {
    public function __construct(private readonly Provisioner $provisioner) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('davc-ldap:provision')
            ->setDescription('Provision DAV Connector services from LDAP attributes')
            ->addArgument('user', InputArgument::OPTIONAL, 'Nextcloud user ID')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Provision all LDAP users')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'Provision only the selected profile ID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Resolve LDAP data and show the plan without modifying DAV Connector');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $uid = trim((string)$input->getArgument('user'));
        $all = (bool)$input->getOption('all');
        $dryRun = (bool)$input->getOption('dry-run');
        $profileId = trim((string)($input->getOption('profile') ?? ''));
        $profileId = $profileId === '' ? null : $profileId;

        if (($uid === '' && !$all) || ($uid !== '' && $all)) {
            $output->writeln('<error>Specify either USER or --all</error>');
            return self::INVALID;
        }

        try {
            if ($all) {
                $summary = $this->provisioner->provisionAll($dryRun, $profileId);
            } else {
                $user = $this->provisioner->userById($uid);
                if ($user === null) {
                    $output->writeln('<error>User not found: ' . $uid . '</error>');
                    return self::FAILURE;
                }
                $summary = $this->provisioner->provisionUser($user, $dryRun, $profileId);
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }

        foreach ($summary['results'] as $result) {
            $output->writeln($this->formatResult($result));
        }
        $output->writeln(sprintf(
            '<info>processed=%d ok=%d skipped=%d failed=%d</info>',
            $summary['processed'],
            $summary['ok'],
            $summary['skipped'],
            $summary['failed'],
        ));
        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string,mixed> $result */
    private function formatResult(array $result): string {
        $uid = (string)($result['uid'] ?? '?');
        $profile = (string)($result['profile'] ?? '?');
        $profileName = (string)($result['profile_name'] ?? $profile);
        $prefix = sprintf('%s [%s: %s]', $uid, $profile, $profileName);
        $status = (string)($result['status'] ?? '?');

        if ($status === 'ok') {
            return sprintf(
                '<info>%s: %s, sid=%s, calendars +%d/%d, contacts +%d/%d</info>',
                $prefix,
                (string)($result['action'] ?? 'ok'),
                (string)($result['sid'] ?? '?'),
                (int)($result['calendars']['enabled'] ?? 0),
                (int)($result['calendars']['total'] ?? 0),
                (int)($result['contacts']['enabled'] ?? 0),
                (int)($result['contacts']['total'] ?? 0),
            );
        }
        if ($status === 'dry-run') {
            return sprintf(
                '<comment>%s: dry-run action=%s sid=%s login=%s host=%s</comment>',
                $prefix,
                $result['action'] ?? '?',
                $result['sid'] ?? '-',
                $result['login'] ?? '?',
                $result['host'] ?? '?',
            );
        }
        if ($status === 'skipped') {
            return sprintf('<comment>%s: skipped (%s)</comment>', $prefix, $result['reason'] ?? 'unknown');
        }
        return sprintf('<error>%s: failed (%s)</error>', $prefix, $result['error'] ?? 'unknown');
    }
}
