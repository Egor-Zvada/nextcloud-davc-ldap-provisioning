<?php

declare(strict_types=1);

namespace OCA\DAVCLdapProvisioning\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AppInfoCompatibilityTest extends TestCase {
    public function testManifestSupportsNextcloud34And35(): void {
        $manifestPath = dirname(__DIR__, 2) . '/appinfo/info.xml';
        $manifest = simplexml_load_file($manifestPath);

        self::assertNotFalse($manifest);
        self::assertSame('0.5.0', (string)$manifest->version);
        self::assertSame('34', (string)$manifest->dependencies->nextcloud['min-version']);
        self::assertSame('35', (string)$manifest->dependencies->nextcloud['max-version']);
        self::assertSame('8.2', (string)$manifest->dependencies->php['min-version']);
        self::assertSame('8.5', (string)$manifest->dependencies->php['max-version']);
    }
}
