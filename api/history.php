<?php

declare(strict_types=1);

/**
 * api/history.php — GET /api/history.php
 * Nagłówek: Authorization: Bearer <token>
 *
 * Query (opcjonalne): ?limit=20  (1–100, domyślnie 20)
 *
 * Historia połowów gracza + rozkład trafień per poziom rzadkości.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\Auth;
use MocnyDrop\HistoryRepository;
use MocnyDrop\Json;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('GET');

$user = Auth::requireAuth();

$rawLimit = $_GET['limit'] ?? '20';
$limit = is_scalar($rawLimit) && preg_match('/^\d+$/', trim((string) $rawLimit)) === 1
    ? (int) trim((string) $rawLimit)
    : 20;

Json::success([
    'historia' => HistoryRepository::recent($user['id'], $limit),
    'rozbicie_rzadkosci' => HistoryRepository::rarityBreakdown($user['id']),
]);
