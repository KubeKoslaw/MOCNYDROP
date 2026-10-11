<?php

declare(strict_types=1);

/**
 * backend/bootstrap.php
 *
 * Punkt wejścia backendu: autoloader dla przestrzeni nazw MocnyDrop\.
 *
 * Projekt celowo nie używa Composera — ma działać po prostu przez
 * `php -S localhost:8000` albo XAMPP, bez kroku instalacji zależności.
 * Mapowanie jest zgodne z PSR-4: MocnyDrop\Foo\Bar -> backend/Foo/Bar.php
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

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

    // require_once, nie require: testy rejestrują drugi autoloader dla
    // MocnyDrop\Tests\ i wolimy, żeby żadna ścieżka nie mogła wczytać
    // tej samej klasy dwa razy.
    if (is_file($file)) {
        require_once $file;
    }
});
