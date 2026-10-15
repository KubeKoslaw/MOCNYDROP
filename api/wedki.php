<?php

declare(strict_types=1);

/**
 * api/wedki.php — GET /api/wedki.php
 *
 * Lista wędek (klasa Wedka z diagramu klas) razem z pulą przedmiotów, wagami
 * i szansami procentowymi. Frontend potrzebuje tego do zbudowania strony
 * łowienia i paska ruletki.
 *
 * Odpowiedź zawiera też `rzadkosci` — słownik poziomów z kolorami i mnożnikami,
 * żeby @THOPA174 nie musiał hardkodować barw w CSS, tylko brać je z API.
 *
 * Endpoint publiczny: zawartość puli i tak widać podczas animacji, więc nie ma
 * powodu chować jej za logowaniem.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\Json;
use MocnyDrop\Rzadkosc;
use MocnyDrop\Settings;
use MocnyDrop\Wedka;
use MocnyDrop\Token;
use MocnyDrop\PosiadaneWedki;
use MocnyDrop\Database;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('GET');

$db = Database::connection();
$u = Token::bearerToken() === null ? null : Token::wymagajZalogowania();
if ($u !== null) { PosiadaneWedki::startowa($u->id, $db); }
$wedki = Wedka::aktywne();

Json::success([
    'wedki' => array_map(static fn (Wedka $wedka): array => $wedka->doApi() + ['posiadana' => $u !== null && PosiadaneWedki::ma($u->id, $wedka->id, $db)], $wedki),
    'rzadkosci' => array_map(
        static fn (Rzadkosc $rzadkosc): array => $rzadkosc->doApi(),
        array_values(Rzadkosc::wszystkie())
    ),
    'reel' => [
        'dlugosc' => Settings::getInt(Settings::KEY_REEL_LENGTH, 60),
        'indeks_wygranej' => Settings::getInt(Settings::KEY_REEL_WINNER_INDEX, 54),
    ],
]);
