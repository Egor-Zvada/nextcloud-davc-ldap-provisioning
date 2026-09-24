<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\Config;

final class ProfileValidator {
    public const MAX_PROFILES = 20;
    public const MAX_TARGETS_PER_TYPE = 200;
    public const MIN_INTERVAL = 300;
    public const MAX_INTERVAL = 86400;
    public const SOURCE_LDAP = 'ldap';
    public const SOURCE_STATIC = 'static';

    /**
     * @param array<string, mixed> $profile
     * @return array{
     *     id:string,
     *     name:string,
     *     enabled:bool,
     *     credential_source:string,
     *     login_attribute:string,
     *     secret_attribute:string,
     *     static_login:string,
     *     target_all:bool,
     *     target_users:list<string>,
     *     target_groups:list<string>,
     *     host:string,
     *     port:int,
     *     path:string,
     *     secure_transport:bool,
     *     auto_enable_calendars:bool,
     *     auto_enable_contacts:bool,
     *     background_enabled:bool,
     *     background_interval:int
     * }
     */
    public static function normalize(array $profile): array {
        $id = strtolower(trim((string)($profile['id'] ?? '')));
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D', $id) !== 1) {
            throw new \InvalidArgumentException('Profile ID must contain 1-40 lowercase letters, numbers, underscores or dashes');
        }

        $name = trim((string)($profile['name'] ?? ''));
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($name === '' || $nameLength > 80) {
            throw new \InvalidArgumentException('Profile name must contain 1-80 characters');
        }

        $credentialSource = strtolower(trim((string)($profile['credential_source'] ?? self::SOURCE_LDAP)));
        if (!in_array($credentialSource, [self::SOURCE_LDAP, self::SOURCE_STATIC], true)) {
            throw new \InvalidArgumentException('Credential source must be LDAP or manual');
        }

        $loginAttribute = trim((string)($profile['login_attribute'] ?? ''));
        $secretAttribute = trim((string)($profile['secret_attribute'] ?? ''));
        $attributePattern = '/^[A-Za-z][A-Za-z0-9-]{0,63}$/D';
        if ($credentialSource === self::SOURCE_LDAP) {
            if (preg_match($attributePattern, $loginAttribute) !== 1 || preg_match($attributePattern, $secretAttribute) !== 1) {
                throw new \InvalidArgumentException('LDAP attribute names are invalid');
            }
        } else {
            if ($loginAttribute !== '' && preg_match($attributePattern, $loginAttribute) !== 1) {
                throw new \InvalidArgumentException('LDAP attribute names are invalid');
            }
            if ($secretAttribute !== '' && preg_match($attributePattern, $secretAttribute) !== 1) {
                throw new \InvalidArgumentException('LDAP attribute names are invalid');
            }
        }

        $staticLogin = trim((string)($profile['static_login'] ?? ''));
        if ($credentialSource === self::SOURCE_STATIC && $staticLogin === '') {
            throw new \InvalidArgumentException('Manual login is required');
        }
        if (strlen($staticLogin) > 320) {
            throw new \InvalidArgumentException('Manual login is too long');
        }

        $targetAll = self::toBool($profile['target_all'] ?? false);
        $targetUsers = self::normalizeTargets($profile['target_users'] ?? [], 'user');
        $targetGroups = self::normalizeTargets($profile['target_groups'] ?? [], 'group');

        $host = strtolower(trim((string)($profile['host'] ?? '')));
        $validHost = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            || filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (!$validHost) {
            throw new \InvalidArgumentException('DAV host is invalid');
        }

        $port = (int)($profile['port'] ?? 443);
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('DAV port must be between 1 and 65535');
        }

        $path = trim((string)($profile['path'] ?? '/'));
        if ($path === '' || !str_starts_with($path, '/') || strlen($path) > 2048 || strpbrk($path, "\r\n") !== false) {
            throw new \InvalidArgumentException('DAV path must start with /');
        }

        $backgroundEnabled = self::toBool($profile['background_enabled'] ?? false);
        $backgroundInterval = (int)($profile['background_interval'] ?? AppConfig::DEFAULT_INTERVAL);
        if ($backgroundInterval < self::MIN_INTERVAL || $backgroundInterval > self::MAX_INTERVAL) {
            throw new \InvalidArgumentException('Background interval must be between 300 and 86400 seconds');
        }
        if ($backgroundEnabled && !$targetAll && $targetUsers === [] && $targetGroups === []) {
            throw new \InvalidArgumentException('Select at least one user or group before enabling background provisioning');
        }

        return [
            'id' => $id,
            'name' => $name,
            'enabled' => self::toBool($profile['enabled'] ?? true),
            'credential_source' => $credentialSource,
            'login_attribute' => $loginAttribute,
            'secret_attribute' => $secretAttribute,
            'static_login' => $staticLogin,
            'target_all' => $targetAll,
            'target_users' => $targetUsers,
            'target_groups' => $targetGroups,
            'host' => $host,
            'port' => $port,
            'path' => $path,
            'secure_transport' => self::toBool($profile['secure_transport'] ?? true),
            'auto_enable_calendars' => self::toBool($profile['auto_enable_calendars'] ?? false),
            'auto_enable_contacts' => self::toBool($profile['auto_enable_contacts'] ?? false),
            'background_enabled' => $backgroundEnabled,
            'background_interval' => $backgroundInterval,
        ];
    }

    public static function toBool(mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return list<string> */
    private static function normalizeTargets(mixed $value, string $kind): array {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException(ucfirst($kind) . ' targets must be a list');
        }
        if (count($value) > self::MAX_TARGETS_PER_TYPE) {
            throw new \InvalidArgumentException('Too many selected ' . $kind . ' targets');
        }

        $normalized = [];
        foreach ($value as $target) {
            if (!is_string($target)) {
                throw new \InvalidArgumentException(ucfirst($kind) . ' target is invalid');
            }
            $target = trim($target);
            $length = function_exists('mb_strlen') ? mb_strlen($target) : strlen($target);
            if ($target === '' || $length > 128 || preg_match('/[\x00-\x1F\x7F]/', $target) === 1) {
                throw new \InvalidArgumentException(ucfirst($kind) . ' target is invalid');
            }
            $normalized[$target] = true;
        }
        return array_keys($normalized);
    }
}
