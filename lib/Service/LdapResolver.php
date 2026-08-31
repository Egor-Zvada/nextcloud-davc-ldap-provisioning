<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\Service;

use OCP\IUser;
use OCP\LDAP\ILDAPProviderFactory;

class LdapResolver {
    public function __construct(private readonly ILDAPProviderFactory $ldapProviderFactory) {
    }

    /**
     * @return array{login:string,secret:string}|null
     */
    public function resolve(IUser $user, string $loginAttribute, string $secretAttribute): ?array {
        if ($user->getBackendClassName() !== 'LDAP') {
            return null;
        }

        if (!$this->ldapProviderFactory->isAvailable()) {
            throw new \RuntimeException('LDAP provider is not available');
        }

        $provider = $this->ldapProviderFactory->getLDAPProvider();
        return CredentialGate::accept(
            $provider->getUserAttribute($user->getUID(), $loginAttribute),
            $provider->getUserAttribute($user->getUID(), $secretAttribute),
        );
    }
}
