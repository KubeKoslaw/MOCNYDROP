<?php
/**
 * api/upgrade.php
 * Endpoint trybu Upgradera (POST).
 * Zgodny z UML: Tryby Gry Hazardowej -> Ulepszanie w Upgraderze
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError("Metoda nieobsługiwana. Użyj metody POST.", 405);
}

$user = Auth::requireAuth();
$db = Database::getConnection();

$input = getJsonInput();
$inputInventoryId = (int)($input['input_inventory_id'] ?? 0);
$targetItemId = (int)($input['target_item_id'] ?? 0);

if ($inputInventoryId <= 0 || $targetItemId <= 0) {
    jsonError("Wymagany identyfikator ryby wkładu (input_inventory_id) oraz celu (target_item_id).", 400);
}

$db->beginTransaction();

try {
    // 1. Sprawdzamy czy ryba wkładu należy do gracza i nie jest sprzedana
    $stmtInput = $db->prepare("
        SELECT e.id, p.nazwa, p.wartosc, p.rzadkosc, p.ikona
        FROM ekwipunek e
        JOIN przedmioty p ON p.id = e.przedmiot_id
        WHERE e.id = ? AND e.user_id = ? AND e.czy_sprzedany = 0
    ");
    $stmtInput->execute([$inputInventoryId, $user['id']]);
    $inputFish = $stmtInput->fetch();

    if (!$inputFish) {
        throw new Exception("Ryba wkładu nie istnieje lub nie należy do Twojego ekwipunku.");
    }

    // 2. Pobieramy rybę celu z katalogu
    $stmtTarget = $db->prepare("SELECT id, nazwa, wartosc, rzadkosc, ikona FROM przedmioty WHERE id = ?");
    $stmtTarget->execute([$targetItemId]);
    $targetFish = $stmtTarget->fetch();

    if (!$targetFish) {
        throw new Exception("Ryba docelowa nie istnieje w katalogu.");
    }

    $wartoscWkladu = (float)$inputFish['wartosc'];
    $wartoscCelu = (float)$targetFish['wartosc'];

    if ($wartoscCelu <= $wartoscWkladu) {
        throw new Exception("Wartość ryby celu musi być większa od ryby wkładu.");
    }

    // 3. Kalkulacja szansy procentowej z marżą 5%: Szansa = (W_wkład / W_cel) * 0.95
    $marza = 0.05;
    $szansa = round(($wartoscWkladu / $wartoscCelu) * (1 - $marza) * 100, 2);
    $szansa = max(1.0, min(95.0, $szansa)); // Ograniczenie między 1% a 95%

    // 4. Trwałe usunięcie ryby wkładu (przepada w ulepszaczu)
    $stmtBurn = $db->prepare("UPDATE ekwipunek SET czy_sprzedany = 1 WHERE id = ?");
    $stmtBurn->execute([$inputInventoryId]);

    // 5. Losowanie liczby koła fortuny od 1 do 10000 (precyzja 0.01%)
    $roll = random_int(1, 10000) / 100.0;
    $wygrana = ($roll <= $szansa);

    $nowyPrzedmiotId = null;
    if ($wygrana) {
        // Dodajemy ulepszoną rybę celu do ekwipunku
        $stmtAdd = $db->prepare("INSERT INTO ekwipunek (user_id, przedmiot_id) VALUES (?, ?)");
        $stmtAdd->execute([$user['id'], $targetFish['id']]);
        $nowyPrzedmiotId = (int)$db->lastInsertId();
    }

    $db->commit();

    jsonResponse([
        'success' => true,
        'wygrana' => $wygrana,
        'szansa_procent' => $szansa,
        'wylosowany_roll' => $roll,
        'wklad' => $inputFish['nazwa'],
        'cel' => $targetFish['nazwa'],
        'nagroda' => $wygrana ? $targetFish : null,
        'message' => $wygrana 
            ? "SUKCES! Twój okaz został ulepszony do: {$targetFish['nazwa']}!"
            : "PORAŻKA! Niestety ulepszanie nie powiodło się, a wkład przepadł."
    ]);

} catch (Exception $e) {
    $db->rollBack();
    jsonError($e->getMessage(), 400);
}
