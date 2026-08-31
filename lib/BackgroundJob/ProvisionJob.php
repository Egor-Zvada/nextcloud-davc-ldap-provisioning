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
        $this->setInterval($this->config->interval());
        $this->setAllowParallelRuns(false);
    }

    protected function run($argument): void {
        if (!$this->config->isEnabled()) {
            return;
        }

        try {
            $summary = $this->provisioner->provisionAll(false);
            $this->logger->info('LDAP DAV background provisioning completed', [
                'app' => 'davc_ldap_provisioning',
                'processed' => $summary['processed'],
                'ok' => $summary['ok'],
                'skipped' => $summary['skipped'],
                'failed' => $summary['failed'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('LDAP DAV background provisioning stopped', [
                'app' => 'davc_ldap_provisioning',
                'exception' => $e,
            ]);
        }
    }
}
