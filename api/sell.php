<?php

declare(strict_types=1);

/**
 * api/sell.php — POST /api/sell.php
 * Nagłówek: Authorization: Bearer <token>
 * Body: { "ekwipunek_id": 12 }
 *
 * Natychmiastowa sprzedaż wygranej (przypadek rozszerzający z diagramu
 * use case). Pieniądze trafiają do Portfela przez Portfel::dodajSrodki().
 *
 * Uwaga na `ekwipunek_id`: to id WPISU w ekwipunku, nie id przedmiotu z
 * katalogu. Gracz może mieć trzy takie same ryby i każda to osobny wpis.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\Ekwipunek;
use MocnyDrop\Json;
use MocnyDrop\Token;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('POST');

$uzytkownik = Token::wymagajZalogowania();
$body = Json::readBody();

$ekwipunekId = Json::intField($body, 'ekwipunek_id');

Json::success((new Ekwipunek($uzytkownik->id))->sprzedajWpis($ekwipunekId));
