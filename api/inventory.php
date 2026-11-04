<?php

declare(strict_types=1);

use MocnyDrop\Ekwipunek;
use MocnyDrop\Json;
use MocnyDrop\Token;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('GET');

$uzytkownik = Token::wymagajZalogowania();

$includeSold = filter_var($_GET['z_sprzedanymi'] ?? '0', FILTER_VALIDATE_BOOL);

$ekwipunek = new Ekwipunek($uzytkownik->id);

Json::success([
    'ekwipunek' => $ekwipunek->lista($includeSold),
    'podsumowanie' => $ekwipunek->podsumowanie(),
]);
