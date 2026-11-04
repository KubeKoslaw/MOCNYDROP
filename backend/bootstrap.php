<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

spl_autoload_register(static function (string $class): void {
    $prefix = 'MocnyDrop\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));

    if ($relative === '') {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
