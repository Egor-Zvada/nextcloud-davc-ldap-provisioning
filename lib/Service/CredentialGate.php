<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\Service;

/**
 * Treat the two LDAP attributes as an explicit per-user opt-in switch.
 * Both values must be present before any DAV Connector operation is allowed.
 */
final class CredentialGate {
    /**
     * @return array{login:string,secret:string}|null
     */
    public static function accept(?string $login, ?string $secret): ?array {
        $login = trim((string)$login);
        $secret = trim((string)$secret);

        if ($login === '' || $secret === '') {
            return null;
        }

        if (strlen($login) > 320) {
            throw new \RuntimeException('LDAP login value is unexpectedly long');
        }
        if (strlen($secret) > 4096) {
            throw new \RuntimeException('LDAP secret value is unexpectedly long');
        }

        return ['login' => $login, 'secret' => $secret];
    }
}
