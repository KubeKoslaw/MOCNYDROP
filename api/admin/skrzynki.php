<?php

declare(strict_types=1);

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

$czytajPule = static function (array $body): ?array {
    if (!array_key_exists('przedmioty', $body) || $body['przedmioty'] === null) {
        return null;
    }

    if (!is_array($body['przedmioty'])) {
        throw ApiException::badRequest('Pole przedmioty musi być listą.', 'invalid_pool_entry');
    }

    $pula = [];

    foreach ($body['przedmioty'] as $wpis) {
        if (!is_array($wpis)) {
            throw ApiException::badRequest('Wpis puli musi być obiektem {przedmiot_id, waga}.', 'invalid_pool_entry');
        }

        $pula[] = [
            'przedmiot_id' => (int) ($wpis['przedmiot_id'] ?? 0),
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
        (int) $poleFloat($body, 'kolejnosc', 0.0)
    );

    $skrzynka->pulaDoZapisu = $czytajPule($body) ?? [];

    $admin->edytujSkrzynke($skrzynka);

    Json::success([
        'skrzynka' => Skrzynka::wymagaj($skrzynka->id)->doApi(),
        'message' => 'Utworzono skrzynkę „' . $skrzynka->nazwa . '".',
    ], 201);
}

if ($akcja === 'edycja' || $akcja === 'przelacz') {
    $istniejaca = Skrzynka::wymagaj(Json::intField($body, 'skrzynka_id'));

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
        $istniejaca->id
    );

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
