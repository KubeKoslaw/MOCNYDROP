<?php

declare(strict_types=1);

/**
 * api/skrzynki.php — GET /api/skrzynki.php
 *
 * Lista aktywnych skrzynek (klasa Skrzynka z diagramu klas) razem z pulą
 * przedmiotów, wagami i szansami procentowymi — odpowiednik api/wedki.php
 * dla trybu «Otwarcie skrzynki» (UC_CASE z diagramu use case).
 *
 * Odpowiedź: { success, skrzynki: [...], rzadkosci: [...], reel: { dlugosc, indeks_wygranej } }
 *
 * Endpoint publiczny: zawartość puli i tak widać podczas animacji, więc nie ma
 * powodu chować jej za logowaniem (tak samo jak przy wędkach).
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\Json;
use MocnyDrop\Rzadkosc;
use MocnyDrop\Settings;
use MocnyDrop\Skrzynka;
use MocnyDrop\Token;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('GET');

$u = Token::bearerToken() === null ? null : Token::wymagajZalogowania();
Json::success([
    'czas_serwera' => time(),
    'skrzynki' => array_map(
        static fn (Skrzynka $skrzynka): array => $skrzynka->doApi($u?->id),
        Skrzynka::aktywne()
    ),
    'rzadkosci' => array_map(
        static fn (Rzadkosc $rzadkosc): array => $rzadkosc->doApi(),
        array_values(Rzadkosc::wszystkie())
    ),
    'reel' => [
        'dlugosc' => Settings::getInt(Settings::KEY_REEL_LENGTH, 60),
        'indeks_wygranej' => Settings::getInt(Settings::KEY_REEL_WINNER_INDEX, 54),
    ],
]);
