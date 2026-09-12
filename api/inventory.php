<?php
/**
 * api/inventory.php
 * Endpoint pobierający ekwipunek zalogowanego gracza (GET).
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/InventoryManager.php';

$user = Auth::requireAuth();

try {
    $items = InventoryManager::getUserInventory($user['id']);
    jsonResponse([
        'user_id' => $user['id'],
        'inventory' => $items
    ]);
} catch (Exception $e) {
    jsonError($e->getMessage(), 500);
}
