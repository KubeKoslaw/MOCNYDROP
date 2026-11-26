<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\ApiException;
use MocnyDrop\Database;
use MocnyDrop\Rzadkosc;
use PDO;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use Throwable;

/**
 * Baza testów: każdy test dostaje świeżą bazę SQLite w pamięci
 * (schemat + rzadkości + ustawienia z `database/schema.sql`).
 *
 * `Database::setConnection()` jest konieczne, bo część kodu testowanego
 * (np. `FishingService::cast()` bez `$pdo`) sięga po globalne połączenie.
 */
abstract class TestCase extends PhpUnitTestCase
{
    protected PDO $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = Database::openMemory();
        Database::setConnection($this->db);
        Rzadkosc::clearCache();
    }

    protected function tearDown(): void
    {
        Database::reset();
        Rzadkosc::clearCache();

        parent::tearDown();
    }

    protected function assertFloat(float $expected, float $actual, string $message = '', float $delta = 0.001): void
    {
        $this->assertEqualsWithDelta($expected, $actual, $delta, $message);
    }

    protected function assertBetween(float $min, float $max, float $actual, string $message = ''): void
    {
        $prefix = $message === '' ? '' : $message . ' — ';

        $this->assertGreaterThanOrEqual($min, $actual, $prefix . 'wartość poniżej dolnej granicy');
        $this->assertLessThanOrEqual($max, $actual, $prefix . 'wartość powyżej górnej granicy');
    }

    /**
     * @param class-string<Throwable> $expected
     * @param string|null $kod oczekiwany `ApiException::errorCode()`
     */
    protected function assertThrows(string $expected, callable $fn, string $message = '', ?string $kod = null): Throwable
    {
        try {
            $fn();
        } catch (Throwable $error) {
            $this->assertInstanceOf($expected, $error, $message);

            if ($kod !== null && $error instanceof ApiException) {
                $this->assertSame($kod, $error->errorCode(), ($message === '' ? '' : $message . ' — ') . 'kod błędu');
            }

            return $error;
        }

        $this->fail(($message === '' ? 'Oczekiwano wyjątku' : $message) . ' — oczekiwano ' . $expected . ', nic nie rzucono.');
    }
}
