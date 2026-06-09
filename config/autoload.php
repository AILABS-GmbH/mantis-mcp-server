<?php

declare(strict_types=1);

/**
 * Autoloading.
 *
 * Prefers the Composer autoloader (vendor/autoload.php). If it is not present
 * (e.g. because "composer install" has not been run yet), a minimal PSR-4
 * autoloader for the "MantisMcp\" namespace is registered so the server runs
 * even without Composer.
 */

$projectRoot = dirname(__DIR__);
$composerAutoload = $projectRoot . '/vendor/autoload.php';

if (is_file($composerAutoload)) {
    require $composerAutoload;
    return;
}

spl_autoload_register(static function (string $class) use ($projectRoot): void {
    $prefix = 'MantisMcp\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = $projectRoot . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
