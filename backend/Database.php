<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        return self::$pdo ??= self::openFile();
    }

    public static function setConnection(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function openMemory(): PDO
    {
        $pdo = self::configure(new PDO('sqlite::memory:'));

        self::applySchema($pdo);

        return $pdo;
    }

    public static function insertIfAbsent(PDO $pdo, string $insert, string $key = 'id'): string
    {
        self::validateIdentifier($key);

        return $insert . ' ON CONFLICT DO NOTHING';
    }

    public static function upsert(PDO $pdo, string $insert, string $key, string $column): string
    {
        self::validateIdentifier($key);
        self::validateIdentifier($column);

        return $insert . sprintf(' ON CONFLICT (`%s`) DO UPDATE SET `%s` = excluded.`%s`', $key, $column, $column);
    }

    private static function validateIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-z_]+$/D', $identifier)) {
            throw new RuntimeException('Nieprawidłowy identyfikator SQL.');
        }
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

        $pdo->exec('PRAGMA journal_mode = WAL;');

        if ($isNew) {
            self::applySchema($pdo);
        } else {
            self::uzupelnijKolumny($pdo);
        }

        return $pdo;
    }

    private static function configure(PDO $pdo): PDO
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('PRAGMA foreign_keys = ON;');

        return $pdo;
    }

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

        $sql = preg_replace('/^--.*$/m', '', $sql);

        foreach (explode(';', $sql ?? '') as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
    }

    private static function uzupelnijKolumny(PDO $pdo): void
    {
        $brakujace = [
            'wedki' => [
                'limit_zarzutow' => 'INTEGER NOT NULL DEFAULT 10',
                'przedmiot_id' => 'INTEGER REFERENCES przedmioty (id) ON DELETE SET NULL',
            ],
            'ekwipunek' => [
                'zarzuty_pozostale' => 'INTEGER',
            ],
        ];

        foreach ($brakujace as $tabela => $kolumny) {
            $istniejace = array_column($pdo->query('PRAGMA table_info(' . $tabela . ')')->fetchAll(), 'name');

            if ($istniejace === []) {
                continue;
            }

            foreach ($kolumny as $kolumna => $definicja) {
                if (!in_array($kolumna, $istniejace, true)) {
                    $pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $tabela, $kolumna, $definicja));
                }
            }
        }
    }
}
