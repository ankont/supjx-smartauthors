<?php

declare(strict_types=1);

if (!defined('_JEXEC')) {
    define('_JEXEC', 1);
}

require_once __DIR__ . '/Mocks/JoomlaMocks.php';

// Register PSR-4 autoloader for SmartAuthors plugin
spl_autoload_register(static function (string $class): void {
    $prefix = 'SuperSoft\\Plugin\\System\\SmartAuthors\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = dirname(__DIR__) . '/plugin/src/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
