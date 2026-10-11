<?php

declare(strict_types=1);

/**
 * api/upgrade.php — GET|POST /api/upgrade.php
 * Nagłówek: Authorization: Bearer <token>
 *
 * UPGRADER — przypadek użycia UC_UPGRADE z diagramu use case („Ulepszenie
 * przedmiotu (Upgrader)") i „Ulepszanie w Upgraderze (POST /api/upgrade.php)"
 * ze strony 2 diagramu MOCNYDROP_PROJEKT. Nazwa endpointu jest z diagramu.
 *
 * GET  — kalkulacja szansy («Kalkulacja szansy % = (W_wkład / W_cel) * (1-marża)»)
 *        Query: ?ekwipunek_id=12&cel_przedmiot_id=7
 *        Odpowiedź: { success, wklad, cel, szansa_procent, marza, wzor }
 *
 * POST — wykonanie próby
 *        Body: { "ekwipunek_id": 12, "cel_przedmiot_id": 7 }
 *        Odpowiedź: { success, udane, kolo, szansa_procent, wklad, cel,
 *                     przedmiot|null, historia_id, saldo, message }
 *
 * Cel musi być WART WIĘCEJ niż wkład (kod target_must_be_more_valuable) — marża
 * gwarantuje, że wartość oczekiwana próby jest niższa od wartości wkładu:
 * EV = szansa * W_cel = W_wkład * (1 - marża).
 *
 * Porażka jest bezpowrotna: wkład znika z tabeli `ekwipunek`
 * („Porażka: Bezpowrotna utrata przedmiotu wkładu").
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\Json;
use MocnyDrop\Token;
use MocnyDrop\UpgraderService;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('GET', 'POST');

$uzytkownik = Token::wymagajZalogowania();

$metoda = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'POST'));

if ($metoda === 'GET') {
    $ekwipunekId = (int) ($_GET['ekwipunek_id'] ?? 0);
    $celId = (int) ($_GET['cel_przedmiot_id'] ?? 0);

    if ($ekwipunekId <= 0 || $celId <= 0) {
        Json::fail(
            'Wymagane parametry: ekwipunek_id oraz cel_przedmiot_id.',
            'missing_field',
            400
        );
    }

    $kalkulacja = UpgraderService::kalkulacja($uzytkownik->id, $ekwipunekId, $celId);

    Json::success([
        'wklad' => $kalkulacja['wklad'],
        'cel' => $kalkulacja['cel'],
        'wartosc_wkladu' => $kalkulacja['wartosc_wkladu'],
        'wartosc_celu' => $kalkulacja['wartosc_celu'],
        'marza' => $kalkulacja['marza'],
        'szansa' => $kalkulacja['szansa'],
        'szansa_procent' => $kalkulacja['szansa_procent'],
        'wzor' => 'szansa = (W_wklad / W_cel) * (1 - marza)',
    ]);
}

$body = Json::readBody();

Json::success(
    UpgraderService::upgrade(
        $uzytkownik->id,
        Json::intField($body, 'ekwipunek_id'),
        Json::intField($body, 'cel_przedmiot_id')
    )
);
