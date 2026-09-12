<?php
/**
 * api/sell_item.php
 * Endpoint natychmiastowej sprzedaży przedmiotu z ekwipunku za monety (POST).
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/InventoryManager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError("Metoda nieobsługiwana. Użyj metody POST.", 405);
}

$user = Auth::requireAuth();

$input = getJsonInput();
$inventoryId = isset($input['inventory_id']) ? (int)$input['inventory_id'] : 0;

if ($inventoryId <= 0) {
    jsonError("Brakujący lub nieprawidłowy identyfikator przedmiotu (inventory_id).", 400);
}

try {
    $result = InventoryManager::sellItem($user['id'], $inventoryId);
    jsonResponse($result, 200);
} catch (Exception $e) {
    jsonError($e->getMessage(), 400);
}
