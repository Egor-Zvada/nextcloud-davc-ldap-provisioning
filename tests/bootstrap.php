<?php

declare(strict_types=1);

$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'OCA\\DAVCLdapProvisioning\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $base = str_starts_with($relative, 'Tests/')
        ? dirname(__DIR__) . '/tests/' . substr($relative, strlen('Tests/'))
        : dirname(__DIR__) . '/lib/' . $relative;
    $path = $base . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
