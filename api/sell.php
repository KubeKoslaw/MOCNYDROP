<?php

declare(strict_types=1);

/**
 * api/sell.php — POST /api/sell.php
 * Nagłówek: Authorization: Bearer <token>
 * Body: { "ekwipunek_id": 12 }
 *
 * Sprzedaje rybę i dopisuje monety do salda.
 *
 * Uwaga na `ekwipunek_id`: to id WPISU w ekwipunku, nie id ryby z katalogu.
 * Gracz może mieć trzy takie same ryby i każda to osobny wpis.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\Auth;
use MocnyDrop\InventoryService;
use MocnyDrop\Json;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('POST');

$user = Auth::requireAuth();
$body = Json::readBody();

$inventoryId = Json::intField($body, 'ekwipunek_id');

Json::success(InventoryService::sell($user['id'], $inventoryId));
