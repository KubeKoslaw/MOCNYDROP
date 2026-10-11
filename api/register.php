<?php

declare(strict_types=1);

/**
 * api/register.php — POST /api/register.php
 *
 * Body: { "login": "gracz", "email": "gracz@poczta.pl", "password": "haslo1234" }
 *
 * Rejestracja to Uzytkownik::zarejestruj() z diagramu klas: zakłada konto
 * i od razu Portfel z saldem startowym (kompozycja 1:1 z diagramu oraz
 * «Przyznanie bonusu powitalnego (50 monet)» z diagramu use case).
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\Json;
use MocnyDrop\Uzytkownik;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('POST');

$body = Json::readBody();

// Hasła celowo NIE przepuszczamy przez stringField(), bo ten robi trim() —
// spacja na końcu hasła jest jego częścią i nie mamy prawa jej obcinać.
$uzytkownik = Uzytkownik::utworz(
    Json::stringField($body, 'login'),
    Json::stringField($body, 'email'),
    (string) ($body['password'] ?? '')
);

$uzytkownik->zarejestruj();

Json::success([
    'user' => $uzytkownik->doPublicznej(),
    'message' => 'Rejestracja udana! Możesz się teraz zalogować.',
], 201);
