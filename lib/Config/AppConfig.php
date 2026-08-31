<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\Config;

use OCA\DAVCLdapProvisioning\AppInfo\Application;
use OCP\IConfig;

class AppConfig {
    /**
     * `enabled` is reserved by Nextcloud for the app's own enabled state.
     * Never use it for the background provisioning switch.
     */
    public const BACKGROUND_ENABLED_KEY = 'background_provisioning_enabled';
    public const PROFILES_KEY = 'profiles_json';
    public const DEFAULT_LOGIN_ATTRIBUTE = 'extensionAttribute1';
    public const DEFAULT_SECRET_ATTRIBUTE = 'extensionAttribute2';
    public const DEFAULT_LABEL = 'Yandex Calendar';
    public const DEFAULT_HOST = 'caldav.yandex.ru';
    public const DEFAULT_PORT = 443;
    public const DEFAULT_PATH = '/';
    public const DEFAULT_INTERVAL = 1800;

    public function __construct(private readonly IConfig $config) {
    }

    public function isEnabled(): bool {
        return $this->getBool(self::BACKGROUND_ENABLED_KEY, false);
    }

    public function interval(): int {
        return max(300, min(86400, (int)$this->get('interval', (string)self::DEFAULT_INTERVAL)));
    }

    /**
     * @return list<array{
     *     id:string,
     *     name:string,
     *     enabled:bool,
     *     login_attribute:string,
     *     secret_attribute:string,
     *     host:string,
     *     port:int,
     *     path:string,
     *     secure_transport:bool,
     *     auto_enable_calendars:bool,
     *     auto_enable_contacts:bool
     * }>
     */
    public function profiles(bool $includeDisabled = true): array {
        $stored = trim($this->get(self::PROFILES_KEY, ''));
        if ($stored === '') {
            $profiles = [$this->legacyProfile()];
        } else {
            try {
                $decoded = json_decode($stored, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \RuntimeException('Stored DAV provisioning profiles are invalid JSON', 0, $e);
            }
            if (!is_array($decoded) || !array_is_list($decoded)) {
                throw new \RuntimeException('Stored DAV provisioning profiles must be a list');
            }
            $profiles = [];
            foreach ($decoded as $profile) {
                if (!is_array($profile)) {
                    throw new \RuntimeException('Stored DAV provisioning profile is invalid');
                }
                $profiles[] = ProfileValidator::normalize($profile);
            }
        }

        if ($includeDisabled) {
            return $profiles;
        }
        return array_values(array_filter($profiles, static fn(array $profile): bool => $profile['enabled']));
    }

    /** @return array<string, mixed>|null */
    public function profile(string $id, bool $includeDisabled = true): ?array {
        foreach ($this->profiles($includeDisabled) as $profile) {
            if ($profile['id'] === $id) {
                return $profile;
            }
        }
        return null;
    }

    /** @param list<array<string, mixed>> $profiles */
    public function saveProfiles(array $profiles): void {
        if (count($profiles) > ProfileValidator::MAX_PROFILES) {
            throw new \InvalidArgumentException('At most ' . ProfileValidator::MAX_PROFILES . ' profiles are allowed');
        }

        $normalized = [];
        $ids = [];
        foreach ($profiles as $profile) {
            $profile = ProfileValidator::normalize($profile);
            if (isset($ids[$profile['id']])) {
                throw new \InvalidArgumentException('Profile IDs must be unique');
            }
            $ids[$profile['id']] = true;
            $normalized[] = $profile;
        }

        $json = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->config->setAppValue(Application::APP_ID, self::PROFILES_KEY, $json);
    }

    public function saveGlobal(bool $backgroundEnabled, int $interval): void {
        if ($interval < 300 || $interval > 86400) {
            throw new \InvalidArgumentException('Interval must be between 300 and 86400 seconds');
        }
        $this->config->setAppValue(Application::APP_ID, self::BACKGROUND_ENABLED_KEY, $backgroundEnabled ? '1' : '0');
        $this->config->setAppValue(Application::APP_ID, 'interval', (string)$interval);
    }

    public function generateProfileId(): string {
        do {
            $id = 'profile-' . bin2hex(random_bytes(6));
        } while ($this->profile($id) !== null);
        return $id;
    }

    /** @return array<string, mixed> */
    public function all(): array {
        return [
            'background_enabled' => $this->isEnabled(),
            'interval' => $this->interval(),
            'profiles' => $this->profiles(),
        ];
    }

    public function managedServiceId(string $uid, string $profileId): ?int {
        $value = $this->config->getUserValue($uid, Application::APP_ID, $this->userKey('managed_service_id', $profileId), '');
        return ctype_digit($value) ? (int)$value : null;
    }

    public function setManagedServiceId(string $uid, string $profileId, int $sid): void {
        $this->config->setUserValue($uid, Application::APP_ID, $this->userKey('managed_service_id', $profileId), (string)$sid);
    }

    public function clearManagedServiceId(string $uid, string $profileId): void {
        $this->config->deleteUserValue($uid, Application::APP_ID, $this->userKey('managed_service_id', $profileId));
    }

    public function recordResult(string $uid, string $profileId, string $status, string $message): void {
        $this->config->setUserValue($uid, Application::APP_ID, $this->userKey('last_status', $profileId), $status);
        $this->config->setUserValue($uid, Application::APP_ID, $this->userKey('last_message', $profileId), mb_substr($message, 0, 500));
        $this->config->setUserValue($uid, Application::APP_ID, $this->userKey('last_run', $profileId), (string)time());
    }

    private function get(string $key, string $default): string {
        return $this->config->getAppValue(Application::APP_ID, $key, $default);
    }

    private function getBool(string $key, bool $default): bool {
        $value = strtolower($this->get($key, $default ? '1' : '0'));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<string, mixed> */
    private function legacyProfile(): array {
        $path = trim($this->get('path', self::DEFAULT_PATH));
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return ProfileValidator::normalize([
            'id' => 'default',
            'name' => $this->get('label', self::DEFAULT_LABEL),
            'enabled' => true,
            'login_attribute' => $this->get('login_attribute', self::DEFAULT_LOGIN_ATTRIBUTE),
            'secret_attribute' => $this->get('secret_attribute', self::DEFAULT_SECRET_ATTRIBUTE),
            'host' => $this->get('host', self::DEFAULT_HOST),
            'port' => max(1, min(65535, (int)$this->get('port', (string)self::DEFAULT_PORT))),
            'path' => $path,
            'secure_transport' => $this->getBool('secure_transport', true),
            'auto_enable_calendars' => $this->getBool('auto_enable_calendars', true),
            'auto_enable_contacts' => false,
        ]);
    }

    private function userKey(string $base, string $profileId): string {
        // Keep the 0.1.x keys for the migrated default profile so its managed sid survives the upgrade.
        return $profileId === 'default' ? $base : $base . '_' . $profileId;
    }
}
