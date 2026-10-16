<?php

declare(strict_types=1);

/**
 * api/admin/skrzynki.php — GET|POST /api/admin/skrzynki.php
 * Nagłówek: Authorization: Bearer <token> (konto z rola = 'admin')
 *
 * ZARZĄDZANIE SKRZYNKAMI — przypadek użycia UC_ADM1 z diagramu use case
 * i „Kreator i Edytor Skrzynek (Konfiguracja w sklepie)" ze strony 3 diagramu
 * MOCNYDROP_PROJEKT.
 *
 * GET  — lista WSZYSTKICH skrzynek (także nieaktywnych) z pulami i wagami.
 *
 * POST — body { akcja, ... }:
 *   "utworz"   { nazwa, cena, opis?, ikona?, aktywna?, kolejnosc?, wedki? }
 *   "edycja"   { skrzynka_id, nazwa?, cena?, opis?, ikona?, aktywna?, kolejnosc?, wedki? }
 *   "przelacz" { skrzynka_id }                      -> aktywna = 0/1
 *
 *   wedki: [ { "wedka_id": 1, "waga": 5.0 }, { "wedka_id": 2, "waga": null } ]
 *   (waga null = domyślna waga 1)
 *
 * Wszystkie zapisy idą przez Administrator::edytujSkrzynke() z diagramu klas —
 * jedną transakcją, z wpisem do log_audytowy.
 *
 * Przypadek rozszerzający ADM1_ERR «Błędne wagi lub ujemna cena»:
 *   kody invalid_price, invalid_weight, invalid_case_name, item_not_found (404),
 *   case_pool_empty, duplicate_pool_entry (409), case_name_taken (409).
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\Administrator;
use MocnyDrop\ApiException;
use MocnyDrop\Json;
use MocnyDrop\Skrzynka;

require_once dirname(__DIR__) . '/_bootstrap.php';

Json::requireMethod('GET', 'POST');

$admin = Administrator::zalogowany();

$metoda = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'POST'));

if ($metoda === 'GET') {
    Json::success([
        'skrzynki' => array_map(
            static fn (Skrzynka $skrzynka): array => $skrzynka->doApi(),
            Skrzynka::wszystkie()
        ),
    ]);
}

$body = Json::readBody();

$akcja = strtolower(Json::stringField($body, 'akcja'));

/** Pole liczbowe z body z wartością domyślną (cena, kolejnosc). */
$poleFloat = static function (array $body, string $klucz, float $domyslna): float {
    if (!array_key_exists($klucz, $body) || $body[$klucz] === null || $body[$klucz] === '') {
        return $domyslna;
    }

    $wartosc = $body[$klucz];

    if (!is_numeric($wartosc)) {
        throw ApiException::badRequest('Pole ' . $klucz . ' musi być liczbą.', 'invalid_field');
    }

    return (float) $wartosc;
};

/**
 * Lista dropów z body: [{wedka_id, waga}] — null = „nie ruszaj puli".
 *
 * @param array<string, mixed> $body
 * @return list<array{wedka_id: int, waga: float|null}>|null
 */
$czytajPule = static function (array $body): ?array {
    if (!array_key_exists('wedki', $body) || $body['wedki'] === null) {
        return null;
    }

    if (!is_array($body['wedki'])) {
        throw ApiException::badRequest('Pole wedki musi być listą.', 'invalid_pool_entry');
    }

    $pula = [];

    foreach ($body['wedki'] as $wpis) {
        if (!is_array($wpis)) {
            throw ApiException::badRequest('Wpis puli musi być obiektem {wedka_id, waga}.', 'invalid_pool_entry');
        }

        $pula[] = [
            'wedka_id' => Json::intField($wpis, 'wedka_id'),
            'waga' => array_key_exists('waga', $wpis) ? $wpis['waga'] : null,
        ];
    }

    return $pula;
};

if ($akcja === 'utworz') {
    $skrzynka = Skrzynka::nowa(
        Json::stringField($body, 'nazwa'),
        $poleFloat($body, 'cena', 0.0),
        Json::stringField($body, 'ikona'),
        Json::stringField($body, 'opis'),
        filter_var($body['aktywna'] ?? true, FILTER_VALIDATE_BOOL),
        (int) $poleFloat($body, 'kolejnosc', 0.0),
        dzienna: filter_var($body['dzienna'] ?? false, FILTER_VALIDATE_BOOL)
    );

    // Nowa skrzynka bez puli nie da się otworzyć — wymagamy dropów od razu.
    $skrzynka->pulaDoZapisu = $czytajPule($body) ?? [];

    $admin->edytujSkrzynke($skrzynka);

    Json::success([
        'skrzynka' => Skrzynka::wymagaj($skrzynka->id)->doApi(),
        'message' => 'Utworzono skrzynkę „' . $skrzynka->nazwa . '".',
    ], 201);
}

if ($akcja === 'edycja' || $akcja === 'przelacz') {
    $istniejaca = Skrzynka::wymagaj(Json::intField($body, 'skrzynka_id'));

    // Pola readonly — budujemy nowy egzemplarz z docelowymi wartościami,
    // a brakujące pola przepisujemy z bazy (merge jak w formularzu).
    $skrzynka = new Skrzynka(
        $akcja === 'przelacz'
            ? $istniejaca->nazwa
            : (array_key_exists('nazwa', $body) ? Json::stringField($body, 'nazwa') : $istniejaca->nazwa),
        $akcja === 'przelacz'
            ? $istniejaca->cena
            : $poleFloat($body, 'cena', $istniejaca->cena),
        $akcja === 'przelacz'
            ? $istniejaca->ikona
            : (array_key_exists('ikona', $body) ? Json::stringField($body, 'ikona') : $istniejaca->ikona),
        $akcja === 'przelacz'
            ? !$istniejaca->aktywna
            : (array_key_exists('aktywna', $body)
                ? filter_var($body['aktywna'], FILTER_VALIDATE_BOOL)
                : $istniejaca->aktywna),
        $akcja === 'przelacz'
            ? $istniejaca->opis
            : (array_key_exists('opis', $body) ? Json::stringField($body, 'opis') : $istniejaca->opis),
        $akcja === 'przelacz'
            ? $istniejaca->kolejnosc
            : (int) $poleFloat($body, 'kolejnosc', (float) $istniejaca->kolejnosc),
        $istniejaca->id,
        dzienna: $akcja === 'przelacz' ? $istniejaca->dzienna : filter_var($body['dzienna'] ?? $istniejaca->dzienna, FILTER_VALIDATE_BOOL)
    );

    // Przełącznik nie rusza puli; edycja zapisuje ją tylko, gdy przyszła w body.
    $skrzynka->pulaDoZapisu = $akcja === 'przelacz' ? null : $czytajPule($body);

    $admin->edytujSkrzynke($skrzynka);

    Json::success([
        'skrzynka' => Skrzynka::wymagaj($skrzynka->id)->doApi(),
        'message' => $akcja === 'przelacz'
            ? ($skrzynka->aktywna ? 'Skrzynka włączona.' : 'Skrzynka wyłączona.')
            : 'Zapisano zmiany w skrzynce „' . $skrzynka->nazwa . '".',
    ]);
}

throw ApiException::badRequest(
    'Nieznana akcja. Dozwolone: utworz, edycja, przelacz.',
    'unknown_action'
);
