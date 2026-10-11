<?php

declare(strict_types=1);

/**
 * api/katalog.php — GET /api/katalog.php
 *
 * Katalog przedmiotów (klasa Przedmiot z diagramu klas) + słownik rzadkości
 * + parametry trybów gry.
 *
 * Potrzebny dwóm przypadkom użycia z diagramu:
 *   UC_UPGRADE — «Wybór pożądanego przedmiotu celu z katalogu»,
 *   UC_TRADE   — podgląd progów kontraktu przed wybraniem wkładu.
 *
 * Query (opcjonalne): ?rzadkosc_kod=niezla  — tylko jeden poziom rzadkości.
 *
 * Endpoint publiczny: te same dane widać w pulach łowisk i skrzynek.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\Json;
use MocnyDrop\Przedmiot;
use MocnyDrop\Rzadkosc;
use MocnyDrop\Settings;
use MocnyDrop\TraderService;
use MocnyDrop\UpgraderService;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('GET');

$filtr = trim((string) ($_GET['rzadkosc_kod'] ?? ''));

$przedmioty = array_map(static fn (array $row): array => [
    'przedmiot_id' => $row['przedmiot_id'],
    'nazwa' => $row['nazwa'],
    'rzadkosc' => $row['rzadkosc_nazwa'],
    'rzadkosc_kod' => $row['rzadkosc_kod'],
    'kolejnosc' => $row['rzadkosc_kolejnosc'],
    'kolor' => $row['kolor'],
    'wartosc' => round((float) $row['wartosc'] * (float) $row['mnoznik'], 2),
    'ikona' => $row['ikona_url'],
    'opis' => $row['opis'],
], Przedmiot::katalog());

if ($filtr !== '') {
    $przedmioty = array_values(array_filter(
        $przedmioty,
        static fn (array $item): bool => $item['rzadkosc_kod'] === $filtr
    ));
}

Json::success([
    'przedmioty' => $przedmioty,
    'liczba_przedmiotow' => count($przedmioty),
    'rzadkosci' => array_map(
        static fn (Rzadkosc $rzadkosc): array => $rzadkosc->doApi(),
        array_values(Rzadkosc::wszystkie())
    ),
    'upgrader' => [
        'marza' => UpgraderService::marza(),
        'wzor' => 'szansa = (W_wklad / W_cel) * (1 - marza)',
    ],
    'trader' => TraderService::limity(),
    'waluta' => Settings::get(Settings::KEY_CURRENCY, 'monety'),
]);
