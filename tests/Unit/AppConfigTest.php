<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\Tests\Unit;

use OCA\DAVCLdapProvisioning\Config\AppConfig;
use PHPUnit\Framework\TestCase;

class AppConfigTest extends TestCase {
    public function testBackgroundSwitchDoesNotUseReservedEnabledKey(): void {
        self::assertSame('background_provisioning_enabled', AppConfig::BACKGROUND_ENABLED_KEY);
        self::assertNotSame('enabled', AppConfig::BACKGROUND_ENABLED_KEY);
    }
}
