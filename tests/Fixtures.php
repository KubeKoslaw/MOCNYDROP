<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\Settings;
use PDO;

/**
 * tests/Fixtures.php
 *
 * Dane testowe. Testy celowo NIE zależą od database/seed/ — ten katalog
 * należy do @moxmar26 i jego zawartość będzie się zmieniać, a testy
 * backendu muszą być od tego niezależne.
 *
 * Ikony wskazują prawdziwe pliki z assets/icons/, żeby testy sprawdzały
 * też poprawność ścieżek, a nie tylko logikę.
 *
 * Nazwy fixture odzwierciedlają klasy z diagramu klas: `user()` tworzy
 * Uzytkownik wraz z Portfelem (kompozycja 1:1), `wedka()` — Wedka,
 * `przedmiot()` — Przedmiot w puli wędki.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class Fixtures
{
    /**
     * Pula odpowiadająca dokładnie tabeli wag z README.md:
     * jeden przedmiot na każdy z 6 poziomów rzadkości, wagi dziedziczone (NULL).
     *
     * suma wag = 50 + 30 + 15 + 4 + 1 + 0.001 = 100.001
     *
     * @var list<array{nazwa: string, kod: string, wartosc: float, ikona: string}>
     */
    public const PULA_README = [
        ['nazwa' => 'Anchois',            'kod' => 'pospolita',   'wartosc' => 2.0,     'ikona' => 'anchovy.png'],
        ['nazwa' => 'Pstrąg Potokowy',    'kod' => 'przecietna',  'wartosc' => 8.0,     'ikona' => 'brown_trout.png'],
        ['nazwa' => 'Bass Małogębowy',    'kod' => 'niezla',      'wartosc' => 25.0,    'ikona' => 'smallmouth_bass.png'],
        ['nazwa' => 'Marlin Błękitny',    'kod' => 'mityczna',    'wartosc' => 120.0,   'ikona' => 'blue_marlin.png'],
        ['nazwa' => 'Jesiotr',            'kod' => 'niesamowita', 'wartosc' => 400.0,   'ikona' => 'sturgeon.png'],
        ['nazwa' => 'Ryba Otchłani',      'kod' => 'ultra',       'wartosc' => 25000.0, 'ikona' => 'void_fish.png'],
    ];

    public const HASLO = 'haslo1234';

    /**
     * Tworzy gracza (Uzytkownik) razem z Portfelem o podanym saldzie.
     *
     * @return array{id: int, login: string, password: string}
     */
    public static function user(PDO $db, string $login = 'tester', float $saldo = 1000.0, string $rola = 'user'): array
    {
        $stmt = $db->prepare(
            'INSERT INTO uzytkownicy (login, email, haslo_hash, zablokowany, rola)
             VALUES (?, ?, ?, 0, ?)'
        );
        $stmt->execute([
            $login,
            strtolower($login) . '@test.local',
            password_hash(self::HASLO, PASSWORD_DEFAULT),
            $rola,
        ]);

        $userId = (int) $db->lastInsertId();

        // Kompozycja Uzytkownik ◆— Portfel (1:1): konto bez portfela nie istnieje.
        $stmt = $db->prepare('INSERT INTO portfele (user_id, saldo, waluta) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $saldo, 'monety']);

        return ['id' => $userId, 'login' => $login, 'password' => self::HASLO];
    }

    /** Wpisuje blokadę konta (atrybut `zablokowany` z diagramu). */
    public static function zablokuj(PDO $db, int $userId, bool $zablokowany = true): void
    {
        $stmt = $db->prepare('UPDATE uzytkownicy SET zablokowany = ? WHERE id = ?');
        $stmt->execute([$zablokowany ? 1 : 0, $userId]);
    }

    /** Tworzy wędkę (klasa Wedka) i zwraca jej id. */
    public static function wedka(PDO $db, string $nazwa = 'Wędka Testowa', float $koszt = 10.0, int $kolejnosc = 0): int
    {
        $stmt = $db->prepare(
            'INSERT INTO wedki (nazwa, opis, koszt_uzycia, ikona, czy_aktywna, kolejnosc)
             VALUES (?, ?, ?, ?, 1, ?)'
        );
        $stmt->execute([$nazwa, 'Wędka testowa: ' . $nazwa, $koszt, '', $kolejnosc]);

        return (int) $db->lastInsertId();
    }

    /**
     * Dodaje przedmiot do katalogu i od razu podpina go pod wędkę.
     * $waga = NULL oznacza dziedziczenie wagi domyślnej poziomu rzadkości.
     */
    public static function przedmiot(
        PDO $db,
        int $wedkaId,
        string $nazwa,
        string $kod,
        float $wartosc,
        ?float $waga = null,
        string $ikona = 'anchovy.png'
    ): int {
        $stmt = $db->prepare(
            'INSERT INTO przedmioty (nazwa, rzadkosc_kod, wartosc, ikona, opis, czy_aktywny)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([$nazwa, $kod, $wartosc, $ikona, '']);

        $przedmiotId = (int) $db->lastInsertId();

        $stmt = $db->prepare('INSERT INTO wedka_przedmioty (wedka_id, przedmiot_id, waga) VALUES (?, ?, ?)');
        $stmt->execute([$wedkaId, $przedmiotId, $waga]);

        return $przedmiotId;
    }

    /**
     * Wędka z pulą dokładnie jak w README.md.
     * Główna fixture testów rozkładu losowania.
     */
    public static function readmeWedka(PDO $db, float $koszt = 10.0): int
    {
        $wedkaId = self::wedka($db, 'Wędka README', $koszt);

        foreach (self::PULA_README as $item) {
            self::przedmiot($db, $wedkaId, $item['nazwa'], $item['kod'], $item['wartosc'], null, $item['ikona']);
        }

        return $wedkaId;
    }

    /**
     * Generator liczb losowych zwracający zaprogramowaną sekwencję.
     * Dzięki niemu testy ważonego losowania są w 100% powtarzalne.
     *
     * @param list<float> $values
     */
    public static function scriptedRandom(array $values): callable
    {
        $index = 0;
        $last = $values === [] ? 0.0 : (float) $values[count($values) - 1];

        return static function () use (&$index, $values, $last): float {
            $value = $values[$index] ?? $last;
            $index++;

            return (float) $value;
        };
    }

    /** Ustawia parametr w tabeli `ustawienia` (np. inną długość paska ruletki). */
    public static function setting(PDO $db, string $key, string $value): void
    {
        $stmt = $db->prepare(
            'INSERT INTO ustawienia (klucz, wartosc, opis) VALUES (?, ?, ?)
             ON CONFLICT(klucz) DO UPDATE SET wartosc = excluded.wartosc'
        );
        $stmt->execute([$key, $value, 'nadpisane przez test']);
    }

    public static function reelLength(PDO $db): int
    {
        return Settings::getInt(Settings::KEY_REEL_LENGTH, 60, $db);
    }
}
