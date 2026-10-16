<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;
use RuntimeException;

/**
 * backend/Database.php
 *
 * Połączenie PDO z SQLite. Baza plikowa tworzy się sama przy pierwszym
 * żądaniu, więc nikt nie musi nic ręcznie importować w phpMyAdminie.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class Database
{
    private static ?PDO $pdo = null;

    /** Wspólne połączenie do pliku bazy. */
    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::openFile();
        }

        return self::$pdo;
    }

    /** Podstawia gotowe połączenie. Używane wyłącznie przez testy. */
    public static function setConnection(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    /**
     * Baza w pamięci ze schematem — dla testów.
     * Nie ma tu WAL, bo journal_mode dla :memory: zawsze wraca do "memory".
     */
    public static function openMemory(): PDO
    {
        $pdo = self::configure(new PDO('sqlite::memory:'));

        self::applySchema($pdo);

        return $pdo;
    }

    private static function openFile(): PDO
    {
        $file = Config::databaseFile();
        $dir = dirname($file);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Nie udało się utworzyć katalogu bazy: ' . $dir);
        }

        $isNew = !is_file($file);

        $pdo = self::configure(new PDO('sqlite:' . $file));

        // WAL pozwala czytać w trakcie zapisu — przydatne, gdy kilka osób
        // klika stronę naraz podczas testów integracyjnych.
        $pdo->exec('PRAGMA journal_mode = WAL;');

        if ($isNew || (int) $pdo->query('PRAGMA user_version')->fetchColumn() < 2) {
            self::applySchema($pdo);
        }

        return $pdo;
    }

    private static function configure(PDO $pdo): PDO
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // SQLite domyślnie IGNORUJE klucze obce. Bez tej linijki ON DELETE
        // CASCADE z schema.sql po cichu by nie działał.
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA busy_timeout = 5000;');

        return $pdo;
    }

    /** Wczytuje database/schema.sql do podanego połączenia. */
    public static function applySchema(PDO $pdo): void
    {
        $schema = Config::schemaFile();

        if (!is_file($schema)) {
            throw new RuntimeException('Brak pliku schematu: ' . $schema);
        }

        $sql = file_get_contents($schema);

        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('Plik schematu jest pusty: ' . $schema);
        }

        Migracje::zastosuj($pdo, $sql);
    }
}
