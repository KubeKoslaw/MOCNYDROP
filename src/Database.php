<?php
/**
 * src/Database.php
 * Prosta klasa zarządzająca połączeniem z bazą SQLite za pomocą PDO.
 */

class Database {
    private static ?PDO $pdo = null;

    /**
     * Zwraca aktywne połączenie z bazą danych SQLite.
     * Jeśli baza jeszcze nie istnieje, automatycznie tworzy tabele i dane startowe.
     */
    public static function getConnection(): PDO {
        if (self::$pdo === null) {
            $dbDir = __DIR__ . '/../database';
            $dbFile = $dbDir . '/mocnydrop.sqlite';
            
            // Sprawdzamy czy plik bazy istnieje przed połączeniem
            $isNewDatabase = !file_exists($dbFile);

            if (!is_dir($dbDir)) {
                mkdir($dbDir, 0777, true);
            }

            // Tworzymy połączenie PDO z plikiem SQLite
            self::$pdo = new PDO('sqlite:' . $dbFile);
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            // Włączamy obsługę kluczy obcych (FOREIGN KEYS) w SQLite
            self::$pdo->exec('PRAGMA foreign_keys = ON;');

            // Jeśli baza powstała na nowo, inicjalizujemy schemat i dane
            if ($isNewDatabase) {
                self::initializeDatabase();
            }
        }

        return self::$pdo;
    }

    /**
     * Wczytuje plik schema.sql oraz seed.sql do bazy danych.
     */
    public static function initializeDatabase(): void {
        $db = self::$pdo;
        $schemaFile = __DIR__ . '/../database/schema.sql';
        $seedFile = __DIR__ . '/../database/seed.sql';

        if (file_exists($schemaFile)) {
            $sql = file_get_contents($schemaFile);
            $db->exec($sql);
        }

        if (file_exists($seedFile)) {
            $sql = file_get_contents($seedFile);
            $db->exec($sql);
        }
    }
}
