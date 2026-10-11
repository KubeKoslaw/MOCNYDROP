<?php

declare(strict_types=1);

/**
 * api/register.php — POST /api/register.php
 *
 * Body: { "login": "gracz", "email": "gracz@poczta.pl", "password": "haslo1234" }
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\Auth;
use MocnyDrop\Json;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('POST');

$body = Json::readBody();

// Hasła celowo NIE przepuszczamy przez stringField(), bo ten robi trim() —
// spacja na końcu hasła jest jego częścią i nie mamy prawa jej obcinać.
$result = Auth::register(
    Json::stringField($body, 'login'),
    Json::stringField($body, 'email'),
    (string) ($body['password'] ?? '')
);

Json::success([
    'user' => Auth::toPublic($result),
    'message' => 'Rejestracja udana! Możesz się teraz zalogować.',
], 201);
