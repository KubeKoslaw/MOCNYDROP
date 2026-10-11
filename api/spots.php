<?php

declare(strict_types=1);

/**
 * api/spots.php — GET /api/spots.php
 *
 * Lista łowisk razem z pulą ryb, wagami i szansami procentowymi.
 * Frontend potrzebuje tego do zbudowania strony łowienia i paska ruletki.
 *
 * Odpowiedź zawiera też `rzadkosci` — słownik poziomów z kolorami,
 * żeby @THOPA174 nie musiał hardkodować barw w CSS, tylko brać je z API.
 *
 * Endpoint publiczny: zawartość łowisk i tak widać podczas animacji,
 * więc nie ma powodu chować jej za logowaniem.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\FishingSpotRepository;
use MocnyDrop\Json;
use MocnyDrop\Rarity;
use MocnyDrop\Settings;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('GET');

$spots = FishingSpotRepository::listActive();

Json::success([
    'lowiska' => array_map([FishingSpotRepository::class, 'toApi'], $spots),
    'rzadkosci' => array_values(Rarity::all()),
    'reel' => [
        'dlugosc' => Settings::getInt(Settings::KEY_REEL_LENGTH, 60),
        'indeks_wygranej' => Settings::getInt(Settings::KEY_REEL_WINNER_INDEX, 54),
    ],
]);
