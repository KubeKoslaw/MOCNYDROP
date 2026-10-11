<?php

declare(strict_types=1);

/**
 * api/me.php — GET /api/me.php
 * Nagłówek: Authorization: Bearer <token>
 *
 * Zwraca dane gracza, saldo i podsumowanie ekwipunku.
 * To jest endpoint, którym frontend odświeża pasek stanu konta.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\Auth;
use MocnyDrop\InventoryService;
use MocnyDrop\Json;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('GET');

$user = Auth::requireAuth();

Json::success([
    'user' => Auth::toPublic($user),
    'saldo' => $user['saldo'],
    'ekwipunek' => InventoryService::summary($user['id']),
]);
