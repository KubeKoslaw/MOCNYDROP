<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\Settings;
use MocnyDrop\Wedka;
use PDO;
use RuntimeException;

/**
 * Fabryki danych dla testów. Każdy helper insertuje minimalny, poprawny
 * zestaw wierszy i zwraca id (albo tablicę z `id`).
 */
final class Fixtures
{
    /**
     * Pula z README.md — po jednej rybie na poziom rzadkości.
     * Wartości/ikony muszą odpowiadać tabeli z dokumentacji (wagi 50/30/15/4/1/0.001 = 100.001).
     */
    public const PULA_README = [
        ['nazwa' => 'Anchois', 'kod' => 'pospolita', 'wartosc' => 2.0, 'ikona' => 'anchovy.png'],
        ['nazwa' => 'Pstrąg Potokowy', 'kod' => 'przecietna', 'wartosc' => 25.0, 'ikona' => 'brown_trout.png'],
        ['nazwa' => 'Bass Małogębowy', 'kod' => 'niezla', 'wartosc' => 60.0, 'ikona' => 'smallmouth_bass.png'],
        ['nazwa' => 'Marlin Błękitny', 'kod' => 'mityczna', 'wartosc' => 500.0, 'ikona' => 'blue_marlin.png'],
        ['nazwa' => 'Jesiotr', 'kod' => 'niesamowita', 'wartosc' => 3000.0, 'ikona' => 'sturgeon.png'],
        ['nazwa' => 'Ryba Otchłani', 'kod' => 'ultra', 'wartosc' => 25000.0, 'ikona' => 'void_fish.png'],
    ];

    /**
     * Konto z portfelem o dokładnie podanym saldzie (bez logiki rejestracji).
     *
     * @return array{id: int, login: string, email: string, rola: string}
     */
    public static function user(PDO $db, string $login, float $saldo, string $rola = 'user'): array
    {
        $email = $login . '@test.local';

        $db->prepare('INSERT INTO uzytkownicy (login, email, haslo_hash, rola) VALUES (?, ?, ?, ?)')
            ->execute([$login, $email, password_hash('haslo1234', PASSWORD_DEFAULT), $rola]);

        $id = (int) $db->lastInsertId();

        $db->prepare('INSERT INTO portfele (user_id, saldo, waluta) VALUES (?, ?, ?)')
            ->execute([$id, $saldo, Settings::get(Settings::KEY_CURRENCY, 'monety', $db)]);

        return ['id' => $id, 'login' => $login, 'email' => $email, 'rola' => $rola];
    }

    public static function setting(PDO $db, string $klucz, string $wartosc): void
    {
        $db->prepare(
            'INSERT INTO ustawienia (klucz, wartosc) VALUES (?, ?)
             ON CONFLICT (klucz) DO UPDATE SET wartosc = excluded.wartosc'
        )->execute([$klucz, $wartosc]);
    }

    /** Wędka bez własnego przedmiotu (testy tworzą go jawnie albo robi to {@see self::dajWedke()}). */
    public static function wedka(
        PDO $db,
        string $nazwa,
        float $kosztUzycia,
        int $kolejnosc = 0,
        int $limitZarzutow = 10
    ): int {
        $db->prepare(
            'INSERT INTO wedki (nazwa, opis, koszt_uzycia, ikona, czy_aktywna, kolejnosc, limit_zarzutow)
             VALUES (?, ?, ?, ?, 1, ?, ?)'
        )->execute([$nazwa, '', $kosztUzycia, 'rod_bambusowa.svg', $kolejnosc, $limitZarzutow]);

        return (int) $db->lastInsertId();
    }

    /** Wędka z pulą z README (wagi rzadkości domyślne). */
    public static function readmeWedka(PDO $db, float $kosztUzycia): int
    {
        $wedkaId = self::wedka($db, 'Wędka README', $kosztUzycia);

        foreach (self::PULA_README as $ryba) {
            self::przedmiot($db, $wedkaId, $ryba['nazwa'], $ryba['kod'], $ryba['wartosc'], null, $ryba['ikona']);
        }

        return $wedkaId;
    }

    /** Przedmiot w puli wędki; `null` wagi = `rzadkosci.waga_domyslna`. */
    public static function przedmiot(
        PDO $db,
        int $wedkaId,
        string $nazwa,
        string $rzadkoscKod,
        float $wartosc,
        ?float $waga = null,
        ?string $ikona = null
    ): int {
        $przedmiotId = self::wstawPrzedmiot($db, $nazwa, $rzadkoscKod, $wartosc, $ikona);

        $db->prepare('INSERT INTO wedka_przedmioty (wedka_id, przedmiot_id, waga) VALUES (?, ?, ?)')
            ->execute([$wedkaId, $przedmiotId, $waga]);

        return $przedmiotId;
    }

    /**
     * Wpis w ekwipunku gracza dla danej wędki (tworzy przedmiot wędki, jeśli brak).
     * Domyślnie jeden zarzut — po zarzucie wędka znika z ekwipunku.
     *
     * @return int id wpisu w `ekwipunek`
     */
    public static function dajWedke(PDO $db, int $userId, int $wedkaId, ?int $zarzuty = null): int
    {
        $stmt = $db->prepare('SELECT * FROM wedki WHERE id = ?');
        $stmt->execute([$wedkaId]);
        $wedka = $stmt->fetch();

        if ($wedka === false) {
            throw new RuntimeException('Brak wędki o id ' . $wedkaId . ' — najpierw Fixtures::wedka().');
        }

        $przedmiotId = $wedka['przedmiot_id'] === null ? null : (int) $wedka['przedmiot_id'];

        if ($przedmiotId === null) {
            $przedmiotId = Wedka::wymagaj($wedkaId, $db)->utworzPrzedmiot('pospolita', $db);
        }

        $zarzuty ??= 1;
        $wartosc = round((float) $wedka['koszt_uzycia'] * $zarzuty, 2);

        $db->prepare('INSERT INTO ekwipunek (user_id, przedmiot_id, wartosc, zarzuty_pozostale) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $przedmiotId, $wartosc, $zarzuty]);

        return (int) $db->lastInsertId();
    }

    public static function skrzynka(PDO $db, string $nazwa, float $cena, bool $aktywna = true, int $kolejnosc = 0): int
    {
        $db->prepare(
            'INSERT INTO skrzynki (nazwa, opis, cena, ikona, aktywna, kolejnosc) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$nazwa, '', $cena, 'case.png', $aktywna ? 1 : 0, $kolejnosc]);

        return (int) $db->lastInsertId();
    }

    /** Skrzynka z pulą z README (wagi rzadkości domyślne). */
    public static function readmeSkrzynka(PDO $db, float $cena): int
    {
        $skrzynkaId = self::skrzynka($db, 'Skrzynka README', $cena);

        foreach (self::PULA_README as $ryba) {
            self::przedmiotSkrzynki($db, $skrzynkaId, $ryba['nazwa'], $ryba['kod'], $ryba['wartosc'], null);
        }

        return $skrzynkaId;
    }

    public static function przedmiotSkrzynki(
        PDO $db,
        int $skrzynkaId,
        string $nazwa,
        string $rzadkoscKod,
        float $wartosc,
        ?float $waga = null
    ): int {
        $przedmiotId = self::wstawPrzedmiot($db, $nazwa, $rzadkoscKod, $wartosc, null);

        $db->prepare('INSERT INTO skrzynka_przedmioty (skrzynka_id, przedmiot_id, waga) VALUES (?, ?, ?)')
            ->execute([$skrzynkaId, $przedmiotId, $waga]);

        return $przedmiotId;
    }

    /** Deterministyczny `random`: zwraca kolejne wartości, potem ostatnią w kółko. */
    public static function scriptedRandom(array $values): callable
    {
        if ($values === []) {
            throw new RuntimeException('Fixtures::scriptedRandom() wymaga co najmniej jednej wartości.');
        }

        $queue = array_values($values);

        return static function () use (&$queue): float {
            $value = count($queue) > 1 ? array_shift($queue) : $queue[0];

            return (float) $value;
        };
    }

    private static function wstawPrzedmiot(PDO $db, string $nazwa, string $rzadkoscKod, float $wartosc, ?string $ikona): int
    {
        $db->prepare(
            'INSERT INTO przedmioty (nazwa, rzadkosc_kod, wartosc, ikona, opis, czy_aktywny) VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$nazwa, $rzadkoscKod, $wartosc, $ikona ?? '', '']);

        return (int) $db->lastInsertId();
    }
}
