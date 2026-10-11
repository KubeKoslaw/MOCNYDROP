<?php

declare(strict_types=1);

/**
 * api/logout.php — POST /api/logout.php
 * Nagłówek: Authorization: Bearer <token>
 *
 * Woła Token::uniewaznij() z diagramu klas — kasuje token z bazy, więc nie da
 * się go użyć ponownie, nawet jeśli ktoś go wcześniej skopiował.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\Json;
use MocnyDrop\Token;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('POST');

$token = Token::bearerToken();

if ($token !== null) {
    $encja = Token::zBazy($token);

    if ($encja !== null) {
        $encja->uniewaznij();
    }
}

// Brak tokena też uznajemy za sukces: stan końcowy jest ten sam —
// klient nie ma ważnej sesji. Frontend nie musi obsługiwać tu błędu.
Json::success(['message' => 'Wylogowano.']);
