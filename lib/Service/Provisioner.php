<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\Service;

use OCA\DAVCLdapProvisioning\Config\AppConfig;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class Provisioner {
    public function __construct(
        private readonly IUserManager $userManager,
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

        if ($user->getBackendClassName() !== 'LDAP') {
            return [
                'uid' => $uid,
                'profile' => $profileId,
                'profile_name' => $profileName,
                'status' => 'skipped',
                'reason' => 'not_ldap',
            ];
        }
        if (!(bool)$profile['enabled']) {
            return [
                'uid' => $uid,
                'profile' => $profileId,
                'profile_name' => $profileName,
                'status' => 'skipped',
                'reason' => 'profile_disabled',
            ];
        }

        $credentials = $this->ldapResolver->resolve(
            $user,
            (string)$profile['login_attribute'],
            (string)$profile['secret_attribute'],
        );

        if ($credentials === null) {
            return [
                'uid' => $uid,
                'profile' => $profileId,
                'profile_name' => $profileName,
                'status' => 'skipped',
                'reason' => 'ldap_attributes_missing',
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

        $message = sprintf(
            'DAV service %s (sid=%d), calendars enabled=%d/%d, contacts enabled=%d/%d',
            $service['action'],
            $service['sid'],
            $collectionResult['calendars']['enabled'],
            $collectionResult['calendars']['total'],
            $collectionResult['contacts']['enabled'],
            $collectionResult['contacts']['total'],
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

        $this->userManager->callForAllUsers(function (IUser $user) use (&$summary, $dryRun, $profileId): void {
            $userSummary = $this->provisionUser($user, $dryRun, $profileId);
            foreach (['processed', 'ok', 'skipped', 'failed'] as $key) {
                $summary[$key] += $userSummary[$key];
            }
            array_push($summary['results'], ...$userSummary['results']);
        });

        return $summary;
    }

    public function userById(string $uid): ?IUser {
        return $this->userManager->get($uid);
    }
}
