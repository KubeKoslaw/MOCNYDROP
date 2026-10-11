<?php

declare(strict_types=1);

/**
 * api/trade_up.php — GET|POST /api/trade_up.php
 * Nagłówek: Authorization: Bearer <token>
 *
 * TRADER — przypadek użycia UC_TRADE z diagramu use case („Wymiana przedmiotów
 * (Trader)") i „Wymiana w Traderze (POST /api/trade_up.php)" ze strony 2
 * diagramu MOCNYDROP_PROJEKT. Nazwa endpointu jest z diagramu.
 *
 * GET  — podgląd kontraktu (suma wkładu, próg, pula nagród z szansami)
 *        Query: ?ekwipunek_ids=12,13,14
 * POST — wykonanie kontraktu
 *        Body: { "ekwipunek_ids": [12, 13, 14] }
 *
 * Zasady (wszystkie z diagramu PROJEKT):
 *   - od 3 do 10 przedmiotów ze swojego ekwipunku (kody too_few_items / too_many_items),
 *   - łączna wartość wkładu >= progu `trader_min_wartosc`, inaczej
 *     «Odrzucenie: za niska wartość» (kod trade_below_minimum = TRADE_ERR),
 *   - wkład jest TRWALE usuwany (spalony),
 *   - nagroda jest losowana z puli przedmiotów RZADSZYCH niż najrzadszy wkład.
 *
 * Odpowiedź: { success, kontrakt, spalone, nagroda, pula, reel, reel_dlugosc,
 *              reel_indeks_wygranej, historia_id, saldo, message }
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\ApiException;
use MocnyDrop\Json;
use MocnyDrop\Token;
use MocnyDrop\TraderService;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('GET', 'POST');

$uzytkownik = Token::wymagajZalogowania();

/**
 * Lista id wpisów ekwipunku z query albo z body.
 *
 * @param mixed $surowe
 * @return list<int>
 */
$czytajListe = static function ($surowe): array {
    if (is_string($surowe)) {
        $surowe = explode(',', $surowe);
    }

    if (!is_array($surowe)) {
        throw ApiException::badRequest(
            'Pole ekwipunek_ids musi być listą identyfikatorów wpisów ekwipunku.',
            'invalid_entry_list'
        );
    }

    return $surowe;
};

$metoda = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'POST'));

if ($metoda === 'GET') {
    $ids = $czytajListe($_GET['ekwipunek_ids'] ?? '');

    if ($ids === []) {
        Json::fail('Wymagany parametr: ekwipunek_ids (lista 3–10 wpisów).', 'missing_field', 400);
    }

    $kontrakt = TraderService::kalkulacja($uzytkownik->id, $ids);

    Json::success([
        'wklad' => $kontrakt['wpisy'],
        'wartosc_wkladu' => $kontrakt['wartosc_wkladu'],
        'poziom_wejsciowy' => $kontrakt['poziom_wejsciowy'],
        'limity' => $kontrakt['limity'],
        'pula' => array_map(static fn (array $item): array => [
            'przedmiot_id' => $item['przedmiot_id'],
            'nazwa' => $item['nazwa'],
            'rzadkosc' => $item['rzadkosc_nazwa'],
            'rzadkosc_kod' => $item['rzadkosc_kod'],
            'kolor' => $item['kolor'],
            'wartosc' => $item['wartosc'],
            'ikona' => $item['ikona_url'],
            'szansa_procent' => $item['szansa_procent'],
        ], $kontrakt['pula']),
    ]);
}

$body = Json::readBody();

if (!array_key_exists('ekwipunek_ids', $body)) {
    Json::fail('Brak wymaganego pola: ekwipunek_ids', 'missing_field', 400);
}

Json::success(
    TraderService::trade($uzytkownik->id, $czytajListe($body['ekwipunek_ids']))
);
