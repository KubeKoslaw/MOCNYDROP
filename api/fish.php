<?php
/**
 * api/fish.php
 * Endpoint trybu łowiska: rzut wędką (POST).
 * Zgodny z UML: Tryby Gry Hazardowej -> Rzut wędką w łowisku
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/GameEngine.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError("Metoda nieobsługiwana. Użyj metody POST.", 405);
}

$user = Auth::requireAuth();
$db = Database::getConnection();

// Koszt jednego zarzucenia wędki (przynęta i sprzęt)
$kosztWędki = 5.00;

$db->beginTransaction();

try {
    // 1. Sprawdzamy saldo gracza
    $stmtUser = $db->prepare("SELECT saldo FROM uzytkownicy WHERE id = ?");
    $stmtUser->execute([$user['id']]);
    $saldo = (float)$stmtUser->fetchColumn();

    if ($saldo < $kosztWędki) {
        throw new Exception("Brak środków na przynętę! Potrzebujesz {$kosztWędki} monet (masz: {$saldo}).");
    }

    // 2. Pobieramy opłatę
    $noweSaldo = $saldo - $kosztWędki;
    $stmtUpdate = $db->prepare("UPDATE uzytkownicy SET saldo = ? WHERE id = ?");
    $stmtUpdate->execute([$noweSaldo, $user['id']]);

    // 3. Pula ryb dostępnych w łowisku z wagami
    $stmtFish = $db->query("
        SELECT id, nazwa, rzadkosc, wartosc, ikona,
            CASE rzadkosc
                WHEN 'Zwykła' THEN 55
                WHEN 'Rzadka' THEN 30
                WHEN 'Epicka' THEN 12
                WHEN 'Legendarna' THEN 3
            END AS waga
        FROM przedmioty
    ");
    $fishPool = $stmtFish->fetchAll();

    // 4. Ważone losowanie wyłowionej ryby
    $wonFish = GameEngine::rollItem($fishPool);

    // 5. Zapisujemy wyłowioną rybę do ekwipunku
    $stmtInv = $db->prepare("INSERT INTO ekwipunek (user_id, przedmiot_id) VALUES (?, ?)");
    $stmtInv->execute([$user['id'], $wonFish['id']]);
    $inventoryId = (int)$db->lastInsertId();

    // 6. Zapis zdarzenia w historii
    $stmtHist = $db->prepare("
        INSERT INTO historia_losowan (user_id, skrzynka_id, przedmiot_id, koszt, wygrana_wartosc)
        VALUES (?, NULL, ?, ?, ?)
    ");
    $stmtHist->execute([$user['id'], $wonFish['id'], $kosztWędki, $wonFish['wartosc']]);

    $db->commit();

    jsonResponse([
        'success' => true,
        'koszt' => $kosztWędki,
        'wylowiona_ryba' => [
            'inventory_id' => $inventoryId,
            'nazwa' => $wonFish['nazwa'],
            'rzadkosc' => $wonFish['rzadkosc'],
            'wartosc' => (float)$wonFish['wartosc'],
            'ikona' => $wonFish['ikona'],
            'szansa_procent' => $wonFish['szansa_procent']
        ],
        'nowe_saldo' => $noweSaldo,
        'message' => "Złowiono: {$wonFish['nazwa']} ({$wonFish['rzadkosc']})!"
    ]);

} catch (Exception $e) {
    $db->rollBack();
    jsonError($e->getMessage(), 400);
}
