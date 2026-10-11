<?php

declare(strict_types=1);

/**
 * api/inventory.php — GET /api/inventory.php
 * Nagłówek: Authorization: Bearer <token>
 *
 * Query (opcjonalne): ?z_sprzedanymi=1  — pokaż też ryby już sprzedane
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\Auth;
use MocnyDrop\InventoryService;
use MocnyDrop\Json;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('GET');

$user = Auth::requireAuth();

$includeSold = filter_var($_GET['z_sprzedanymi'] ?? '0', FILTER_VALIDATE_BOOL);

Json::success([
    'ekwipunek' => InventoryService::listCaught($user['id'], $includeSold),
    'podsumowanie' => InventoryService::summary($user['id']),
]);
