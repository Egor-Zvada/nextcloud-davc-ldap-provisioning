<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\Tests\Unit;

use OCA\DAVCLdapProvisioning\Service\CredentialGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CredentialGateTest extends TestCase {
    /** @return array<string,array{0:?string,1:?string}> */
    public static function incompleteCredentials(): array {
        return [
            'both absent' => [null, null],
            'both empty' => ['', ''],
            'login absent' => [null, 'secret'],
            'login blank' => ['  ', 'secret'],
            'secret absent' => ['user@example.test', null],
            'secret blank' => ['user@example.test', "\t"],
        ];
    }

    #[DataProvider('incompleteCredentials')]
    public function testIncompleteCredentialsAreNotEligible(?string $login, ?string $secret): void {
        self::assertNull(CredentialGate::accept($login, $secret));
    }

    public function testBothAttributesOptTheUserIn(): void {
        self::assertSame(
            ['login' => 'user@example.test', 'secret' => 'app-password'],
            CredentialGate::accept(' user@example.test ', ' app-password '),
        );
    }

    public function testManualPasswordCanPreserveSignificantWhitespace(): void {
        self::assertSame(
            ['login' => 'user@example.test', 'secret' => ' secret with spaces '],
            CredentialGate::accept(' user@example.test ', ' secret with spaces ', false),
        );
    }
}
