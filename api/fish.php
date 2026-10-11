<?php

declare(strict_types=1);

/**
 * api/fish.php — POST /api/fish.php
 * Nagłówek: Authorization: Bearer <token>
 * Body: { "wedka_id": 1 }
 *
 * RZUT WĘDKĄ — endpoint ze strony 2 diagramu MOCNYDROP_PROJEKT:
 * „Rzut wędką w łowisku (POST /api/fish.php)".
 *
 * Odpowiedź: {
 *   success, rzut: { id, typ, koszt }, wedka: { id, nazwa, koszt_uzycia },
 *   przedmiot: { ekwipunek_id, przedmiot_id, nazwa, rzadkosc, rzadkosc_kod,
 *                kolor, wartosc, ikona, szansa_procent },
 *   saldo_przed, saldo_po,
 *   reel: [ 60 pozycji ], reel_dlugosc, reel_indeks_wygranej,
 *   message
 * }
 *
 * KOLEJNOŚĆ W ANIMACJI (dla @jgpostrach):
 *   1. wyślij żądanie i POCZEKAJ na odpowiedź — nie startuj animacji wcześniej,
 *   2. przewiń `reel` tak, żeby wskaźnik zatrzymał się na `reel_indeks_wygranej`,
 *   3. dopiero po zatrzymaniu pokaż `przedmiot` i zaktualizuj saldo z `saldo_po`.
 *
 * Wynik jest ustalony na serwerze przed animacją, więc jej przerwanie albo
 * zatrzymanie w innym miejscu nie zmienia wygranej.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\FishingService;
use MocnyDrop\Json;
use MocnyDrop\Token;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('POST');

$uzytkownik = Token::wymagajZalogowania();
$body = Json::readBody();

$wedkaId = Json::intField($body, 'wedka_id');

Json::success(FishingService::cast($uzytkownik->id, $wedkaId));
