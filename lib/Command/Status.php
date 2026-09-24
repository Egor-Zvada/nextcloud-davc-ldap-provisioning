<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\Command;

use OCA\DAVCLdapProvisioning\Config\AppConfig;
use OCA\DAVCLdapProvisioning\Service\DavcAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Status extends Command {
    public function __construct(
        private readonly AppConfig $config,
        private readonly DavcAdapter $davc,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('davc-ldap:status')
            ->setDescription('Show DAVC LDAP provisioning profiles and compatibility');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $rows = [];
        foreach ($this->config->profiles() as $profile) {
            $target = $profile['target_all']
                ? 'all'
                : sprintf('%d users, %d groups', count($profile['target_users']), count($profile['target_groups']));
            $source = $profile['credential_source'] === 'static'
                ? 'manual:' . $profile['static_login']
                : 'ldap:' . $profile['login_attribute'] . '/' . $profile['secret_attribute'];
            $rows[] = [
                $profile['id'],
                $profile['enabled'] ? 'yes' : 'no',
                $profile['name'],
                $source,
                $target,
                sprintf(
                    '%s://%s:%d%s',
                    $profile['secure_transport'] ? 'https' : 'http',
                    $profile['host'],
                    $profile['port'],
                    $profile['path'],
                ),
                $profile['background_enabled'] ? $profile['background_interval'] . 's' : 'off',
            ];
        }
        (new Table($output))
            ->setHeaders(['ID', 'Enabled', 'Name', 'Credentials', 'Targets', 'DAV endpoint', 'Schedule'])
            ->setRows($rows)
            ->render();

        try {
            $version = $this->davc->assertCompatible();
            $output->writeln('<info>DAV Connector: ' . $version . ' (supported)</info>');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>DAV Connector: ' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }
    }
}
