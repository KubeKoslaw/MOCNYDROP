<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\ApiException;
use MocnyDrop\Database;
use MocnyDrop\Rzadkosc;
use PDO;
use Throwable;

/**
 * tests/TestCase.php
 *
 * Baza dla testów. Bez PHPUnit i bez Composera — projekt szkolny ma się
 * dać odpalić jedną komendą `php tests/run.php` na czystym XAMPP.
 *
 * Każdy test dostaje ŚWIEŻĄ bazę w pamięci (setUp), więc testy są od siebie
 * niezależne i można je uruchamiać w dowolnej kolejności.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
abstract class TestCase
{
    protected PDO $db;

    private int $assertions = 0;

    /** Podpinana przed każdą metodą testową. */
    public function setUp(): void
    {
        $this->db = Database::openMemory();

        Database::setConnection($this->db);

        // Rzadkosc cache'uje poziomy rzadkości per proces. Bez czyszczenia
        // pierwszy test "zamroziłby" dane i reszta czytałaby je z cache.
        Rzadkosc::clearCache();
    }

    public function tearDown(): void
    {
        Database::setConnection(null);
        Database::reset();
        Rzadkosc::clearCache();
    }

    public function assertionCount(): int
    {
        return $this->assertions;
    }

    protected function assertTrue(bool $condition, string $message): void
    {
        $this->tick();

        if (!$condition) {
            throw new AssertionFailed($message);
        }
    }

    protected function assertFalse(bool $condition, string $message): void
    {
        $this->assertTrue(!$condition, $message);
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        $this->tick();

        if ($expected !== $actual) {
            throw new AssertionFailed(sprintf(
                '%s (oczekiwano %s, jest %s)',
                $message,
                self::dump($expected),
                self::dump($actual)
            ));
        }
    }

    /** Porównanie float z tolerancją — pieniądze liczymy na REAL, więc == jest zawodne. */
    protected function assertFloat(float $expected, float $actual, string $message, float $epsilon = 0.0001): void
    {
        $this->tick();

        if (abs($expected - $actual) > $epsilon) {
            throw new AssertionFailed(sprintf(
                '%s (oczekiwano %s, jest %s, tolerancja %s)',
                $message,
                self::dump($expected),
                self::dump($actual),
                self::dump($epsilon)
            ));
        }
    }

    protected function assertBetween(float $min, float $max, float $actual, string $message): void
    {
        $this->tick();

        if ($actual < $min || $actual > $max) {
            throw new AssertionFailed(sprintf(
                '%s (oczekiwano zakresu %s..%s, jest %s)',
                $message,
                self::dump($min),
                self::dump($max),
                self::dump($actual)
            ));
        }
    }

    protected function assertCount(int $expected, array $actual, string $message): void
    {
        $this->tick();

        if (count($actual) !== $expected) {
            throw new AssertionFailed(sprintf('%s (oczekiwano %d elementów, jest %d)', $message, $expected, count($actual)));
        }
    }

    protected function assertArrayHasKey(string|int $key, array $array, string $message): void
    {
        $this->tick();

        if (!array_key_exists($key, $array)) {
            throw new AssertionFailed($message . ' (brak klucza: ' . self::dump($key) . ')');
        }
    }

    protected function assertNotNull(mixed $value, string $message): void
    {
        $this->tick();

        if ($value === null) {
            throw new AssertionFailed($message);
        }
    }

    /**
     * Przeciwieństwo assertNotNull().
     * Potrzebne m.in. dla Token::uwierzytelnij() i Token::bearerToken(),
     * które sygnalizują odrzucenie przez null, a nie przez wyjątek.
     */
    protected function assertNull(mixed $value, string $message): void
    {
        $this->tick();

        if ($value !== null) {
            throw new AssertionFailed(sprintf(
                '%s (oczekiwano null, jest %s)',
                $message,
                self::dump($value)
            ));
        }
    }

    /**
     * Sprawdza, że wywołanie rzuci konkretny wyjątek.
     *
     * @param class-string<Throwable> $exceptionClass
     * @param string|null             $expectedErrorCode sprawdzany tylko dla ApiException
     */
    protected function assertThrows(
        string $exceptionClass,
        callable $callback,
        string $message,
        ?string $expectedErrorCode = null
    ): void {
        $this->tick();

        try {
            $callback();
        } catch (Throwable $thrown) {
            if (!($thrown instanceof $exceptionClass)) {
                throw new AssertionFailed(sprintf(
                    '%s (oczekiwano %s, rzucono %s: %s)',
                    $message,
                    $exceptionClass,
                    $thrown::class,
                    $thrown->getMessage()
                ));
            }

            if ($expectedErrorCode !== null) {
                if (!($thrown instanceof ApiException)) {
                    throw new AssertionFailed($message . ' (kod błędu sprawdzamy tylko dla ApiException)');
                }

                if ($thrown->errorCode() !== $expectedErrorCode) {
                    throw new AssertionFailed(sprintf(
                        '%s (oczekiwano kodu "%s", jest "%s")',
                        $message,
                        $expectedErrorCode,
                        $thrown->errorCode()
                    ));
                }
            }

            return;
        }

        throw new AssertionFailed($message . ' (nie rzucono żadnego wyjątku)');
    }

    private function tick(): void
    {
        $this->assertions++;
    }

    private static function dump(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_float($value) || is_int($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return '"' . $value . '"';
        }

        return get_debug_type($value);
    }
}
