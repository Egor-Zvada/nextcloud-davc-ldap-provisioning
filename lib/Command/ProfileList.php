<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\Command;

use OCA\DAVCLdapProvisioning\Config\AppConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProfileList extends Command {
    public function __construct(private readonly AppConfig $config) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('davc-ldap:profile:list')
            ->setDescription('List LDAP DAV provisioning profiles');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $rows = [];
        foreach ($this->config->profiles() as $profile) {
            $credentials = $profile['credential_source'] === 'static'
                ? 'manual:' . $profile['static_login']
                : 'ldap:' . $profile['login_attribute'] . '/' . $profile['secret_attribute'];
            $targets = $profile['target_all']
                ? 'all'
                : sprintf('%d users, %d groups', count($profile['target_users']), count($profile['target_groups']));
            $rows[] = [
                $profile['id'],
                $profile['enabled'] ? 'yes' : 'no',
                $profile['name'],
                $credentials,
                $targets,
                $profile['host'],
                $profile['port'],
                $profile['background_enabled'] ? $profile['background_interval'] . 's' : 'off',
            ];
        }

        (new Table($output))
            ->setHeaders(['ID', 'Enabled', 'Name', 'Credentials', 'Targets', 'Host', 'Port', 'Schedule'])
            ->setRows($rows)
            ->render();
        return self::SUCCESS;
    }
}
