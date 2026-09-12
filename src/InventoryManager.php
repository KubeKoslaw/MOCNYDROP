<?php
/**
 * src/InventoryManager.php
 * Zarządzanie ekwipunkiem gracza i sprzedażą przedmiotów za walutę.
 */

require_once __DIR__ . '/Database.php';

class InventoryManager {
    /**
     * Pobiera aktywne przedmioty z ekwipunku gracza.
     */
    public static function getUserInventory(int $userId): array {
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT e.id AS inventory_id, e.zdobyto_kiedy,
                   p.id AS przedmiot_id, p.nazwa, p.rzadkosc, p.wartosc, p.ikona
            FROM ekwipunek e
            JOIN przedmioty p ON p.id = e.przedmiot_id
            WHERE e.user_id = ? AND e.czy_sprzedany = 0
            ORDER BY e.id DESC
        ");
        $stmt->execute([$userId]);
        $items = $stmt->fetchAll();

        foreach ($items as &$item) {
            $item['inventory_id'] = (int)$item['inventory_id'];
            $item['przedmiot_id'] = (int)$item['przedmiot_id'];
            $item['wartosc'] = (float)$item['wartosc'];
        }

        return $items;
    }

    /**
     * Sprzedaż przedmiotu z ekwipunku:
     * Dodaje wartość przedmiotu v_i do salda gracza (S_po = S_przed + v_i)
     * i oznacza przedmiot jako sprzedany.
     */
    public static function sellItem(int $userId, int $inventoryId): array {
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            // 1. Sprawdzamy czy przedmiot należy do tego gracza i nie jest sprzedany
            $stmt = $db->prepare("
                SELECT e.id, e.czy_sprzedany, p.nazwa, p.wartosc
                FROM ekwipunek e
                JOIN przedmioty p ON p.id = e.przedmiot_id
                WHERE e.id = ? AND e.user_id = ?
            ");
            $stmt->execute([$inventoryId, $userId]);
            $item = $stmt->fetch();

            if (!$item) {
                throw new Exception("Przedmiot nie istnieje lub nie należy do Ciebie.");
            }

            if ($item['czy_sprzedany'] == 1) {
                throw new Exception("Ten przedmiot został już wcześniej sprzedany.");
            }

            $wartosc = (float)$item['wartosc'];

            // 2. Oznaczamy przedmiot jako sprzedany
            $stmtUpdateInv = $db->prepare("UPDATE ekwipunek SET czy_sprzedany = 1 WHERE id = ?");
            $stmtUpdateInv->execute([$inventoryId]);

            // 3. Dodajemy monety do salda gracza
            $stmtUpdateUser = $db->prepare("UPDATE uzytkownicy SET saldo = saldo + ? WHERE id = ?");
            $stmtUpdateUser->execute([$wartosc, $userId]);

            // 4. Pobieramy nowe saldo
            $stmtUser = $db->prepare("SELECT saldo FROM uzytkownicy WHERE id = ?");
            $stmtUser->execute([$userId]);
            $user = $stmtUser->fetch();
            $noweSaldo = (float)$user['saldo'];

            $db->commit();

            return [
                'success' => true,
                'sprzedany_przedmiot' => $item['nazwa'],
                'otrzymane_monety' => $wartosc,
                'nowe_saldo' => $noweSaldo,
                'message' => "Pomyślnie sprzedano '{$item['nazwa']}' za {$wartosc} monet!"
            ];

        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
