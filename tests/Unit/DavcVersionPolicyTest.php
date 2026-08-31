<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\Tests\Unit;

use OCA\DAVCLdapProvisioning\Service\DavcAdapter;
use PHPUnit\Framework\TestCase;

class DavcVersionPolicyTest extends TestCase {
    public function testSupportedVersions(): void {
        self::assertTrue(DavcAdapter::supportsVersion('1.1.0'));
        self::assertTrue(DavcAdapter::supportsVersion('1.1.9'));
    }

    public function testUnknownVersionsFailClosed(): void {
        self::assertFalse(DavcAdapter::supportsVersion('1.0.9'));
        self::assertFalse(DavcAdapter::supportsVersion('1.2.0'));
        self::assertFalse(DavcAdapter::supportsVersion('2.0.0'));
    }

    public function testEmptyAndRootPathsAreEquivalent(): void {
        $method = new \ReflectionMethod(DavcAdapter::class, 'normalizePath');

        self::assertSame('/', $method->invoke(null, ''));
        self::assertSame('/', $method->invoke(null, '/'));
        self::assertSame('/caldav', $method->invoke(null, 'caldav'));
        self::assertSame('/caldav', $method->invoke(null, '//caldav'));
    }
}
