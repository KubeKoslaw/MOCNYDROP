<?php

declare(strict_types=1);

use MocnyDrop\Json;
use MocnyDrop\Rzadkosc;
use MocnyDrop\Settings;
use MocnyDrop\Skrzynka;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('GET');

Json::success([
    'skrzynki' => array_map(
        static fn (Skrzynka $skrzynka): array => $skrzynka->doApi(),
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
