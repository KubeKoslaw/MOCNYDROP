<?php

declare(strict_types=1);

/**
 * api/open_case.php — POST /api/open_case.php
 * Nagłówek: Authorization: Bearer <token>
 * Body: { "skrzynka_id": 1, "sprzedaj_natychmiast": false }
 *
 * OTWARCIE SKRZYNKI — przypadek użycia UC_CASE z diagramu use case i „Otwarcie
 * Skrzynki (POST /api/open_case.php)" ze strony 2 diagramu MOCNYDROP_PROJEKT.
 * Nazwa endpointu jest wprost z diagramu.
 *
 * Obsługuje oba przypadki rozszerzające z diagramu:
 *   CASE_ERR  «Brak środków»                    -> kod insufficient_funds
 *   CASE_SELL «Natychmiastowa sprzedaż wygranej» -> sprzedaj_natychmiast: true
 *
 * Odpowiedź: {
 *   success, otwarcie: { id, typ, cena }, skrzynka: { id, nazwa, cena },
 *   przedmiot: { ekwipunek_id, przedmiot_id, nazwa, rzadkosc, rzadkosc_kod,
 *                kolor, wartosc, ikona, szansa_procent },
 *   saldo_przed, saldo_po, sprzedaz,
 *   reel: [ 60 pozycji ], reel_dlugosc, reel_indeks_wygranej, message
 * }
 *
 * Kontrakt paska `reel` jest IDENTYCZNY jak w api/fish.php: wygrana leży pod
 * `reel_indeks_wygranej`, a wynik ustala serwer przed animacją.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\ApiException;
use MocnyDrop\Json;
use MocnyDrop\Skrzynka;
use MocnyDrop\Token;
use MocnyDrop\TypLosowania;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('POST');

$uzytkownik = Token::wymagajZalogowania();
$body = Json::readBody();

$skrzynka = Skrzynka::wymagaj(Json::intField($body, 'skrzynka_id'));

$sprzedajNatychmiast = filter_var($body['sprzedaj_natychmiast'] ?? false, FILTER_VALIDATE_BOOL);

$wynik = $skrzynka->otworzSzczegolowo($uzytkownik, $sprzedajNatychmiast);

/** @var \MocnyDrop\Przedmiot $przedmiot */
$przedmiot = $wynik['przedmiot'];

Json::success([
    'otwarcie' => [
        'id' => $wynik['historia_id'],
        'typ' => TypLosowania::Skrzynka->value,
        'cena' => $wynik['cena'],
    ],
    'skrzynka' => [
        'id' => $skrzynka->id,
        'nazwa' => $skrzynka->nazwa,
        'cena' => $wynik['cena'],
    ],
    'przedmiot' => [
        'ekwipunek_id' => $wynik['ekwipunek_id'],
        'przedmiot_id' => $przedmiot->id,
        'nazwa' => $przedmiot->nazwa,
        'rzadkosc' => $przedmiot->rzadkosc->nazwa,
        'rzadkosc_kod' => $przedmiot->rzadkosc->kod,
        'kolor' => $przedmiot->rzadkosc->kolor,
        'wartosc' => $wynik['wartosc'],
        'ikona' => $przedmiot->ikonaUrl(),
        'szansa_procent' => $wynik['szansa_procent'],
    ],
    'saldo_przed' => $wynik['saldo_przed'],
    'saldo_po' => $wynik['saldo_po'],
    'sprzedaz' => $wynik['sprzedaz'],
    'reel' => $wynik['reel'],
    'reel_dlugosc' => $wynik['reel_dlugosc'],
    'reel_indeks_wygranej' => $wynik['reel_indeks_wygranej'],
    'message' => $wynik['sprzedaz'] === null
        ? sprintf('Zdobyto: %s (%s)!', $przedmiot->nazwa, $przedmiot->rzadkosc->nazwa)
        : sprintf(
            'Zdobyto %s (%s) i sprzedano od razu za %s monet.',
            $przedmiot->nazwa,
            $przedmiot->rzadkosc->nazwa,
            ApiException::money((float) $wynik['sprzedaz']['otrzymane_monety'])
        ),
]);
