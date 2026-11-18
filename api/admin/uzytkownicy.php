<?php

declare(strict_types=1);

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

$doPanelu = static fn (Uzytkownik $u): array => array_merge(
    $u->doPublicznej(),
    ['zablokowany' => $u->czyZablokowany()]
);

switch ($akcja) {
    case 'ban':
        $admin->zbanuj($cel);

        Json::success([
            'uzytkownik' => $doPanelu($cel),
            'message' => 'Konto „' . $cel->login . '" zostało zablokowane, a jego sesje OAuth unieważnione.',
        ]);

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
