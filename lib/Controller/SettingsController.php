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
use OCP\IUserManager;
use OCP\IUserSession;

class SettingsController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly AppConfig $config,
        private readonly IUserSession $userSession,
        private readonly IUserManager $userManager,
        private readonly IGroupManager $groupManager,
        private readonly IL10N $l10n,
    ) {
        parent::__construct($appName, $request);
    }

    public function save(string $profiles = '[]'): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
            return new DataResponse(['error' => $this->l10n->t('Admin privileges required')], 403);
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
        $secretUpdates = [];
        $secretClears = [];
        try {
            foreach ($decoded as $profile) {
                if (!is_array($profile)) {
                    throw new \InvalidArgumentException('Profile configuration is invalid');
                }
                if (trim((string)($profile['id'] ?? '')) === '') {
                    $profile['id'] = $this->config->generateProfileId();
                }
                $rawSecret = (string)($profile['static_secret'] ?? '');
                $profile = ProfileValidator::normalize($profile);
                if (isset($ids[$profile['id']])) {
                    throw new \InvalidArgumentException('Profile IDs must be unique');
                }
                $this->validateTargets($profile);

                if ($profile['credential_source'] === ProfileValidator::SOURCE_STATIC) {
                    if (trim($rawSecret) === '' && !$this->config->hasProfileSecret($profile['id'])) {
                        throw new \InvalidArgumentException('Manual password is required');
                    }
                    if ($rawSecret !== '') {
                        if (strlen($rawSecret) > 4096) {
                            throw new \InvalidArgumentException('Manual password is too long');
                        }
                        $secretUpdates[$profile['id']] = $rawSecret;
                    }
                } else {
                    $secretClears[] = $profile['id'];
                }
                $ids[$profile['id']] = true;
                $normalized[] = $profile;
            }
            $this->config->saveProfiles($normalized);
            foreach ($secretUpdates as $profileId => $secret) {
                $this->config->saveProfileSecret($profileId, $secret);
            }
            foreach ($secretClears as $profileId) {
                $this->config->clearProfileSecret($profileId);
            }
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $this->l10n->t($e->getMessage())], 400);
        }

        return new DataResponse(['ok' => true, 'config' => $this->config->all()]);
    }

    public function principals(string $query = ''): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
            return new DataResponse(['error' => $this->l10n->t('Admin privileges required')], 403);
        }

        $query = trim($query);
        if ($query === '') {
            return new DataResponse(['users' => [], 'groups' => []]);
        }

        $users = [];
        foreach (array_merge(
            $this->userManager->searchDisplayName($query, 20, 0),
            $this->userManager->search($query, 20, 0),
        ) as $candidate) {
            $users[$candidate->getUID()] = [
                'id' => $candidate->getUID(),
                'label' => $candidate->getDisplayName(),
            ];
            if (count($users) >= 20) {
                break;
            }
        }

        $groups = [];
        foreach ($this->groupManager->search($query, 20, 0) as $group) {
            $groups[] = [
                'id' => $group->getGID(),
                'label' => $group->getDisplayName(),
            ];
        }

        return new DataResponse([
            'users' => array_values($users),
            'groups' => $groups,
        ]);
    }

    /** @param array<string, mixed> $profile */
    private function validateTargets(array $profile): void {
        foreach ($profile['target_users'] as $uid) {
            if ($this->userManager->get((string)$uid) === null) {
                throw new \InvalidArgumentException('Selected user no longer exists: ' . $uid);
            }
        }
        foreach ($profile['target_groups'] as $groupId) {
            if ($this->groupManager->get((string)$groupId) === null) {
                throw new \InvalidArgumentException('Selected group no longer exists: ' . $groupId);
            }
        }
    }
}
