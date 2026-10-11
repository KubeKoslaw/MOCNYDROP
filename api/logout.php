<?php

declare(strict_types=1);

/**
 * api/logout.php — POST /api/logout.php
 * Nagłówek: Authorization: Bearer <token>
 *
 * Kasuje token z bazy, więc nie da się go użyć ponownie nawet jeśli
 * ktoś go wcześniej skopiował.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\Auth;
use MocnyDrop\Json;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('POST');

$token = Auth::bearerToken();

if ($token !== null) {
    Auth::logout($token);
}

// Brak tokena też uznajemy za sukces: stan końcowy jest ten sam —
// klient nie ma ważnej sesji. Frontend nie musi obsługiwać tu błędu.
Json::success(['message' => 'Wylogowano.']);
