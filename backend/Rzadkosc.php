<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;
use RuntimeException;

/**
 * backend/Rzadkosc.php
 *
 * Klasa Rzadkosc z diagramu klas:
 *
 *     - nazwa : string
 *     - mnoznik : float
 *
 * Asocjacja Przedmiot — Rzadkosc (*:1) jest w bazie kolumną
 * `przedmioty.rzadkosc_kod` z kluczem obcym na `rzadkosci.kod`.
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
 * `mnoznik` (atrybut z diagramu) skaluje wartość przedmiotu:
 *     Przedmiot::obliczWartosc() = wartosc * rzadkosc->mnoznik
 * Domyślnie wynosi 1.0, więc dopóki nikt go nie przestawi, ekonomia gry się
 * nie zmienia. `waga_domyslna` to osobna rzecz: waga w losowaniu P(i).
 *
 * Źródłem prawdy jest tabela `rzadkosci` — ta klasa tylko ją czyta. Zmiana
 * balansu to UPDATE w bazie, bez ruszania kodu i bez redeployu.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class Rzadkosc
{
    /** @var array<string, self>|null */
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

    /**
     * Wszystkie poziomy rzadkości, kluczowane kodem, posortowane rosnąco.
     *
     * @return array<string, self>
     */
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

    /** Waga domyślna poziomu — używana, gdy pula wędki nie podaje własnej wagi. */
    public static function wagaDomyslna(string $kod, ?PDO $pdo = null): float
    {
        return self::get($kod, $pdo)->wagaDomyslna;
    }

    /** Czyści cache — potrzebne w testach, gdy każda testowa baza jest inna. */
    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /**
     * Kształt zwracany przez API (`rzadkosci` w odpowiedzi api/wedki.php),
     * żeby frontend brał kolory i nazwy z bazy zamiast hardkodować je w CSS.
     *
     * @return array<string, mixed>
     */
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

    /** @param array<string, mixed> $row */
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
