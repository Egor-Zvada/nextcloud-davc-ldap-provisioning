<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\Tests\Unit;

use OCA\DAVCLdapProvisioning\Config\AppConfig;
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
            'credential_source' => 'ldap',
            'login_attribute' => 'msDS-cloudExtensionAttribute1',
            'secret_attribute' => 'msDS-cloudExtensionAttribute2',
            'static_login' => '',
            'target_all' => false,
            'target_users' => ['alice'],
            'target_groups' => [],
            'host' => 'caldav.example.org',
            'port' => 443,
            'path' => '/',
            'secure_transport' => true,
            'calendar_color_enabled' => true,
            'calendar_color' => '#A1B2C3',
            'auto_enable_calendars' => false,
            'auto_enable_contacts' => false,
            'background_enabled' => false,
            'background_interval' => 1800,
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
        self::assertTrue($normalized['calendar_color_enabled']);
        self::assertSame('#a1b2c3', $normalized['calendar_color']);
    }

    public function testExistingProfileKeepsItsCalendarColorUntilOptedIn(): void {
        $profile = $this->validProfile();
        unset($profile['calendar_color_enabled'], $profile['calendar_color']);

        $normalized = ProfileValidator::normalize($profile);

        self::assertFalse($normalized['calendar_color_enabled']);
        self::assertSame(AppConfig::DEFAULT_CALENDAR_COLOR, $normalized['calendar_color']);
    }

    public function testManualCredentialsDoNotRequireLdapAttributes(): void {
        $profile = $this->validProfile();
        $profile['credential_source'] = 'static';
        $profile['login_attribute'] = '';
        $profile['secret_attribute'] = '';
        $profile['static_login'] = 'calendar@example.test';
        $profile['target_groups'] = ['calendar-users'];

        $normalized = ProfileValidator::normalize($profile);

        self::assertSame('static', $normalized['credential_source']);
        self::assertSame('calendar@example.test', $normalized['static_login']);
        self::assertSame(['alice'], $normalized['target_users']);
        self::assertSame(['calendar-users'], $normalized['target_groups']);
    }

    public function testBackgroundRequiresAnExplicitTargetWhenNotAllUsers(): void {
        $profile = $this->validProfile();
        $profile['target_users'] = [];
        $profile['target_groups'] = [];
        $profile['background_enabled'] = true;

        $this->expectException(\InvalidArgumentException::class);
        ProfileValidator::normalize($profile);
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
            'invalid calendar color' => ['calendar_color', 'blue'],
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
