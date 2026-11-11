<?php

/**
 * Use simhash-unicode without Composer:
 *
 *     require '/path/to/simhash-unicode/bootstrap.php';
 *
 * Registers an autoloader for the IndianOpinion\Simhash namespace only.
 * Needs PHP 8.1 or later and the mbstring extension; intl is recommended.
 */

declare(strict_types=1);

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    throw new RuntimeException('simhash-unicode needs PHP 8.1 or later.');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'IndianOpinion\\Simhash\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
