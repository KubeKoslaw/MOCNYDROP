<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;

/**
 * backend/FishingSpotRepository.php
 *
 * Łowiska i ich pule ryb.
 *
 * Łowisko jest tu odpowiednikiem "skrzynki": ma koszt zarzutu i własną listę
 * ryb z wagami. Ta sama struktura obsłuży potem tryb skrzynek — wystarczy
 * inna tabela łącząca, reszta mechaniki się nie zmienia.
 *
 * Wagi biorą się z COALESCE(lr.waga, z.waga_domyslna):
 * @moxmar26 może w seedzie albo podać wagę jawnie per łowisko (np. "na Głębi
 * Mityczna ma 8 zamiast 4"), albo zostawić NULL i odziedziczyć wagę poziomu
 * rzadkości z README.md.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class FishingSpotRepository
{
    /** Wszystkie aktywne łowiska wraz z pulami ryb — na start strony łowienia. */
    public static function listActive(?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        $rows = $db
            ->query('SELECT * FROM lowiska WHERE czy_aktywne = 1 ORDER BY kolejnosc ASC, koszt_zarzutu ASC, id ASC')
            ->fetchAll();

        $out = [];

        foreach ($rows as $row) {
            $out[] = self::hydrate($row, $db);
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function find(int $spotId, ?PDO $pdo = null): ?array
    {
        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare('SELECT * FROM lowiska WHERE id = ?');
        $stmt->execute([$spotId]);

        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row, $db);
    }

    /** Jak find(), ale rzuca 404 zamiast zwracać null. */
    public static function require(int $spotId, ?PDO $pdo = null): array
    {
        $spot = self::find($spotId, $pdo);

        if ($spot === null) {
            throw ApiException::notFound('Nie ma takiego łowiska.', 'spot_not_found');
        }

        return $spot;
    }

    /**
     * Pula ryb łowiska z wagami i szansami procentowymi.
     * To jest dokładnie ta lista, którą dostaje WeightedRoller::roll().
     *
     * @return list<array<string, mixed>>
     */
    public static function pool(int $spotId, ?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare(
            'SELECT r.id AS ryba_id,
                    r.nazwa,
                    r.rzadkosc_kod,
                    r.wartosc,
                    r.ikona,
                    r.opis,
                    z.nazwa  AS rzadkosc_nazwa,
                    z.kolor  AS kolor,
                    z.kolejnosc AS rzadkosc_kolejnosc,
                    COALESCE(lr.waga, z.waga_domyslna) AS waga
             FROM lowisko_ryby lr
             JOIN ryby r      ON r.id = lr.ryba_id
             JOIN rzadkosci z ON z.kod = r.rzadkosc_kod
             WHERE lr.lowisko_id = ? AND r.czy_aktywna = 1
             ORDER BY z.kolejnosc ASC, r.wartosc ASC, r.id ASC'
        );
        $stmt->execute([$spotId]);

        $items = [];

        foreach ($stmt->fetchAll() as $row) {
            $ikona = (string) $row['ikona'];

            $items[] = [
                'ryba_id' => (int) $row['ryba_id'],
                'nazwa' => (string) $row['nazwa'],
                'rzadkosc_kod' => (string) $row['rzadkosc_kod'],
                'rzadkosc_nazwa' => (string) $row['rzadkosc_nazwa'],
                'rzadkosc_kolejnosc' => (int) $row['rzadkosc_kolejnosc'],
                'kolor' => (string) $row['kolor'],
                'wartosc' => (float) $row['wartosc'],
                'ikona' => $ikona,
                'ikona_url' => Config::iconUrl($ikona),
                'ikona_istnieje' => Config::iconExists($ikona),
                'opis' => (string) $row['opis'],
                'waga' => (float) $row['waga'],
            ];
        }

        // Szanse liczymy raz dla całej puli — taniej i spójniej niż per element.
        $chances = WeightedRoller::chances($items);

        foreach ($items as $i => $item) {
            $items[$i]['szansa_procent'] = $chances[$i];
        }

        return $items;
    }

    /** Łowisko w kształcie, jaki zwraca API. */
    public static function toApi(array $spot): array
    {
        return [
            'id' => $spot['id'],
            'nazwa' => $spot['nazwa'],
            'opis' => $spot['opis'],
            'koszt_zarzutu' => $spot['koszt_zarzutu'],
            'ikona' => $spot['ikona_url'],
            'liczba_ryb' => count($spot['ryby']),
            'ryby' => array_map(static fn (array $fish): array => [
                'ryba_id' => $fish['ryba_id'],
                'nazwa' => $fish['nazwa'],
                'rzadkosc' => $fish['rzadkosc_nazwa'],
                'rzadkosc_kod' => $fish['rzadkosc_kod'],
                'kolor' => $fish['kolor'],
                'wartosc' => $fish['wartosc'],
                'ikona' => $fish['ikona_url'],
                'waga' => $fish['waga'],
                'szansa_procent' => $fish['szansa_procent'],
            ], $spot['ryby']),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row, PDO $db): array
    {
        $id = (int) $row['id'];
        $ikona = (string) $row['ikona'];

        return [
            'id' => $id,
            'nazwa' => (string) $row['nazwa'],
            'opis' => (string) $row['opis'],
            'koszt_zarzutu' => (float) $row['koszt_zarzutu'],
            'ikona' => $ikona,
            'ikona_url' => Config::iconUrl($ikona),
            'czy_aktywne' => (int) $row['czy_aktywne'] === 1,
            'ryby' => self::pool($id, $db),
        ];
    }
}
