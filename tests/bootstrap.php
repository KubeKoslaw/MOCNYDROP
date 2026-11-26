<?php

declare(strict_types=1);

/**
 * Bootstrap PHPUnit: autoloader Composera (PHPUnit + MocnyDrop\Tests\)
 * oraz autoloader produkcyjny backendu (MocnyDrop\).
 */
$root = dirname(__DIR__);

$vendor = $root . '/vendor/autoload.php';

if (!is_file($vendor)) {
    fwrite(
        STDERR,
        "Brak vendor/autoload.php — uruchom `composer install`." . PHP_EOL
    );
    exit(1);
}

require_once $vendor;
require_once $root . '/backend/bootstrap.php';
