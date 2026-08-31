<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\Controller;

use OCA\DAVCLdapProvisioning\Config\AppConfig;
use OCA\DAVCLdapProvisioning\Config\ProfileValidator;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

class SettingsController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly AppConfig $config,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IL10N $l10n,
    ) {
        parent::__construct($appName, $request);
    }

    public function save(
        string $background_enabled = '0',
        int $interval = 1800,
        string $profiles = '[]',
    ): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
            return new DataResponse(['error' => $this->l10n->t('Admin privileges required')], 403);
        }
        if ($interval < 300 || $interval > 86400) {
            return new DataResponse(['error' => $this->l10n->t('Interval must be between 300 and 86400 seconds')], 400);
        }
        if (strlen($profiles) > 131072) {
            return new DataResponse(['error' => $this->l10n->t('Profile configuration is too large')], 400);
        }

        try {
            $decoded = json_decode($profiles, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new DataResponse(['error' => $this->l10n->t('Profile configuration is invalid JSON')], 400);
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return new DataResponse(['error' => $this->l10n->t('Profile configuration must be a list')], 400);
        }
        if (count($decoded) > ProfileValidator::MAX_PROFILES) {
            return new DataResponse([
                'error' => $this->l10n->t('At most %s profiles are allowed', [ProfileValidator::MAX_PROFILES]),
            ], 400);
        }

        $normalized = [];
        $ids = [];
        try {
            foreach ($decoded as $profile) {
                if (!is_array($profile)) {
                    throw new \InvalidArgumentException('Profile configuration is invalid');
                }
                if (trim((string)($profile['id'] ?? '')) === '') {
                    $profile['id'] = $this->config->generateProfileId();
                }
                $profile = ProfileValidator::normalize($profile);
                if (isset($ids[$profile['id']])) {
                    throw new \InvalidArgumentException('Profile IDs must be unique');
                }
                $ids[$profile['id']] = true;
                $normalized[] = $profile;
            }
            $this->config->saveProfiles($normalized);
            $this->config->saveGlobal(ProfileValidator::toBool($background_enabled), $interval);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $this->l10n->t($e->getMessage())], 400);
        }

        return new DataResponse(['ok' => true, 'config' => $this->config->all()]);
    }
}
