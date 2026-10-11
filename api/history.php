<?php

declare(strict_types=1);

/**
 * api/history.php — GET /api/history.php
 * Nagłówek: Authorization: Bearer <token>
 *
 * Query (opcjonalne): ?limit=20  (1–100, domyślnie 20)
 *
 * Historia losowań gracza (asocjacja Uzytkownik — Losowanie «historia» z
 * diagramu klas) + rozkład trafień per poziom rzadkości.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\Json;
use MocnyDrop\Losowanie;
use MocnyDrop\Token;
use MocnyDrop\TypLosowania;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('GET');

$uzytkownik = Token::wymagajZalogowania();

$rawLimit = $_GET['limit'] ?? '20';
$limit = is_scalar($rawLimit) && preg_match('/^\d+$/', trim((string) $rawLimit)) === 1
    ? (int) trim((string) $rawLimit)
    : 20;

Json::success([
    'historia' => Losowanie::historia($uzytkownik->id, $limit),
    'rozbicie_rzadkosci' => Losowanie::rozbicieRzadkosci($uzytkownik->id),
    'rtp' => Losowanie::rtpDlaTypu(TypLosowania::Wedka),
]);
