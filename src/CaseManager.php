<?php
/**
 * src/CaseManager.php
 * Zarządzanie skrzynkami oraz transakcyjne otwieranie skrzynek.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/GameEngine.php';

class CaseManager {
    /**
     * Pobiera listę wszystkich aktywnych skrzynek wraz z ich przedmiotami.
     */
    public static function getAllCases(): array {
        $db = Database::getConnection();
        
        $stmt = $db->query("SELECT * FROM skrzynki ORDER BY koszt ASC");
        $cases = $stmt->fetchAll();

        foreach ($cases as &$case) {
            $case['id'] = (int)$case['id'];
            $case['koszt'] = (float)$case['koszt'];
            $case['przedmioty'] = self::getCaseItems($case['id']);
        }

        return $cases;
    }

    /**
     * Pobiera przedmioty przypisane do danej skrzynki wraz z wagami i szansą %.
     */
    public static function getCaseItems(int $caseId): array {
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT p.id, p.nazwa, p.rzadkosc, p.wartosc, p.ikona, sp.waga
            FROM skrzynka_przedmioty sp
            JOIN przedmioty p ON p.id = sp.przedmiot_id
            WHERE sp.skrzynka_id = ?
            ORDER BY sp.waga DESC
        ");
        $stmt->execute([$caseId]);
        $items = $stmt->fetchAll();

        $totalWeight = array_sum(array_column($items, 'waga'));

        foreach ($items as &$item) {
            $item['id'] = (int)$item['id'];
            $item['wartosc'] = (float)$item['wartosc'];
            $item['waga'] = (int)$item['waga'];
            $item['szansa_procent'] = $totalWeight > 0 ? round(($item['waga'] / $totalWeight) * 100, 2) : 0;
        }

        return $items;
    }

    /**
     * Główna funkcja: Otwarcie skrzynki przez gracza.
     * Wykonywana w bezpiecznej transakcji SQLite.
     * 
     * @param int $userId Id gracza
     * @param int $caseId Id otwieranej skrzynki
     * @return array Wynik losowania, wylosowany przedmiot i nowe saldo
     */
    public static function openCase(int $userId, int $caseId): array {
        $db = Database::getConnection();

        // 1. Pobieramy skrzynkę
        $stmt = $db->prepare("SELECT * FROM skrzynki WHERE id = ?");
        $stmt->execute([$caseId]);
        $case = $stmt->fetch();
        if (!$case) {
            throw new Exception("Wybrana skrzynka nie istnieje.");
        }

        $koszt = (float)$case['koszt'];

        // 2. Pobieramy przedmioty ze skrzynki
        $items = self::getCaseItems($caseId);
        if (empty($items)) {
            throw new Exception("Ta skrzynka nie zawiera żadnych przedmiotów.");
        }

        // 3. Rozpoczynamy transakcję bazodanową ACID
        $db->beginTransaction();

        try {
            // Pobieramy aktualne saldo gracza z blokadą
            $stmtUser = $db->prepare("SELECT saldo FROM uzytkownicy WHERE id = ?");
            $stmtUser->execute([$userId]);
            $user = $stmtUser->fetch();

            if (!$user) {
                throw new Exception("Użytkownik nie istnieje.");
            }

            $saldoPrzed = (float)$user['saldo'];

            // Weryfikacja czy gracza stać na otwarcie: Saldo >= koszt
            if ($saldoPrzed < $koszt) {
                throw new Exception("Niewystarczające środki na koncie. Koszt: {$koszt} monet, Twoje saldo: {$saldoPrzed} monet.");
            }

            // 4. Pobieramy opłatę: S_po = S_przed - koszt
            $noweSaldo = $saldoPrzed - $koszt;
            $stmtUpdate = $db->prepare("UPDATE uzytkownicy SET saldo = ? WHERE id = ?");
            $stmtUpdate->execute([$noweSaldo, $userId]);

            // 5. Ważone losowanie nagrody według wag P(i) = w_i / suma(w)
            $wonItem = GameEngine::rollItem($items);

            // 6. Dodajemy wygrany przedmiot do ekwipunku gracza
            $stmtInv = $db->prepare("
                INSERT INTO ekwipunek (user_id, przedmiot_id)
                VALUES (?, ?)
            ");
            $stmtInv->execute([$userId, $wonItem['id']]);
            $inventoryId = (int)$db->lastInsertId();

            // 7. Zapisujemy wpis do historii losowań
            $stmtHist = $db->prepare("
                INSERT INTO historia_losowan (user_id, skrzynka_id, przedmiot_id, koszt, wygrana_wartosc)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmtHist->execute([$userId, $caseId, $wonItem['id'], $koszt, $wonItem['wartosc']]);

            // Zatwierdzamy transakcję
            $db->commit();

            return [
                'success' => true,
                'skrzynka' => [
                    'id' => (int)$case['id'],
                    'nazwa' => $case['nazwa'],
                    'koszt' => $koszt
                ],
                'wylosowany_przedmiot' => [
                    'inventory_id' => $inventoryId,
                    'przedmiot_id' => (int)$wonItem['id'],
                    'nazwa' => $wonItem['nazwa'],
                    'rzadkosc' => $wonItem['rzadkosc'],
                    'wartosc' => (float)$wonItem['wartosc'],
                    'ikona' => $wonItem['ikona'],
                    'szansa_procent' => $wonItem['szansa_procent']
                ],
                'saldo_przed' => $saldoPrzed,
                'saldo_po' => $noweSaldo,
                'wszystkie_przedmioty' => $items // Dla frontendu do animacji paska ruletki
            ];

        } catch (Exception $e) {
            // W razie błędu wycofujemy zmiany w bazie
            $db->rollBack();
            throw $e;
        }
    }
}
