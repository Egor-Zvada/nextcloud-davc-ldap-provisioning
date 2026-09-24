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

    public function testCollectionDisplayNameUsesProfileAsPrefix(): void {
        $method = new \ReflectionMethod(DavcAdapter::class, 'formatCollectionLabel');

        self::assertSame(
            'Ministry: Personal events',
            $method->invoke(null, 'Ministry', 'Personal events', 'Calendar'),
        );
        self::assertSame(
            'Ministry: Calendar',
            $method->invoke(null, 'Ministry', '', 'Calendar'),
        );
    }

    public function testConnectorDiscoveryMarkerIsNotPartOfRemoteName(): void {
        $method = new \ReflectionMethod(DavcAdapter::class, 'remoteCollectionNames');
        $names = $method->invoke(null, [
            ['id' => '/calendars/personal/', 'label' => 'Personal - Personal events'],
            ['id' => '/calendars/team/', 'label' => 'Personal - Team'],
        ]);

        self::assertSame([
            '/calendars/personal/' => 'Personal events',
            '/calendars/team/' => 'Team',
        ], $names);
    }
}
