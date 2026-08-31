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
            $rows[] = [
                $profile['id'],
                $profile['enabled'] ? 'yes' : 'no',
                $profile['name'],
                $profile['login_attribute'],
                $profile['secret_attribute'],
                $profile['host'],
                $profile['port'],
                $profile['path'],
                $profile['secure_transport'] ? 'yes' : 'no',
                $profile['auto_enable_calendars'] ? 'yes' : 'no',
                $profile['auto_enable_contacts'] ? 'yes' : 'no',
            ];
        }

        (new Table($output))
            ->setHeaders(['ID', 'Enabled', 'Name', 'Login attr', 'Secret attr', 'Host', 'Port', 'Path', 'HTTPS', 'Auto cal', 'Auto contacts'])
            ->setRows($rows)
            ->render();
        return self::SUCCESS;
    }
}
