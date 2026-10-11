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
     * Przedmiot w katalogu BEZ podpisywania go pod łowisko.
     * Potrzebny trybom, które losują z katalogu (trader) albo tylko po nim
     * chodzą (upgrader — «Wybór pożądanego przedmiotu celu z katalogu»).
     */
    public static function przedmiotKatalog(
        PDO $db,
        string $nazwa,
        string $kod,
        float $wartosc,
        string $ikona = 'anchovy.png',
        bool $aktywny = true
    ): int {
        $stmt = $db->prepare(
            'INSERT INTO przedmioty (nazwa, rzadkosc_kod, wartosc, ikona, opis, czy_aktywny)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$nazwa, $kod, $wartosc, $ikona, '', $aktywny ? 1 : 0]);

        return (int) $db->lastInsertId();
    }

    /** Tworzy skrzynkę (klasa Skrzynka) i zwraca jej id. */
    public static function skrzynka(
        PDO $db,
        string $nazwa = 'Skrzynka Testowa',
        float $cena = 20.0,
        bool $aktywna = true,
        int $kolejnosc = 0
    ): int {
        $stmt = $db->prepare(
            'INSERT INTO skrzynki (nazwa, opis, cena, ikona, aktywna, kolejnosc)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$nazwa, 'Skrzynka testowa: ' . $nazwa, $cena, '', $aktywna ? 1 : 0, $kolejnosc]);

        return (int) $db->lastInsertId();
    }

    /**
     * Dodaje przedmiot do katalogu i od razu podpina go pod skrzynkę
     * (asocjacja Skrzynka — SkrzynkaPrzedmiot z diagramu klas).
     * $waga = NULL oznacza dziedziczenie wagi domyślnej poziomu rzadkości.
     */
    public static function przedmiotSkrzynki(
        PDO $db,
        int $skrzynkaId,
        string $nazwa,
        string $kod,
        float $wartosc,
        ?float $waga = null,
        string $ikona = 'anchovy.png'
    ): int {
        $przedmiotId = self::przedmiotKatalog($db, $nazwa, $kod, $wartosc, $ikona);

        $stmt = $db->prepare('INSERT INTO skrzynka_przedmioty (skrzynka_id, przedmiot_id, waga) VALUES (?, ?, ?)');
        $stmt->execute([$skrzynkaId, $przedmiotId, $waga]);

        return $przedmiotId;
    }

    /**
     * Skrzynka z pulą dokładnie jak w README.md — odpowiednik readmeWedka().
     */
    public static function readmeSkrzynka(PDO $db, float $cena = 20.0): int
    {
        $skrzynkaId = self::skrzynka($db, 'Skrzynka README', $cena);

        foreach (self::PULA_README as $item) {
            self::przedmiotSkrzynki($db, $skrzynkaId, $item['nazwa'], $item['kod'], $item['wartosc'], null, $item['ikona']);
        }

        return $skrzynkaId;
    }

    /**
     * Wpis w ekwipunku gracza z pominięciem losowania.
     *
     * Upgrader i trader zaczynają się od przedmiotów, które gracz JUŻ ma —
     * nie ma powodu za każdym razem losować ich wędką w testach.
     */
    public static function wpisEkwipunku(PDO $db, int $userId, int $przedmiotId, ?float $wartosc = null): int
    {
        if ($wartosc === null) {
            $stmt = $db->prepare('SELECT wartosc FROM przedmioty WHERE id = ?');
            $stmt->execute([$przedmiotId]);

            $wartosc = (float) $stmt->fetchColumn();
        }

        $stmt = $db->prepare('INSERT INTO ekwipunek (user_id, przedmiot_id, wartosc) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $przedmiotId, $wartosc]);

        return (int) $db->lastInsertId();
    }

    /** Ustawia parametr mechaniki w tabeli `ustawienia` (np. marżę upgradera). */
    public static function ustawienie(PDO $db, string $klucz, string $wartosc): void
    {
        self::setting($db, $klucz, $wartosc);
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
