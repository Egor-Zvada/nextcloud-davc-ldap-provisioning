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
            ->setDescription('Create or update a DAV provisioning profile')
            ->addArgument('id', InputArgument::REQUIRED, 'Stable profile ID')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Profile and DAV service name')
            ->addOption('credential-source', null, InputOption::VALUE_REQUIRED, 'Credential source: ldap or static')
            ->addOption('login-attribute', null, InputOption::VALUE_REQUIRED, 'LDAP login attribute')
            ->addOption('secret-attribute', null, InputOption::VALUE_REQUIRED, 'LDAP secret attribute')
            ->addOption('static-login', null, InputOption::VALUE_REQUIRED, 'Manual DAV account login')
            ->addOption('static-secret', null, InputOption::VALUE_REQUIRED, 'Manual DAV account password (prefer the admin UI to avoid shell history)')
            ->addOption('all-users', null, InputOption::VALUE_REQUIRED, 'Target all Nextcloud users: 1 or 0')
            ->addOption('user', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Target user ID; repeat for multiple users')
            ->addOption('group', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Target group ID; repeat for multiple groups')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'DAV host')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'DAV port')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'DAV path')
            ->addOption('https', null, InputOption::VALUE_REQUIRED, 'Use HTTPS: 1 or 0')
            ->addOption('use-calendar-color', null, InputOption::VALUE_REQUIRED, 'Apply one local color to all calendars: 1 or 0')
            ->addOption('calendar-color', null, InputOption::VALUE_REQUIRED, 'Local calendar color in #RRGGBB format')
            ->addOption('enabled', null, InputOption::VALUE_REQUIRED, 'Enable profile: 1 or 0')
            ->addOption('auto-calendars', null, InputOption::VALUE_REQUIRED, 'Automatically enable all calendars: 1 or 0')
            ->addOption('auto-contacts', null, InputOption::VALUE_REQUIRED, 'Automatically enable all address books: 1 or 0')
            ->addOption('background', null, InputOption::VALUE_REQUIRED, 'Enable this profile schedule: 1 or 0')
            ->addOption('background-interval', null, InputOption::VALUE_REQUIRED, 'Profile schedule interval in seconds');
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
                'credential_source' => ProfileValidator::SOURCE_LDAP,
                'login_attribute' => '',
                'secret_attribute' => '',
                'static_login' => '',
                'target_all' => false,
                'target_users' => [],
                'target_groups' => [],
                'host' => '',
                'port' => 443,
                'path' => '/',
                'secure_transport' => true,
                'calendar_color_enabled' => false,
                'calendar_color' => AppConfig::DEFAULT_CALENDAR_COLOR,
                'auto_enable_calendars' => false,
                'auto_enable_contacts' => false,
                'background_enabled' => false,
                'background_interval' => AppConfig::DEFAULT_INTERVAL,
            ];
        }

        $stringOptions = [
            'name' => 'name',
            'credential-source' => 'credential_source',
            'login-attribute' => 'login_attribute',
            'secret-attribute' => 'secret_attribute',
            'static-login' => 'static_login',
            'host' => 'host',
            'path' => 'path',
            'calendar-color' => 'calendar_color',
        ];
        foreach ($stringOptions as $option => $key) {
            if ($input->getOption($option) !== null) {
                $profile[$key] = (string)$input->getOption($option);
            }
        }
        if ($input->getOption('port') !== null) {
            $profile['port'] = (int)$input->getOption('port');
        }
        if ($input->getOption('background-interval') !== null) {
            $profile['background_interval'] = (int)$input->getOption('background-interval');
        }
        if ($input->hasParameterOption('--user')) {
            $profile['target_users'] = array_values(array_map('strval', $input->getOption('user')));
        }
        if ($input->hasParameterOption('--group')) {
            $profile['target_groups'] = array_values(array_map('strval', $input->getOption('group')));
        }

        $boolOptions = [
            'https' => 'secure_transport',
            'enabled' => 'enabled',
            'use-calendar-color' => 'calendar_color_enabled',
            'auto-calendars' => 'auto_enable_calendars',
            'auto-contacts' => 'auto_enable_contacts',
            'all-users' => 'target_all',
            'background' => 'background_enabled',
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
            $staticSecret = $input->getOption('static-secret');
            if ($profile['credential_source'] === ProfileValidator::SOURCE_STATIC
                && $staticSecret === null
                && !$this->config->hasProfileSecret($id)) {
                throw new \InvalidArgumentException('A new manual profile requires --static-secret or must be created in the admin UI.');
            }
            if ($staticSecret !== null && (trim((string)$staticSecret) === '' || strlen((string)$staticSecret) > 4096)) {
                throw new \InvalidArgumentException('Manual password must contain 1-4096 characters.');
            }
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
        if ($profile['credential_source'] === ProfileValidator::SOURCE_STATIC) {
            if ($staticSecret !== null) {
                $this->config->saveProfileSecret($id, (string)$staticSecret);
            }
        } else {
            $this->config->clearProfileSecret($id);
        }
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
