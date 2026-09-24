<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\Settings;

use OCA\DAVCLdapProvisioning\AppInfo\Application;
use OCA\DAVCLdapProvisioning\Config\AppConfig;
use OCA\DAVCLdapProvisioning\Service\DavcAdapter;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
    public function __construct(
        private readonly AppConfig $config,
        private readonly DavcAdapter $davc,
        private readonly IL10N $l10n,
        private readonly IAppManager $appManager,
    ) {
    }

    public function getForm(): TemplateResponse {
        $compatibility = ['ok' => false, 'message' => $this->l10n->t('Unknown compatibility status')];
        try {
            $version = $this->davc->assertCompatible();
            $compatibility = [
                'ok' => true,
                'message' => $this->l10n->t('DAV Connector %s is supported', [$version]),
            ];
        } catch (\Throwable $e) {
            $compatibility = ['ok' => false, 'message' => $e->getMessage()];
        }

        return new TemplateResponse('davc_ldap_provisioning', 'admin_v3', [
            'config' => $this->config->all(),
            'compatibility' => $compatibility,
            'appVersion' => $this->appManager->getAppVersion(Application::APP_ID),
        ]);
    }

    public function getSection(): string {
        return 'additional';
    }

    public function getPriority(): int {
        return 60;
    }
}
