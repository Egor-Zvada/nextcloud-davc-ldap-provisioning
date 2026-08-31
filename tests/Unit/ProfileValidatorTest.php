<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\Tests\Unit;

use OCA\DAVCLdapProvisioning\Config\ProfileValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProfileValidatorTest extends TestCase {
    /** @return array<string, mixed> */
    private function validProfile(): array {
        return [
            'id' => 'ministry-calendar',
            'name' => 'Ministry calendar',
            'enabled' => true,
            'login_attribute' => 'msDS-cloudExtensionAttribute1',
            'secret_attribute' => 'msDS-cloudExtensionAttribute2',
            'host' => 'caldav.example.org',
            'port' => 443,
            'path' => '/',
            'secure_transport' => true,
            'auto_enable_calendars' => false,
            'auto_enable_contacts' => false,
        ];
    }

    public function testNormalizesBooleansAndHost(): void {
        $profile = $this->validProfile();
        $profile['host'] = 'CALDAV.EXAMPLE.ORG';
        $profile['enabled'] = '1';
        $profile['auto_enable_contacts'] = 'yes';

        $normalized = ProfileValidator::normalize($profile);

        self::assertSame('caldav.example.org', $normalized['host']);
        self::assertTrue($normalized['enabled']);
        self::assertTrue($normalized['auto_enable_contacts']);
        self::assertFalse($normalized['auto_enable_calendars']);
    }

    /** @return array<string, array{0:string,1:mixed}> */
    public static function invalidFields(): array {
        return [
            'unsafe id' => ['id', '../profile'],
            'blank name' => ['name', ''],
            'bad login attribute' => ['login_attribute', 'bad attribute'],
            'bad secret attribute' => ['secret_attribute', ''],
            'URL instead of host' => ['host', 'https://dav.example.org'],
            'port too high' => ['port', 70000],
            'relative path' => ['path', 'dav/'],
        ];
    }

    #[DataProvider('invalidFields')]
    public function testRejectsInvalidProfile(string $field, mixed $value): void {
        $profile = $this->validProfile();
        $profile[$field] = $value;

        $this->expectException(\InvalidArgumentException::class);
        ProfileValidator::normalize($profile);
    }
}
