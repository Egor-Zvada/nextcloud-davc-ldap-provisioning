<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\Command;

use OCA\DAVCLdapProvisioning\Config\AppConfig;
use OCA\DAVCLdapProvisioning\Config\ProfileValidator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProfileSet extends Command {
    public function __construct(private readonly AppConfig $config) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('davc-ldap:profile:set')
            ->setDescription('Create or update an LDAP DAV provisioning profile')
            ->addArgument('id', InputArgument::REQUIRED, 'Stable profile ID')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Profile and DAV service name')
            ->addOption('login-attribute', null, InputOption::VALUE_REQUIRED, 'LDAP login attribute')
            ->addOption('secret-attribute', null, InputOption::VALUE_REQUIRED, 'LDAP secret attribute')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'DAV host')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'DAV port')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'DAV path')
            ->addOption('https', null, InputOption::VALUE_REQUIRED, 'Use HTTPS: 1 or 0')
            ->addOption('enabled', null, InputOption::VALUE_REQUIRED, 'Enable profile: 1 or 0')
            ->addOption('auto-calendars', null, InputOption::VALUE_REQUIRED, 'Automatically enable all calendars: 1 or 0')
            ->addOption('auto-contacts', null, InputOption::VALUE_REQUIRED, 'Automatically enable all address books: 1 or 0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $id = strtolower(trim((string)$input->getArgument('id')));
        $profiles = $this->config->profiles();
        $profile = $this->config->profile($id, true);
        $isNew = $profile === null;

        if ($isNew) {
            $profile = [
                'id' => $id,
                'name' => '',
                'enabled' => true,
                'login_attribute' => '',
                'secret_attribute' => '',
                'host' => '',
                'port' => 443,
                'path' => '/',
                'secure_transport' => true,
                'auto_enable_calendars' => false,
                'auto_enable_contacts' => false,
            ];
        }

        $stringOptions = [
            'name' => 'name',
            'login-attribute' => 'login_attribute',
            'secret-attribute' => 'secret_attribute',
            'host' => 'host',
            'path' => 'path',
        ];
        foreach ($stringOptions as $option => $key) {
            if ($input->getOption($option) !== null) {
                $profile[$key] = (string)$input->getOption($option);
            }
        }
        if ($input->getOption('port') !== null) {
            $profile['port'] = (int)$input->getOption('port');
        }

        $boolOptions = [
            'https' => 'secure_transport',
            'enabled' => 'enabled',
            'auto-calendars' => 'auto_enable_calendars',
            'auto-contacts' => 'auto_enable_contacts',
        ];
        foreach ($boolOptions as $option => $key) {
            if ($input->getOption($option) !== null) {
                try {
                    $profile[$key] = $this->parseBool((string)$input->getOption($option));
                } catch (\InvalidArgumentException $e) {
                    $output->writeln('<error>' . $e->getMessage() . '</error>');
                    return self::INVALID;
                }
            }
        }

        try {
            $profile = ProfileValidator::normalize($profile);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            if ($isNew) {
                $output->writeln('<comment>A new profile requires --name, --login-attribute, --secret-attribute and --host.</comment>');
            }
            return self::INVALID;
        }

        $saved = false;
        foreach ($profiles as $index => $existing) {
            if ($existing['id'] === $id) {
                $profiles[$index] = $profile;
                $saved = true;
                break;
            }
        }
        if (!$saved) {
            $profiles[] = $profile;
        }

        $this->config->saveProfiles($profiles);
        $output->writeln(sprintf('<info>Profile %s %s.</info>', $id, $isNew ? 'created' : 'updated'));
        return self::SUCCESS;
    }

    private function parseBool(string $value): bool {
        $value = strtolower(trim($value));
        if (!in_array($value, ['0', '1', 'false', 'true', 'no', 'yes', 'off', 'on'], true)) {
            throw new \InvalidArgumentException('Boolean options accept only 1/0, true/false, yes/no or on/off');
        }
        return ProfileValidator::toBool($value);
    }
}
