<?php

declare(strict_types=1);

/**
 * tests/run.php — uruchamia testy backendu
 *
 * Użycie:
 *   php tests/run.php                  wszystkie testy
 *   php tests/run.php WeightedRoller   tylko klasy pasujące do wzorca
 *   php tests/run.php Fishing          j.w. (dopasowanie po fragmencie nazwy)
 *
 * Kod wyjścia: 0 = wszystko zielone, 1 = coś nie przeszło.
 *
 * Celowo bez PHPUnit i bez Composera: projekt szkolny ma się dać przetestować
 * jedną komendą na czystym XAMPP-ie, bez `composer install`.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\Tests\AssertionFailed;
use MocnyDrop\Tests\TestCase;

require_once dirname(__DIR__) . '/backend/bootstrap.php';

// Testy leżą poza backend/, więc potrzebują własnego prefixu autoloadera.
spl_autoload_register(static function (string $class): void {
    $prefix = 'MocnyDrop\\Tests\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Ostrzeżenia PHP nie mogą przechodzić niezauważone — podnosimy je do rangi
// wyjątku, żeby test wykrył np. niezainicjowany indeks tablicy.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

$filter = isset($argv[1]) ? trim((string) $argv[1]) : '';

$pliki = glob(__DIR__ . '/*Test.php');

if ($pliki === false || $pliki === []) {
    fwrite(STDERR, 'Nie znaleziono plików testowych w ' . __DIR__ . PHP_EOL);
    exit(1);
}

sort($pliki);

$lacznieTestow = 0;
$lacznieAsercji = 0;
$nieudane = [];
$pomijane = 0;

$poczatek = microtime(true);

echo 'MOCNY DROP — testy backendu (PHP ' . PHP_VERSION . ')' . PHP_EOL;
echo str_repeat('=', 64) . PHP_EOL;

foreach ($pliki as $plik) {
    $krotkaNazwa = basename($plik, '.php');

    if ($filter !== '' && stripos($krotkaNazwa, $filter) === false) {
        $pomijane++;
        continue;
    }

    $klasa = 'MocnyDrop\\Tests\\' . $krotkaNazwa;

    if (!class_exists($klasa)) {
        $nieudane[] = [$krotkaNazwa, '(klasa)', 'Klasa ' . $klasa . ' nie istnieje w pliku ' . basename($plik)];
        continue;
    }

    echo PHP_EOL . $krotkaNazwa . PHP_EOL;

    /** @var TestCase $instancja */
    $instancja = new $klasa();

    $metody = array_filter(
        get_class_methods($instancja),
        static fn (string $m): bool => str_starts_with($m, 'test')
    );

    foreach ($metody as $metoda) {
        $lacznieTestow++;

        // Każda metoda dostaje świeżą bazę w pamięci — setUp/tearDown.
        // setUp jest wewnątrz try, bo jego wywalenie też jest błędem testu,
        // a nie awarią całego przebiegu.
        try {
            $instancja->setUp();
            $instancja->$metoda();

            echo '  OK    ' . $metoda . PHP_EOL;
        } catch (AssertionFailed $e) {
            $nieudane[] = [$krotkaNazwa, $metoda, $e->getMessage()];

            echo '  FAIL  ' . $metoda . PHP_EOL;
            echo '        ' . $e->getMessage() . PHP_EOL;
        } catch (Throwable $e) {
            // To NIE jest nieudana asercja, tylko błąd wykonania (np. PDOException).
            // Rozróżniamy, bo "test wykazał błąd" i "test się wysypał" to różne rzeczy.
            $nieudane[] = [$krotkaNazwa, $metoda, $e::class . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()];

            echo '  ERROR ' . $metoda . PHP_EOL;
            echo '        ' . $e::class . ': ' . $e->getMessage() . PHP_EOL;
        } finally {
            $instancja->tearDown();
        }
    }

    $lacznieAsercji += $instancja->assertionCount();
}

$czas = microtime(true) - $poczatek;

echo PHP_EOL . str_repeat('=', 64) . PHP_EOL;

if ($nieudane === []) {
    echo sprintf(
        'ZIELONE: %d testów, %d asercji, %.2f s%s',
        $lacznieTestow,
        $lacznieAsercji,
        $czas,
        $pomijane > 0 ? ' (pominięte klasy: ' . $pomijane . ')' : ''
    ) . PHP_EOL;

    exit(0);
}

echo sprintf('CZERWONE: %d z %d testów nie przeszło (%.2f s)', count($nieudane), $lacznieTestow, $czas) . PHP_EOL . PHP_EOL;

foreach ($nieudane as [$klasa, $metoda, $powod]) {
    echo '  - ' . $klasa . '::' . $metoda . PHP_EOL;
    echo '    ' . $powod . PHP_EOL;
}

exit(1);
