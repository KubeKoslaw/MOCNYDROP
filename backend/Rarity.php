<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;
use RuntimeException;

/**
 * backend/Rarity.php
 *
 * Sześć poziomów rzadkości z README.md:
 *
 *   | Rzadkość                  | Waga  | Szansa    |
 *   |---------------------------|-------|-----------|
 *   | Pospolita                 | 50    | 50%       |
 *   | Przeciętna                | 30    | 30%       |
 *   | Niezła                    | 15    | 15%       |
 *   | Mityczna                  | 4     | 4%        |
 *   | Niesamowita               | 1     | 1%        |
 *   | ULTRA MEGA NIESAMOWITA    | 0.001 | 0.001%    |
 *
 * Źródłem prawdy jest tabela `rzadkosci` w bazie — ta klasa tylko ją czyta.
 * Zmiana balansu to UPDATE w bazie, bez ruszania kodu i bez redeployu.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class Rarity
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cache = null;

    /**
     * Wszystkie poziomy rzadkości, kluczowane kodem, posortowane rosnąco.
     *
     * @return array<string, array{kod: string, nazwa: string, waga_domyslna: float, kolor: string, kolejnosc: int}>
     */
    public static function all(?PDO $pdo = null): array
    {
        if ($pdo === null && self::$cache !== null) {
            return self::$cache;
        }

        $db = $pdo ?? Database::connection();

        $rows = $db
            ->query('SELECT kod, nazwa, waga_domyslna, kolor, kolejnosc FROM rzadkosci ORDER BY kolejnosc ASC')
            ->fetchAll();

        $out = [];

        foreach ($rows as $row) {
            $kod = (string) $row['kod'];

            $out[$kod] = [
                'kod' => $kod,
                'nazwa' => (string) $row['nazwa'],
                'waga_domyslna' => (float) $row['waga_domyslna'],
                'kolor' => (string) $row['kolor'],
                'kolejnosc' => (int) $row['kolejnosc'],
            ];
        }

        if ($out === []) {
            throw new RuntimeException('Tabela `rzadkosci` jest pusta — zaimportuj database/schema.sql.');
        }

        if ($pdo === null) {
            self::$cache = $out;
        }

        return $out;
    }

    /** @return array{kod: string, nazwa: string, waga_domyslna: float, kolor: string, kolejnosc: int} */
    public static function get(string $kod, ?PDO $pdo = null): array
    {
        $all = self::all($pdo);

        if (!isset($all[$kod])) {
            throw new RuntimeException('Nieznany poziom rzadkości: ' . $kod);
        }

        return $all[$kod];
    }

    public static function exists(string $kod, ?PDO $pdo = null): bool
    {
        return isset(self::all($pdo)[$kod]);
    }

    public static function defaultWeight(string $kod, ?PDO $pdo = null): float
    {
        return self::get($kod, $pdo)['waga_domyslna'];
    }

    public static function label(string $kod, ?PDO $pdo = null): string
    {
        return self::get($kod, $pdo)['nazwa'];
    }

    public static function color(string $kod, ?PDO $pdo = null): string
    {
        return self::get($kod, $pdo)['kolor'];
    }

    /** Czyści cache — potrzebne w testach, gdy każda testowa baza jest inna. */
    public static function clearCache(): void
    {
        self::$cache = null;
    }
}
