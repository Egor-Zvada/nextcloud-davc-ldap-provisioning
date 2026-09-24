<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\Service;

use OCA\DAVCLdapProvisioning\Config\AppConfig;
use OCA\DAVCLdapProvisioning\Exception\IncompatibleDavcException;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * All integration with DAV Connector internals is intentionally isolated here.
 * DAV Connector currently has no public provisioning API, so this class is version-gated.
 */
class DavcAdapter {
    private const DAVC_APP_ID = 'integration_davc';

    private ?object $core = null;
    private ?object $services = null;
    private ?object $harmonization = null;
    private ?object $localFactory = null;

    public function __construct(
        private readonly IAppManager $appManager,
        private readonly AppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function supportsVersion(string $version): bool {
        return preg_match('/^1\.1\.[0-9]+(?:[-+].*)?$/', $version) === 1;
    }

    public function version(): string {
        return $this->appManager->getAppVersion(self::DAVC_APP_ID);
    }

    public function assertCompatible(): string {
        if (!$this->appManager->isEnabledForAnyone(self::DAVC_APP_ID)) {
            throw new IncompatibleDavcException('DAV Connector is not enabled');
        }

        $version = $this->version();
        if (!self::supportsVersion($version)) {
            throw new IncompatibleDavcException(
                sprintf('DAV Connector %s is not supported. Provisioning is disabled until compatibility is verified.', $version)
            );
        }

        $this->loadServices();
        return $version;
    }

    /**
     * @param array{label:string,host:string,port:int,path:string,secure_transport:bool} $settings
     * @return array{sid:int,action:string,old_sid?:int}
     */
    public function upsertService(string $profileId, string $uid, string $login, string $secret, array $settings): array {
        $this->assertCompatible();
        $service = $this->findManagedService($profileId, $uid, $login, $settings);

        if ($service === null) {
            $created = $this->connect($uid, $login, $secret, $settings);
            $sid = (int)$created->getId();
            $this->appConfig->setManagedServiceId($uid, $profileId, $sid);
            return ['sid' => $sid, 'action' => 'created'];
        }

        if ($this->requiresReconnect($service, $login, $secret, $settings)) {
            // Fail-safe replacement: validate and create the new service first.
            // Only after successful DAV discovery is the old service removed.
            $replacement = $this->connect($uid, $login, $secret, $settings);
            $newSid = (int)$replacement->getId();
            $oldSid = (int)$service->getId();

            // Keep the old service until the caller successfully enables calendars on the replacement.
            return ['sid' => $newSid, 'action' => 'reconnected', 'old_sid' => $oldSid];
        }

        if ((string)$service->getLabel() !== $settings['label']) {
            $service->setLabel($settings['label']);
            $this->services->deposit($uid, $service);
            return ['sid' => (int)$service->getId(), 'action' => 'updated'];
        }

        $this->appConfig->setManagedServiceId($uid, $profileId, (int)$service->getId());
        return ['sid' => (int)$service->getId(), 'action' => 'unchanged'];
    }

    /**
     * Produce a read-only provisioning plan. This never adopts a service or
     * writes the managed service id, so it is safe to use for --dry-run.
     *
     * @param array{label:string,host:string,port:int,path:string,secure_transport:bool} $settings
     * @return array{action:string,sid?:int}
     */
    public function planService(string $profileId, string $uid, string $login, string $secret, array $settings): array {
        $this->assertCompatible();
        $service = $this->findManagedService($profileId, $uid, $login, $settings, false);

        if ($service === null) {
            return ['action' => 'create'];
        }

        $sid = (int)$service->getId();
        if ($this->requiresReconnect($service, $login, $secret, $settings)) {
            return ['action' => 'reconnect', 'sid' => $sid];
        }

        if ((string)$service->getLabel() !== $settings['label']) {
            return ['action' => 'update', 'sid' => $sid];
        }

        return ['action' => 'unchanged', 'sid' => $sid];
    }


    public function finalizeReplacement(string $profileId, string $uid, int $oldSid, int $newSid): void {
        $this->assertCompatible();
        try {
            $this->core->disconnectAccount($uid, $oldSid);
        } catch (\Throwable $e) {
            $this->logger->warning('Replacement DAV service is working but the old managed service could not be removed', [
                'app' => 'davc_ldap_provisioning',
                'uid' => $uid,
                'oldSid' => $oldSid,
                'newSid' => $newSid,
                'exception' => $e,
            ]);
        }
        $this->appConfig->setManagedServiceId($uid, $profileId, $newSid);
    }

    public function rollbackReplacement(string $profileId, string $uid, int $newSid): void {
        $this->assertCompatible();
        try {
            $this->core->disconnectAccount($uid, $newSid);
            if ($this->appConfig->managedServiceId($uid, $profileId) === $newSid) {
                $this->appConfig->clearManagedServiceId($uid, $profileId);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to clean up an unsuccessful replacement DAV service', [
                'app' => 'davc_ldap_provisioning',
                'uid' => $uid,
                'newSid' => $newSid,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Disconnect only a service previously adopted or created for this profile.
     * Remote DAV data is never deleted; DAV Connector removes its local cache,
     * collection correlations, and scheduled harmonization task.
     *
     * @return array{action:string,sid?:int}
     */
    public function disconnectManagedService(string $profileId, string $uid): array {
        $sid = $this->appConfig->managedServiceId($uid, $profileId);
        if ($sid === null) {
            return ['action' => 'absent'];
        }

        $this->assertCompatible();
        try {
            $service = $this->services->fetchByUserIdAndServiceId($uid, $sid);
        } catch (\Throwable) {
            $this->appConfig->clearManagedServiceId($uid, $profileId);
            return ['action' => 'already_absent', 'sid' => $sid];
        }
        if ($service === null) {
            $this->appConfig->clearManagedServiceId($uid, $profileId);
            return ['action' => 'already_absent', 'sid' => $sid];
        }

        $this->core->disconnectAccount($uid, $sid);
        $this->appConfig->clearManagedServiceId($uid, $profileId);
        return ['action' => 'disconnected', 'sid' => $sid];
    }

    /**
     * @return array{
     *     calendars:array{enabled:int,total:int},
     *     contacts:array{enabled:int,total:int}
     * }
     */
    public function enableCollections(
        string $uid,
        int $sid,
        bool $enableCalendars,
        bool $enableContacts,
    ): array {
        $this->assertCompatible();

        $result = [
            'calendars' => ['enabled' => 0, 'total' => 0],
            'contacts' => ['enabled' => 0, 'total' => 0],
        ];
        if (!$enableCalendars && !$enableContacts) {
            return $result;
        }

        $remote = $this->core->remoteCollectionsFetch($uid, $sid);
        if ($enableCalendars && ($remote['EventsSupported'] ?? false) !== true) {
            throw new \RuntimeException('Remote service did not expose a CalDAV calendar collection');
        }
        if ($enableContacts && ($remote['ContactsSupported'] ?? false) !== true) {
            throw new \RuntimeException('Remote service did not expose a CardDAV address book collection');
        }

        $local = $this->core->localCollectionsFetch($uid, $sid);
        $eventExisting = $this->collectionIds($local['EventCollections'] ?? []);
        $contactExisting = $this->collectionIds($local['ContactCollections'] ?? []);
        $eventEnable = [];
        $contactEnable = [];

        $remoteCalendars = $remote['EventsCollections'] ?? [];
        if ($enableCalendars) {
            $eventEnable = $this->missingCollections($remoteCalendars, $eventExisting, 'Calendar');
            $result['calendars'] = ['enabled' => count($eventEnable), 'total' => count($remoteCalendars)];
        }

        $remoteContacts = $remote['ContactsCollections'] ?? [];
        if ($enableContacts) {
            $contactEnable = $this->missingCollections($remoteContacts, $contactExisting, 'Address book');
            $result['contacts'] = ['enabled' => count($contactEnable), 'total' => count($remoteContacts)];
        }

        if ($contactEnable !== [] || $eventEnable !== []) {
            $this->core->localCollectionsDeposit($uid, $sid, $contactEnable, $eventEnable);
        }

        return $result;
    }

    /**
     * Apply local presentation settings to DAV Connector collections. This
     * replaces the `DavC:` prefix with the profile name and can assign a
     * calendar color. Remote collection names and colors are never changed.
     *
     * @return array{
     *     calendars:array{updated:int,total:int},
     *     contacts:array{updated:int,total:int}
     * }
     */
    public function applyCollectionPresentation(
        string $uid,
        int $sid,
        string $profileName,
        ?string $calendarColor = null,
    ): array {
        $this->assertCompatible();

        $remote = $this->core->remoteCollectionsFetch($uid, $sid);
        $local = $this->core->localCollectionsFetch($uid, $sid);
        $eventNames = self::remoteCollectionNames($remote['EventsCollections'] ?? []);
        $contactNames = self::remoteCollectionNames($remote['ContactsCollections'] ?? []);
        $eventCollections = $local['EventCollections'] ?? [];
        $contactCollections = $local['ContactCollections'] ?? [];
        $result = [
            'calendars' => ['updated' => 0, 'total' => count($eventCollections)],
            'contacts' => ['updated' => 0, 'total' => count($contactCollections)],
        ];

        $eventStore = $this->localFactory->eventsStore();
        foreach ($eventCollections as $collection) {
            $remoteId = (string)$collection->getCcid();
            if (!isset($eventNames[$remoteId])) {
                continue;
            }
            $label = self::formatCollectionLabel($profileName, $eventNames[$remoteId], 'Calendar');
            $modified = false;
            if ((string)$collection->getLabel() !== $label) {
                $collection->setLabel($label);
                $modified = true;
            }
            if ($calendarColor !== null && strtolower((string)$collection->getColor()) !== $calendarColor) {
                $collection->setColor($calendarColor);
                $modified = true;
            }
            if ($modified) {
                $eventStore->collectionModify($collection);
                $result['calendars']['updated']++;
            }
        }

        $contactStore = $this->localFactory->contactsStore();
        foreach ($contactCollections as $collection) {
            $remoteId = (string)$collection->getCcid();
            if (!isset($contactNames[$remoteId])) {
                continue;
            }
            $label = self::formatCollectionLabel($profileName, $contactNames[$remoteId], 'Address book');
            if ((string)$collection->getLabel() === $label) {
                continue;
            }
            $collection->setLabel($label);
            $contactStore->collectionModify($collection);
            $result['contacts']['updated']++;
        }

        return $result;
    }

    public function harmonize(string $uid, int $sid): void {
        $this->assertCompatible();
        $this->harmonization->performHarmonization($uid, $sid);
    }

    /** @param iterable<object> $collections */
    private function collectionIds(iterable $collections): array {
        $ids = [];
        foreach ($collections as $collection) {
            $ids[(string)$collection->getCcid()] = true;
        }
        return $ids;
    }

    /**
     * @param array<int, array<string, mixed>> $remoteCollections
     * @param array<string, bool> $existing
     * @return list<array{id:string,ccid:string,label:string,enabled:bool}>
     */
    private function missingCollections(array $remoteCollections, array $existing, string $fallbackLabel): array {
        $enable = [];
        foreach ($remoteCollections as $collection) {
            $remoteId = (string)($collection['id'] ?? '');
            if ($remoteId === '' || isset($existing[$remoteId])) {
                continue;
            }
            $enable[] = [
                'id' => '',
                'ccid' => $remoteId,
                'label' => (string)($collection['label'] ?? $fallbackLabel),
                'enabled' => true,
            ];
        }
        return $enable;
    }

    /** @param array{label:string,host:string,port:int,path:string,secure_transport:bool} $settings */
    private function connect(string $uid, string $login, string $secret, array $settings): object {
        $constants = 'OCA\\DAVC\\Constants';
        $basicAuth = constant($constants . '::AUTHENTICATION_TYPE_BASIC');

        return $this->core->connectAccount($uid, [
            'label' => $settings['label'],
            'auth' => $basicAuth,
            'bauth_id' => $login,
            'bauth_secret' => $secret,
            'location_protocol' => $settings['secure_transport'] ? 'https' : 'http',
            'location_host' => $settings['host'],
            'location_port' => $settings['port'],
            'location_path' => $settings['path'],
        ]);
    }

    /** @param array{label:string,host:string,port:int,path:string,secure_transport:bool} $settings */
    private function findManagedService(string $profileId, string $uid, string $login, array $settings, bool $persist = true): ?object {
        $sid = $this->appConfig->managedServiceId($uid, $profileId);
        if ($sid !== null) {
            try {
                $service = $this->services->fetchByUserIdAndServiceId($uid, $sid);
                if ($service !== null) {
                    return $service;
                }
            } catch (\Throwable) {
                if ($persist) {
                    $this->appConfig->clearManagedServiceId($uid, $profileId);
                }
            }
        }

        // Adoption path for a connection that was created manually before this companion app.
        $basicAuth = constant('OCA\\DAVC\\Constants::AUTHENTICATION_TYPE_BASIC');
        foreach ($this->services->fetchByUserId($uid) as $service) {
            if (
                (string)$service->getAuth() === $basicAuth
                && (string)$service->getBauthId() === $login
                && $this->endpointMatches($service, $settings)
            ) {
                if ($persist) {
                    $this->appConfig->setManagedServiceId($uid, $profileId, (int)$service->getId());
                }
                return $service;
            }
        }

        return null;
    }

    /** @param array{label:string,host:string,port:int,path:string,secure_transport:bool} $settings */
    private function requiresReconnect(object $service, string $login, string $secret, array $settings): bool {
        return (string)$service->getBauthId() !== $login
            || !hash_equals((string)$service->getBauthSecret(), $secret)
            || !$this->endpointMatches($service, $settings);
    }

    /** @param array{label:string,host:string,port:int,path:string,secure_transport:bool} $settings */
    private function endpointMatches(object $service, array $settings): bool {
        return strtolower((string)$service->getLocationHost()) === strtolower($settings['host'])
            && (int)$service->getLocationPort() === $settings['port']
            && self::normalizePath((string)$service->getLocationPath()) === self::normalizePath($settings['path'])
            && strtolower((string)$service->getLocationProtocol()) === ($settings['secure_transport'] ? 'https' : 'http');
    }

    private static function normalizePath(string $path): string {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return '/';
        }
        return '/' . ltrim($path, '/');
    }

    /** @param iterable<array<string, mixed>> $collections */
    private static function remoteCollectionNames(iterable $collections): array {
        $names = [];
        foreach ($collections as $collection) {
            $id = (string)($collection['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $label = trim((string)($collection['label'] ?? ''));
            // DAV Connector 1.1.x adds this UI-only marker when returning
            // discovery results. It is not part of the remote display name.
            if (str_starts_with($label, 'Personal - ')) {
                $label = trim(substr($label, strlen('Personal - ')));
            }
            $names[$id] = $label;
        }
        return $names;
    }

    private static function formatCollectionLabel(string $profileName, string $remoteName, string $fallback): string {
        $profileName = trim($profileName);
        $remoteName = trim($remoteName);
        if ($remoteName === '') {
            $remoteName = $fallback;
        }
        $label = $profileName === '' ? $remoteName : $profileName . ': ' . $remoteName;

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($label) > 255 ? mb_substr($label, 0, 255) : $label;
        }
        return strlen($label) > 255 ? substr($label, 0, 255) : $label;
    }

    private function loadServices(): void {
        if ($this->core !== null && $this->services !== null && $this->harmonization !== null && $this->localFactory !== null) {
            return;
        }

        $this->appManager->loadApp(self::DAVC_APP_ID);

        $applicationClass = 'OCA\\DAVC\\AppInfo\\Application';
        $coreClass = 'OCA\\DAVC\\Service\\CoreService';
        $servicesClass = 'OCA\\DAVC\\Service\\ServicesService';
        $harmonizationClass = 'OCA\\DAVC\\Service\\HarmonizationService';
        $localFactoryClass = 'OCA\\DAVC\\Service\\Local\\LocalFactory';

        foreach ([$applicationClass, $coreClass, $servicesClass, $harmonizationClass, $localFactoryClass] as $class) {
            if (!class_exists($class)) {
                throw new IncompatibleDavcException('Expected DAV Connector class is missing: ' . $class);
            }
        }

        try {
            $application = new $applicationClass();
            $container = $application->getContainer();
            $this->core = $container->get($coreClass);
            $this->services = $container->get($servicesClass);
            $this->harmonization = $container->get($harmonizationClass);
            $this->localFactory = $container->get($localFactoryClass);
        } catch (\Throwable $e) {
            throw new IncompatibleDavcException('Unable to initialize DAV Connector internal services: ' . $e->getMessage(), 0, $e);
        }
    }
}
