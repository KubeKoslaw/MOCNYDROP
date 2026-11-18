<?php

declare(strict_types=1);

use MocnyDrop\Json;
use MocnyDrop\Rzadkosc;
use MocnyDrop\Settings;
use MocnyDrop\Wedka;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('GET');

$wedki = Wedka::aktywne();

Json::success([
    'wedki' => array_map(static fn (Wedka $wedka): array => $wedka->doApi(), $wedki),
    'rzadkosci' => array_map(
        static fn (Rzadkosc $rzadkosc): array => $rzadkosc->doApi(),
        array_values(Rzadkosc::wszystkie())
    ),
    'reel' => [
        'dlugosc' => Settings::getInt(Settings::KEY_REEL_LENGTH, 60),
        'indeks_wygranej' => Settings::getInt(Settings::KEY_REEL_WINNER_INDEX, 54),
    ],
]);
