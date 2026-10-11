<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;
use Throwable;

/**
 * backend/InventoryService.php
 *
 * Ekwipunek: lista złowionych ryb i ich sprzedaż.
 *
 * Sprzedaż jest w transakcji i — tak samo jak pobranie opłaty w Wallet —
 * używa UPDATE z warunkiem `czy_sprzedana = 0`. Dzięki temu dwukrotne
 * kliknięcie "Sprzedaj" (albo dwa równoległe żądania) nie wypłaci monet
 * za tę samą rybę dwa razy.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class InventoryService
{
    /**
     * Złowione ryby gracza.
     *
     * @return list<array<string, mixed>>
     */
    public static function listCaught(int $userId, bool $includeSold = false, ?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        $sql = 'SELECT e.id AS ekwipunek_id,
                       e.cena_sprzedazy,
                       e.czy_sprzedana,
                       e.zdobyto_kiedy,
                       e.sprzedano_kiedy,
                       r.id AS ryba_id,
                       r.nazwa,
                       r.rzadkosc_kod,
                       r.ikona,
                       z.nazwa AS rzadkosc_nazwa,
                       z.kolor
                FROM ekwipunek e
                JOIN ryby r      ON r.id = e.ryba_id
                JOIN rzadkosci z ON z.kod = r.rzadkosc_kod
                WHERE e.user_id = ?';

        if (!$includeSold) {
            $sql .= ' AND e.czy_sprzedana = 0';
        }

        $sql .= ' ORDER BY z.kolejnosc DESC, e.id DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute([$userId]);

        $out = [];

        foreach ($stmt->fetchAll() as $row) {
            $ikona = (string) $row['ikona'];

            $out[] = [
                'ekwipunek_id' => (int) $row['ekwipunek_id'],
                'ryba_id' => (int) $row['ryba_id'],
                'nazwa' => (string) $row['nazwa'],
                'rzadkosc' => (string) $row['rzadkosc_nazwa'],
                'rzadkosc_kod' => (string) $row['rzadkosc_kod'],
                'kolor' => (string) $row['kolor'],
                'wartosc' => (float) $row['cena_sprzedazy'],
                'ikona' => Config::iconUrl($ikona),
                'czy_sprzedana' => (int) $row['czy_sprzedana'] === 1,
                'zdobyto_kiedy' => (string) $row['zdobyto_kiedy'],
                'sprzedano_kiedy' => $row['sprzedano_kiedy'] === null ? null : (string) $row['sprzedano_kiedy'],
            ];
        }

        return $out;
    }

    /**
     * Sprzedaje rybę i dopisuje monety do salda.
     *
     * @return array<string, mixed>
     */
    public static function sell(int $userId, int $inventoryId, ?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare('SELECT * FROM ekwipunek WHERE id = ? AND user_id = ?');
        $stmt->execute([$inventoryId, $userId]);

        $row = $stmt->fetch();

        if ($row === false) {
            // Celowo nie odróżniamy "nie istnieje" od "nie twoje" — inaczej
            // można by po id wyliczyć, jakie wpisy mają inni gracze.
            throw ApiException::notFound('Nie znaleziono takiej ryby w ekwipunku.', 'item_not_found');
        }

        if ((int) $row['czy_sprzedana'] === 1) {
            throw ApiException::conflict('Ta ryba została już sprzedana.', 'already_sold');
        }

        $cena = (float) $row['cena_sprzedazy'];
        $rybaId = (int) $row['ryba_id'];

        $db->beginTransaction();

        try {
            $upd = $db->prepare("UPDATE ekwipunek SET czy_sprzedana = 1, sprzedano_kiedy = datetime('now')
                                 WHERE id = ? AND user_id = ? AND czy_sprzedana = 0");
            $upd->execute([$inventoryId, $userId]);

            // rowCount 0 = ktoś sprzedał ją między naszym SELECT a UPDATE.
            if ($upd->rowCount() === 0) {
                throw ApiException::conflict('Ta ryba została już sprzedana.', 'already_sold');
            }

            $noweSaldo = Wallet::credit($db, $userId, $cena);

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }

        $stmt = $db->prepare('SELECT nazwa FROM ryby WHERE id = ?');
        $stmt->execute([$rybaId]);
        $nazwa = (string) $stmt->fetchColumn();

        return [
            'success' => true,
            'sprzedana_ryba' => [
                'ekwipunek_id' => $inventoryId,
                'ryba_id' => $rybaId,
                'nazwa' => $nazwa,
            ],
            'otrzymane_monety' => round($cena, 2),
            'nowe_saldo' => round($noweSaldo, 2),
            'message' => sprintf('Sprzedano "%s" za %s monet.', $nazwa, ApiException::money($cena)),
        ];
    }

    /** Podsumowanie ekwipunku — do paska stanu na stronie. */
    public static function summary(int $userId, ?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare(
            'SELECT COUNT(*) AS liczba, COALESCE(SUM(cena_sprzedazy), 0) AS suma
             FROM ekwipunek
             WHERE user_id = ? AND czy_sprzedana = 0'
        );
        $stmt->execute([$userId]);

        $row = $stmt->fetch();

        return [
            'liczba_ryb' => (int) ($row['liczba'] ?? 0),
            'laczna_wartosc' => round((float) ($row['suma'] ?? 0.0), 2),
        ];
    }
}
