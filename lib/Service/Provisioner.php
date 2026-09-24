<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\Service;

use OCA\DAVCLdapProvisioning\Config\AppConfig;
use OCA\DAVCLdapProvisioning\Config\ProfileValidator;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class Provisioner {
    public function __construct(
        private readonly IUserManager $userManager,
        private readonly IGroupManager $groupManager,
        private readonly LdapResolver $ldapResolver,
        private readonly DavcAdapter $davc,
        private readonly AppConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public function provisionProfile(IUser $user, array $profile, bool $dryRun = false): array {
        $uid = $user->getUID();
        $profileId = (string)$profile['id'];
        $profileName = (string)$profile['name'];

        if (!(bool)$profile['enabled']) {
            return [
                'uid' => $uid,
                'profile' => $profileId,
                'profile_name' => $profileName,
                'status' => 'skipped',
                'reason' => 'profile_disabled',
            ];
        }
        if (!$this->isUserTargeted($user, $profile)) {
            return [
                'uid' => $uid,
                'profile' => $profileId,
                'profile_name' => $profileName,
                'status' => 'skipped',
                'reason' => 'not_targeted',
            ];
        }

        $credentialSource = (string)($profile['credential_source'] ?? ProfileValidator::SOURCE_LDAP);
        if ($credentialSource === ProfileValidator::SOURCE_STATIC) {
            $credentials = CredentialGate::accept(
                (string)$profile['static_login'],
                $this->config->profileSecret($profileId),
                false,
            );
            $missingReason = 'manual_credentials_missing';
        } else {
            if ($user->getBackendClassName() !== 'LDAP') {
                return [
                    'uid' => $uid,
                    'profile' => $profileId,
                    'profile_name' => $profileName,
                    'status' => 'skipped',
                    'reason' => 'not_ldap',
                ];
            }
            $credentials = $this->ldapResolver->resolve(
                $user,
                (string)$profile['login_attribute'],
                (string)$profile['secret_attribute'],
            );
            $missingReason = 'ldap_attributes_missing';
        }

        if ($credentials === null) {
            return [
                'uid' => $uid,
                'profile' => $profileId,
                'profile_name' => $profileName,
                'status' => 'skipped',
                'reason' => $missingReason,
            ];
        }

        $this->davc->assertCompatible();
        $settings = [
            'label' => $profileName,
            'host' => (string)$profile['host'],
            'port' => (int)$profile['port'],
            'path' => (string)$profile['path'],
            'secure_transport' => (bool)$profile['secure_transport'],
        ];

        if ($dryRun) {
            $plan = $this->davc->planService(
                $profileId,
                $uid,
                $credentials['login'],
                $credentials['secret'],
                $settings,
            );
            return [
                'uid' => $uid,
                'profile' => $profileId,
                'profile_name' => $profileName,
                'status' => 'dry-run',
                'action' => $plan['action'],
                'sid' => $plan['sid'] ?? null,
                'login' => $credentials['login'],
                'host' => $profile['host'],
            ];
        }

        $service = $this->davc->upsertService(
            $profileId,
            $uid,
            $credentials['login'],
            $credentials['secret'],
            $settings,
        );
        $collectionResult = [
            'calendars' => ['enabled' => 0, 'total' => 0],
            'contacts' => ['enabled' => 0, 'total' => 0],
        ];
        $labelResult = [
            'calendars' => ['updated' => 0, 'total' => 0],
            'contacts' => ['updated' => 0, 'total' => 0],
        ];

        try {
            $collectionResult = $this->davc->enableCollections(
                $uid,
                (int)$service['sid'],
                (bool)$profile['auto_enable_calendars'],
                (bool)$profile['auto_enable_contacts'],
            );
            $newCollections = $collectionResult['calendars']['enabled'] + $collectionResult['contacts']['enabled'];
            if ($newCollections > 0) {
                $this->davc->harmonize($uid, (int)$service['sid']);

                // Some DAV servers canonicalize collection URLs during the first harmonization.
                // Reconcile once more so an automatically selected collection is usable immediately.
                $reconciled = $this->davc->enableCollections(
                    $uid,
                    (int)$service['sid'],
                    (bool)$profile['auto_enable_calendars'],
                    (bool)$profile['auto_enable_contacts'],
                );
                $reconciledCount = $reconciled['calendars']['enabled'] + $reconciled['contacts']['enabled'];
                foreach (['calendars', 'contacts'] as $type) {
                    $collectionResult[$type]['enabled'] += $reconciled[$type]['enabled'];
                    $collectionResult[$type]['total'] = max(
                        $collectionResult[$type]['total'],
                        $reconciled[$type]['total'],
                    );
                }
                if ($reconciledCount > 0) {
                    $this->davc->harmonize($uid, (int)$service['sid']);
                }
            }
            if (($service['action'] ?? '') === 'reconnected' && isset($service['old_sid'])) {
                $this->davc->finalizeReplacement(
                    $profileId,
                    $uid,
                    (int)$service['old_sid'],
                    (int)$service['sid'],
                );
            }
        } catch (\Throwable $e) {
            if (in_array(($service['action'] ?? ''), ['created', 'reconnected'], true)) {
                $this->davc->rollbackReplacement($profileId, $uid, (int)$service['sid']);
            }
            throw $e;
        }

        // Collection display names are cosmetic. A naming failure must not
        // roll back an otherwise healthy external DAV connection.
        try {
            $labelResult = $this->davc->applyCollectionLabels(
                $uid,
                (int)$service['sid'],
                $profileName,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('DAV collections were connected but their display names could not be updated', [
                'app' => 'davc_ldap_provisioning',
                'uid' => $uid,
                'profile' => $profileId,
                'exception' => $e,
            ]);
        }

        $message = sprintf(
            'DAV service %s (sid=%d), calendars enabled=%d/%d renamed=%d, contacts enabled=%d/%d renamed=%d',
            $service['action'],
            $service['sid'],
            $collectionResult['calendars']['enabled'],
            $collectionResult['calendars']['total'],
            $labelResult['calendars']['updated'],
            $collectionResult['contacts']['enabled'],
            $collectionResult['contacts']['total'],
            $labelResult['contacts']['updated'],
        );
        $this->config->recordResult($uid, $profileId, 'ok', $message);

        return [
            'uid' => $uid,
            'profile' => $profileId,
            'profile_name' => $profileName,
            'status' => 'ok',
            'action' => $service['action'],
            'sid' => $service['sid'],
            'calendars' => $collectionResult['calendars'],
            'contacts' => $collectionResult['contacts'],
            'labels' => $labelResult,
        ];
    }

    /** @return array{processed:int,ok:int,skipped:int,failed:int,results:list<array<string,mixed>>} */
    public function provisionUser(IUser $user, bool $dryRun = false, ?string $profileId = null): array {
        if ($profileId !== null) {
            $profile = $this->config->profile($profileId, true);
            if ($profile === null) {
                throw new \InvalidArgumentException('Profile not found: ' . $profileId);
            }
            $profiles = [$profile];
        } else {
            $profiles = $this->config->profiles(false);
        }

        $summary = ['processed' => 0, 'ok' => 0, 'skipped' => 0, 'failed' => 0, 'results' => []];
        foreach ($profiles as $profile) {
            $summary['processed']++;
            try {
                $result = $this->provisionProfile($user, $profile, $dryRun);
                $summary['results'][] = $result;
                if (($result['status'] ?? '') === 'skipped') {
                    $summary['skipped']++;
                } else {
                    $summary['ok']++;
                }
            } catch (\Throwable $e) {
                $summary['failed']++;
                $failedProfileId = (string)($profile['id'] ?? '?');
                $summary['results'][] = [
                    'uid' => $user->getUID(),
                    'profile' => $failedProfileId,
                    'profile_name' => (string)($profile['name'] ?? $failedProfileId),
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
                $this->config->recordResult($user->getUID(), $failedProfileId, 'failed', $e->getMessage());
                $this->logger->error('LDAP DAV provisioning failed for user profile', [
                    'app' => 'davc_ldap_provisioning',
                    'uid' => $user->getUID(),
                    'profile' => $failedProfileId,
                    'exception' => $e,
                ]);
            }
        }
        return $summary;
    }

    /** @return array{processed:int,ok:int,skipped:int,failed:int,results:list<array<string,mixed>>} */
    public function provisionAll(bool $dryRun = false, ?string $profileId = null): array {
        $summary = ['processed' => 0, 'ok' => 0, 'skipped' => 0, 'failed' => 0, 'results' => []];

        if ($profileId !== null) {
            $profile = $this->config->profile($profileId, true);
            if ($profile === null) {
                throw new \InvalidArgumentException('Profile not found: ' . $profileId);
            }
            $profiles = [$profile];
        } else {
            $profiles = $this->config->profiles(false);
        }

        foreach ($profiles as $profile) {
            $this->mergeSummary($summary, $this->provisionTargetsForProfile($profile, $dryRun));
        }

        return $summary;
    }

    /**
     * Apply a profile's desired state immediately. Enabled profiles provision
     * their current targets; disabled profiles disconnect every DAV service
     * previously managed by that profile, including users removed from its
     * current target list.
     *
     * @param array<string, mixed> $profile
     * @return array{processed:int,ok:int,skipped:int,failed:int,results:list<array<string,mixed>>}
     */
    public function reconcileProfile(array $profile): array {
        if ((bool)($profile['enabled'] ?? false)) {
            return $this->provisionTargetsForProfile($profile, false);
        }
        return $this->deprovisionProfile((string)$profile['id'], (string)$profile['name']);
    }

    /**
     * @return array{processed:int,ok:int,skipped:int,failed:int,results:list<array<string,mixed>>}
     */
    public function deprovisionProfile(string $profileId, string $profileName = ''): array {
        $summary = ['processed' => 0, 'ok' => 0, 'skipped' => 0, 'failed' => 0, 'results' => []];

        $this->userManager->callForAllUsers(function (IUser $user) use (&$summary, $profileId, $profileName): void {
            $uid = $user->getUID();
            if ($this->config->managedServiceId($uid, $profileId) === null) {
                return;
            }

            $summary['processed']++;
            try {
                $disconnection = $this->davc->disconnectManagedService($profileId, $uid);
                $summary['ok']++;
                $summary['results'][] = [
                    'uid' => $uid,
                    'profile' => $profileId,
                    'profile_name' => $profileName !== '' ? $profileName : $profileId,
                    'status' => 'ok',
                    'action' => $disconnection['action'],
                    'sid' => $disconnection['sid'] ?? null,
                ];
                $this->config->recordResult($uid, $profileId, 'disabled', (string)$disconnection['action']);
            } catch (\Throwable $e) {
                $summary['failed']++;
                $summary['results'][] = [
                    'uid' => $uid,
                    'profile' => $profileId,
                    'profile_name' => $profileName !== '' ? $profileName : $profileId,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
                $this->config->recordResult($uid, $profileId, 'failed', $e->getMessage());
                $this->logger->error('Managed DAV service could not be disconnected for disabled profile', [
                    'app' => 'davc_ldap_provisioning',
                    'uid' => $uid,
                    'profile' => $profileId,
                    'exception' => $e,
                ]);
            }
        });

        return $summary;
    }

    /**
     * Provision exactly the users selected by one profile. Group membership is
     * resolved at run time, so adding a user to a selected group takes effect
     * without editing the profile.
     *
     * @param array<string, mixed> $profile
     * @return array{processed:int,ok:int,skipped:int,failed:int,results:list<array<string,mixed>>}
     */
    public function provisionTargetsForProfile(array $profile, bool $dryRun = false): array {
        $summary = ['processed' => 0, 'ok' => 0, 'skipped' => 0, 'failed' => 0, 'results' => []];

        if ((bool)$profile['target_all']) {
            $this->userManager->callForAllUsers(function (IUser $user) use (&$summary, $dryRun, $profile): void {
                $this->provisionTargetUser($summary, $user, $profile, $dryRun);
            });
            return $summary;
        }

        /** @var array<string, IUser> $users */
        $users = [];
        foreach ($profile['target_users'] as $uid) {
            $user = $this->userManager->get((string)$uid);
            if ($user !== null) {
                $users[$user->getUID()] = $user;
            }
        }
        foreach ($profile['target_groups'] as $groupId) {
            $group = $this->groupManager->get((string)$groupId);
            if ($group === null) {
                continue;
            }
            foreach ($group->getUsers() as $user) {
                $users[$user->getUID()] = $user;
            }
        }

        foreach ($users as $user) {
            $this->provisionTargetUser($summary, $user, $profile, $dryRun);
        }

        return $summary;
    }

    /** @param array<string, mixed> $profile */
    private function isUserTargeted(IUser $user, array $profile): bool {
        if ((bool)($profile['target_all'] ?? false)) {
            return true;
        }
        $uid = $user->getUID();
        if (in_array($uid, $profile['target_users'] ?? [], true)) {
            return true;
        }
        foreach ($profile['target_groups'] ?? [] as $groupId) {
            if ($this->groupManager->isInGroup($uid, (string)$groupId)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array{processed:int,ok:int,skipped:int,failed:int,results:list<array<string,mixed>>} $summary
     * @param array<string, mixed> $profile
     */
    private function provisionTargetUser(array &$summary, IUser $user, array $profile, bool $dryRun): void {
        try {
            $this->appendResult($summary, $this->provisionProfile($user, $profile, $dryRun));
        } catch (\Throwable $e) {
            $profileId = (string)$profile['id'];
            $summary['processed']++;
            $summary['failed']++;
            $summary['results'][] = [
                'uid' => $user->getUID(),
                'profile' => $profileId,
                'profile_name' => (string)$profile['name'],
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
            $this->config->recordResult($user->getUID(), $profileId, 'failed', $e->getMessage());
            $this->logger->error('DAV provisioning failed for targeted user profile', [
                'app' => 'davc_ldap_provisioning',
                'uid' => $user->getUID(),
                'profile' => $profileId,
                'exception' => $e,
            ]);
        }
    }

    /**
     * @param array{processed:int,ok:int,skipped:int,failed:int,results:list<array<string,mixed>>} $summary
     * @param array<string, mixed> $result
     */
    private function appendResult(array &$summary, array $result): void {
        $summary['processed']++;
        $summary['results'][] = $result;
        if (($result['status'] ?? '') === 'skipped') {
            $summary['skipped']++;
        } else {
            $summary['ok']++;
        }
    }

    /**
     * @param array{processed:int,ok:int,skipped:int,failed:int,results:list<array<string,mixed>>} $target
     * @param array{processed:int,ok:int,skipped:int,failed:int,results:list<array<string,mixed>>} $source
     */
    private function mergeSummary(array &$target, array $source): void {
        foreach (['processed', 'ok', 'skipped', 'failed'] as $key) {
            $target[$key] += $source[$key];
        }
        array_push($target['results'], ...$source['results']);
    }

    public function userById(string $uid): ?IUser {
        return $this->userManager->get($uid);
    }
}
