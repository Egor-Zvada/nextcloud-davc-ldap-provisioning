<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\BackgroundJob;

use OCA\DAVCLdapProvisioning\Config\AppConfig;
use OCA\DAVCLdapProvisioning\Service\Provisioner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class ProvisionJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private readonly Provisioner $provisioner,
        private readonly AppConfig $config,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        // One lightweight dispatcher serves every profile. Each profile keeps
        // its own due time and interval in AppConfig.
        $this->setInterval(300);
        $this->setAllowParallelRuns(false);
    }

    protected function run($argument): void {
        $now = time();
        foreach ($this->config->profiles(false) as $profile) {
            if (!$this->config->isProfileDue($profile, $now)) {
                continue;
            }

            try {
                $summary = $this->provisioner->provisionTargetsForProfile($profile, false);
                $this->logger->info('DAV profile background provisioning completed', [
                    'app' => 'davc_ldap_provisioning',
                    'profile' => $profile['id'],
                    'processed' => $summary['processed'],
                    'ok' => $summary['ok'],
                    'skipped' => $summary['skipped'],
                    'failed' => $summary['failed'],
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('DAV profile background provisioning stopped', [
                    'app' => 'davc_ldap_provisioning',
                    'profile' => $profile['id'],
                    'exception' => $e,
                ]);
            } finally {
                // Avoid retrying a broken remote endpoint every five minutes;
                // the configured profile interval also applies after errors.
                $this->config->markProfileBackgroundRun((string)$profile['id'], $now);
            }
        }
    }
}
