<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\Config;

final class ProfileValidator {
    public const MAX_PROFILES = 20;

    /**
     * @param array<string, mixed> $profile
     * @return array{
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

        $loginAttribute = trim((string)($profile['login_attribute'] ?? ''));
        $secretAttribute = trim((string)($profile['secret_attribute'] ?? ''));
        $attributePattern = '/^[A-Za-z][A-Za-z0-9-]{0,63}$/D';
        if (preg_match($attributePattern, $loginAttribute) !== 1 || preg_match($attributePattern, $secretAttribute) !== 1) {
            throw new \InvalidArgumentException('LDAP attribute names are invalid');
        }

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

        return [
            'id' => $id,
            'name' => $name,
            'enabled' => self::toBool($profile['enabled'] ?? true),
            'login_attribute' => $loginAttribute,
            'secret_attribute' => $secretAttribute,
            'host' => $host,
            'port' => $port,
            'path' => $path,
            'secure_transport' => self::toBool($profile['secure_transport'] ?? true),
            'auto_enable_calendars' => self::toBool($profile['auto_enable_calendars'] ?? false),
            'auto_enable_contacts' => self::toBool($profile['auto_enable_contacts'] ?? false),
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
}
