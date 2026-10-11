<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\Rarity;

/**
 * tests/RarityTest.php
 *
 * Testy słownika rzadkości.
 *
 * To jest regresja na konkretny błąd z porzuconego brancha PHP+SQLite:
 * kolumna wagi była tam INTEGER, przez co najrzadszy poziom (waga 0.001)
 * zapisywał się jako 0 i ryba stawała się nieosiągalna. README.md wymaga
 * sześciu poziomów z wagą ułamkową.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class RarityTest extends TestCase
{
    /** Wagi przepisane 1:1 z tabeli w README.md. */
    private const WAGI_README = [
        'pospolita' => 50.0,
        'przecietna' => 30.0,
        'niezla' => 15.0,
        'mityczna' => 4.0,
        'niesamowita' => 1.0,
        'ultra' => 0.001,
    ];

    public function testSchematZawieraSzescPoziomowZReadme(): void
    {
        $all = Rarity::all($this->db);

        $this->assertCount(6, $all, 'README.md definiuje dokładnie 6 poziomów rzadkości');
        $this->assertSame(array_keys(self::WAGI_README), array_keys($all), 'Kody poziomów i ich kolejność');
    }

    public function testWagiSaZgodneZReadme(): void
    {
        foreach (self::WAGI_README as $kod => $waga) {
            $this->assertFloat($waga, Rarity::defaultWeight($kod, $this->db), 'Waga poziomu ' . $kod);
        }
    }

    /**
     * Najważniejsza regresja: waga 0.001 musi przeżyć zapis do bazy.
     * Przy kolumnie INTEGER byłoby tu 0.0 i test by to złapał.
     */
    public function testWagaUltraRzadkiejNieJestZaokraglanaDoZera(): void
    {
        $waga = Rarity::defaultWeight('ultra', $this->db);

        $this->assertTrue($waga > 0.0, 'Waga poziomu ULTRA musi być dodatnia — 0 oznaczałoby utratę ryby z gry');
        $this->assertFloat(0.001, $waga, 'Waga poziomu ULTRA musi być ułamkowa');
        $this->assertTrue($waga < 1.0, 'Waga ULTRA musi być mniejsza od wagi poziomu Niesamowita');
    }

    public function testSumaWagZReadme(): void
    {
        $suma = array_sum(array_map(
            static fn (array $r): float => (float) $r['waga_domyslna'],
            Rarity::all($this->db)
        ));

        $this->assertFloat(100.001, $suma, 'Suma wag domyślnych — tyle ile w tabeli README.md');
    }

    public function testKolejnoscJestRosnaca(): void
    {
        // array_values(): Rarity::all() jest kluczowane kodem poziomu, a array_map
        // zachowuje klucze. sort() natomiast numeruje od zera — bez array_values()
        // assertSame() porównywałoby [pospolita=>1, ...] z [0=>1, ...] i test
        // oblewałby się mimo poprawnej kolejności.
        $kolejnosci = array_values(array_map(
            static fn (array $r): int => (int) $r['kolejnosc'],
            Rarity::all($this->db)
        ));

        $posortowane = $kolejnosci;
        sort($posortowane);

        $this->assertSame($posortowane, $kolejnosci, 'Poziomy muszą być zwracane od najczęstszego do najrzadszego');
        $this->assertSame([1, 2, 3, 4, 5, 6], $kolejnosci, 'Numery kolejności poziomów');
    }

    /** Frontend bierze kolory z API, więc muszą być w poprawnym formacie CSS. */
    public function testKolorySaWFormacieHex(): void
    {
        foreach (Rarity::all($this->db) as $kod => $rzadkosc) {
            $this->assertTrue(
                preg_match('/^#[0-9a-fA-F]{6}$/', (string) $rzadkosc['kolor']) === 1,
                'Kolor poziomu ' . $kod . ' musi być w formacie #RRGGBB, jest: ' . $rzadkosc['kolor']
            );
        }
    }

    public function testNazwyPoziomowSaZgodneZReadme(): void
    {
        $this->assertSame('Pospolita', Rarity::label('pospolita', $this->db), 'Nazwa poziomu 1');
        $this->assertSame('ULTRA MEGA NIESAMOWITA', Rarity::label('ultra', $this->db), 'Nazwa poziomu 6');
    }

    public function testNieznanyPoziomRzucaWyjatek(): void
    {
        $this->assertFalse(Rarity::exists('legendarna', $this->db), 'Poziom "Legendarna" z porzuconego brancha nie istnieje w aktualnym schemacie');

        $this->assertThrows(
            \RuntimeException::class,
            fn (): string => Rarity::label('nie_ma_takiego', $this->db),
            'Nieznany kod rzadkości powinien rzucić wyjątek'
        );
    }

    /** Cache musi być czyszczony między testami, inaczej czytałyby starą bazę. */
    public function testCacheNiePrzeciekaMiedzyBazami(): void
    {
        $przed = Rarity::all($this->db);

        Rarity::clearCache();

        $this->assertSame($przed, Rarity::all($this->db), 'Po wyczyszczeniu cache wynik musi być identyczny');
    }
}
