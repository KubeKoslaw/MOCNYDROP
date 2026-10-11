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
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class Fixtures
{
    /**
     * Pula odpowiadająca dokładnie tabeli wag z README.md:
     * jedna ryba na każdy z 6 poziomów rzadkości, wagi dziedziczone (NULL).
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

    /** Tworzy gracza z podanym saldem. @return array{id: int, login: string, password: string} */
    public static function user(PDO $db, string $login = 'tester', float $saldo = 1000.0): array
    {
        $stmt = $db->prepare(
            'INSERT INTO uzytkownicy (login, email, haslo_hash, rola, saldo)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $login,
            strtolower($login) . '@test.local',
            password_hash(self::HASLO, PASSWORD_DEFAULT),
            'user',
            $saldo,
        ]);

        return ['id' => (int) $db->lastInsertId(), 'login' => $login, 'password' => self::HASLO];
    }

    public static function spot(PDO $db, string $nazwa = 'Jezioro', float $koszt = 10.0, int $kolejnosc = 0): int
    {
        $stmt = $db->prepare(
            'INSERT INTO lowiska (nazwa, opis, koszt_zarzutu, ikona, czy_aktywne, kolejnosc)
             VALUES (?, ?, ?, ?, 1, ?)'
        );
        $stmt->execute([$nazwa, 'Łowisko testowe: ' . $nazwa, $koszt, '', $kolejnosc]);

        return (int) $db->lastInsertId();
    }

    /**
     * Dodaje rybę do katalogu i od razu podpina ją pod łowisko.
     * $waga = NULL oznacza dziedziczenie wagi domyślnej poziomu rzadkości.
     */
    public static function fish(
        PDO $db,
        int $spotId,
        string $nazwa,
        string $kod,
        float $wartosc,
        ?float $waga = null,
        string $ikona = 'anchovy.png'
    ): int {
        $stmt = $db->prepare(
            'INSERT INTO ryby (nazwa, rzadkosc_kod, wartosc, ikona, opis, czy_aktywna)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([$nazwa, $kod, $wartosc, $ikona, '']);

        $rybaId = (int) $db->lastInsertId();

        $stmt = $db->prepare('INSERT INTO lowisko_ryby (lowisko_id, ryba_id, waga) VALUES (?, ?, ?)');
        $stmt->execute([$spotId, $rybaId, $waga]);

        return $rybaId;
    }

    /**
     * Łowisko z pulą dokładnie jak w README.md.
     * Główna fixture testów rozkładu losowania.
     */
    public static function readmeSpot(PDO $db, float $koszt = 10.0): int
    {
        $spotId = self::spot($db, 'Jezioro README', $koszt);

        foreach (self::PULA_README as $fish) {
            self::fish($db, $spotId, $fish['nazwa'], $fish['kod'], $fish['wartosc'], null, $fish['ikona']);
        }

        return $spotId;
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
