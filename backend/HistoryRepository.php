<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;

/**
 * backend/HistoryRepository.php
 *
 * Historia połowów — do tabeli "ostatnie połowy" i do weryfikacji,
 * że rozkład losowań zgadza się z wagami z README.md.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class HistoryRepository
{
    /** @return list<array<string, mixed>> */
    public static function recent(int $userId, int $limit = 20, ?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        // Limit z API jest liczbą od użytkownika — tniemy zakres, żeby nikt
        // nie wysłał limit=999999 i nie wyciągnął całej tabeli jednym requestem.
        $limit = max(1, min(100, $limit));

        $stmt = $db->prepare(
            'SELECT h.id, h.koszt, h.wygrana_wartosc, h.waga_wylosowana, h.suma_wag, h.data,
                    r.id AS ryba_id, r.nazwa, r.ikona,
                    z.nazwa AS rzadkosc_nazwa, z.kolor,
                    l.nazwa AS lowisko_nazwa
             FROM historia_polowow h
             JOIN ryby r     ON r.id = h.ryba_id
             JOIN rzadkosci z ON z.kod = r.rzadkosc_kod
             JOIN lowiska l  ON l.id = h.lowisko_id
             WHERE h.user_id = ?
             ORDER BY h.id DESC
             LIMIT ?'
        );

        // LIMIT bindujemy jako integer — przy emulowanych prepare'ach SQLite
        // potrafi potraktować parametr tekstowy jako 0 i nie zwrócić nic.
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        $out = [];

        foreach ($stmt->fetchAll() as $row) {
            $sumaWag = (float) $row['suma_wag'];
            $waga = (float) $row['waga_wylosowana'];

            $out[] = [
                'id' => (int) $row['id'],
                'data' => (string) $row['data'],
                'lowisko' => (string) $row['lowisko_nazwa'],
                'ryba_id' => (int) $row['ryba_id'],
                'nazwa' => (string) $row['nazwa'],
                'rzadkosc' => (string) $row['rzadkosc_nazwa'],
                'kolor' => (string) $row['kolor'],
                'ikona' => Config::iconUrl((string) $row['ikona']),
                'koszt' => (float) $row['koszt'],
                'wygrana_wartosc' => (float) $row['wygrana_wartosc'],
                'bilans' => round((float) $row['wygrana_wartosc'] - (float) $row['koszt'], 2),
                'szansa_procent' => WeightedRoller::chancePercent($waga, $sumaWag),
            ];
        }

        return $out;
    }

    /**
     * Liczba połowów z podziałem na poziomy rzadkości.
     * Przydatne przy testach rozkładu: porównujemy z wagami z README.md.
     *
     * @return array<string, int>
     */
    public static function rarityBreakdown(int $userId, ?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare(
            'SELECT r.rzadkosc_kod AS kod, COUNT(*) AS liczba
             FROM historia_polowow h
             JOIN ryby r ON r.id = h.ryba_id
             WHERE h.user_id = ?
             GROUP BY r.rzadkosc_kod'
        );
        $stmt->execute([$userId]);

        $out = [];

        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['kod']] = (int) $row['liczba'];
        }

        return $out;
    }
}
