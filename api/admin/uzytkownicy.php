<?php

declare(strict_types=1);

/**
 * api/admin/uzytkownicy.php — GET|POST /api/admin/uzytkownicy.php
 * Nagłówek: Authorization: Bearer <token> (konto z rola = 'admin')
 *
 * ZARZĄDZANIE UŻYTKOWNIKAMI — przypadek użycia UC_ADM2 z diagramu use case
 * i „Panel Zarządzania Użytkownikami (Wyszukiwanie i edycja)" ze strony 3
 * diagramu MOCNYDROP_PROJEKT.
 *
 * GET  — wyszukiwanie konta po ID, loginie lub e-mailu
 *        Query: ?q=gracz&limit=50
 *        Odpowiedź: { success, uzytkownicy: [...], limit }
 *
 * POST — body { akcja, user_id, ... }:
 *   "ban"        { user_id }            -> Zablokowanie konta (ADM2_S3) + kasacja tokenów
 *   "odblokuj"   { user_id }            -> zdjęcie blokady
 *   "saldo"      { user_id, kwota }     -> Zmiana salda gracza (ADM2_S2), kwota +/-
 *
 * Każdy z tych przypadków wywołuje metodę klasy Administrator z diagramu klas
 * (zbanuj / korygujSaldo) i zostawia wpis w log_audytowy.
 *
 * Przypadek rozszerzający ADM2_ERR «Próba zablokowania głównego administratora»:
 *   kod admin_cannot_be_banned (403) — nie da się zbanować siebie ani innego admina.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\Administrator;
use MocnyDrop\ApiException;
use MocnyDrop\Json;
use MocnyDrop\Uzytkownik;

require_once dirname(__DIR__) . '/_bootstrap.php';

Json::requireMethod('GET', 'POST');

$admin = Administrator::zalogowany();

$metoda = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'POST'));

if ($metoda === 'GET') {
    $q = trim((string) ($_GET['q'] ?? ''));

    $rawLimit = $_GET['limit'] ?? '50';
    $limit = is_scalar($rawLimit) && preg_match('/^\d+$/', trim((string) $rawLimit)) === 1
        ? (int) trim((string) $rawLimit)
        : 50;

    Json::success([
        'uzytkownicy' => Uzytkownik::szukajDlaAdmina($q, $limit),
        'szukana_fraza' => $q,
        'limit' => max(1, min(200, $limit)),
    ]);
}

$body = Json::readBody();

$akcja = strtolower(Json::stringField($body, 'akcja'));
$cel = Uzytkownik::znajdz(Json::intField($body, 'user_id'));

/**
 * Konto w kształcie dla panelu: doPublicznej() + stan blokady.
 * Atrybut `zablokowany` jest z diagramu klas, ale w odpowiedziach gracza
 * celowo go nie ma — panel admina musi go widzieć.
 */
$doPanelu = static fn (Uzytkownik $u): array => array_merge(
    $u->doPublicznej(),
    ['zablokowany' => $u->czyZablokowany()]
);

switch ($akcja) {
    case 'ban':
        $admin->zbanuj($cel);

        Json::success([
            'uzytkownik' => $doPanelu($cel),
            'message' => 'Konto „' . $cel->login . '" zostało zablokowane, a jego tokeny unieważnione.',
        ]);

    // PHP nie pozwala na break po never-returning wywołaniu, więc intentionally
    // bez break — Json::success() kończy skrypt przez exit.

    case 'odblokuj':
        $admin->odbanuj($cel);

        Json::success([
            'uzytkownik' => $doPanelu($cel),
            'message' => 'Konto „' . $cel->login . '" zostało odblokowane.',
        ]);

    case 'saldo':
        if (!array_key_exists('kwota', $body) || !is_numeric($body['kwota'])) {
            throw ApiException::badRequest('Pole kwota musi być liczbą (dodatnią lub ujemną).', 'invalid_amount');
        }

        $kwota = (float) $body['kwota'];

        $admin->korygujSaldo($cel, $kwota);

        Json::success([
            'uzytkownik' => $doPanelu($cel),
            'kwota' => $kwota,
            'saldo' => round($cel->portfel()->saldo, 2),
            'message' => sprintf(
                'Saldo gracza „%s" zmienione o %s.',
                $cel->login,
                ApiException::money($kwota)
            ),
        ]);

    default:
        throw ApiException::badRequest(
            'Nieznana akcja. Dozwolone: ban, odblokuj, saldo.',
            'unknown_action'
        );
}
