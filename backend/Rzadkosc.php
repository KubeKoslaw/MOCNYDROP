<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;
use RuntimeException;

final class Rzadkosc
{

    private static ?array $cache = null;

    public function __construct(
        public readonly string $kod,
        public readonly string $nazwa,
        public readonly float $mnoznik,
        public readonly float $wagaDomyslna,
        public readonly string $kolor,
        public readonly int $kolejnosc,
    ) {
    }

    public static function wszystkie(?PDO $pdo = null): array
    {
        if ($pdo === null && self::$cache !== null) {
            return self::$cache;
        }

        $db = $pdo ?? Database::connection();

        $rows = $db
            ->query('SELECT kod, nazwa, mnoznik, waga_domyslna, kolor, kolejnosc FROM rzadkosci ORDER BY kolejnosc ASC')
            ->fetchAll();

        $out = [];

        foreach ($rows as $row) {
            $rzadkosc = self::zWiersza($row);

            $out[$rzadkosc->kod] = $rzadkosc;
        }

        if ($out === []) {
            throw new RuntimeException('Tabela `rzadkosci` jest pusta — zaimportuj database/schema.sql.');
        }

        if ($pdo === null) {
            self::$cache = $out;
        }

        return $out;
    }

    public static function get(string $kod, ?PDO $pdo = null): self
    {
        $all = self::wszystkie($pdo);

        if (!isset($all[$kod])) {
            throw new RuntimeException('Nieznany poziom rzadkości: ' . $kod);
        }

        return $all[$kod];
    }

    public static function exists(string $kod, ?PDO $pdo = null): bool
    {
        return isset(self::wszystkie($pdo)[$kod]);
    }

    public static function wagaDomyslna(string $kod, ?PDO $pdo = null): float
    {
        return self::get($kod, $pdo)->wagaDomyslna;
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    public function doApi(): array
    {
        return [
            'kod' => $this->kod,
            'nazwa' => $this->nazwa,
            'mnoznik' => $this->mnoznik,
            'waga_domyslna' => $this->wagaDomyslna,
            'kolor' => $this->kolor,
            'kolejnosc' => $this->kolejnosc,
        ];
    }

    public static function zWiersza(array $row): self
    {
        return new self(
            (string) $row['kod'],
            (string) $row['nazwa'],
            (float) $row['mnoznik'],
            (float) $row['waga_domyslna'],
            (string) $row['kolor'],
            (int) $row['kolejnosc'],
        );
    }
}
