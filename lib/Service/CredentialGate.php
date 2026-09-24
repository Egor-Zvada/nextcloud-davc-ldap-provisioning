<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\Service;

/**
 * Validate a resolved LDAP or manually configured Basic-auth credential pair.
 */
final class CredentialGate {
    /**
     * @return array{login:string,secret:string}|null
     */
    public static function accept(?string $login, ?string $secret, bool $trimSecret = true): ?array {
        $login = trim((string)$login);
        $secret = (string)$secret;
        if ($trimSecret) {
            $secret = trim($secret);
        }

        if ($login === '' || trim($secret) === '') {
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
