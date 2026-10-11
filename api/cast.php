<?php

declare(strict_types=1);

/**
 * api/cast.php — POST /api/cast.php
 * Nagłówek: Authorization: Bearer <token>
 * Body: { "lowisko_id": 1 }
 *
 * ZARZUT WĘDKĄ. To jest endpoint odpowiadający za całą mechanikę łowienia —
 * odpowiednik "otwórz skrzynkę" w trybie skrzynek.
 *
 * Odpowiedź: {
 *   success, rzut, lowisko,
 *   zlowiona_ryba: { ekwipunek_id, ryba_id, nazwa, rzadkosc, rzadkosc_kod,
 *                    kolor, wartosc, ikona, szansa_procent },
 *   saldo_przed, saldo_po,
 *   reel: [ 60 pozycji ], reel_dlugosc, reel_indeks_wygranej,
 *   message
 * }
 *
 * KOLEJNOŚĆ W ANIMACJI (dla @jgpostrach):
 *   1. wyślij żądanie i POCZEKAJ na odpowiedź — nie startuj animacji wcześniej,
 *   2. przewiń `reel` tak, żeby wskaźnik zatrzymał się na `reel_indeks_wygranej`,
 *   3. dopiero po zatrzymaniu pokaż `zlowiona_ryba` i zaktualizuj saldo z `saldo_po`.
 *
 * Wynik jest ustalony na serwerze przed animacją, więc jej przerwanie albo
 * zatrzymanie w innym miejscu nie zmienia wygranej.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\Auth;
use MocnyDrop\FishingService;
use MocnyDrop\Json;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('POST');

$user = Auth::requireAuth();
$body = Json::readBody();

$spotId = Json::intField($body, 'lowisko_id');

Json::success(FishingService::cast($user['id'], $spotId));
