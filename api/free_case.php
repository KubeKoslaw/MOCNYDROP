<?php

declare(strict_types=1);

use MocnyDrop\DarmowaSkrzynka;
use MocnyDrop\Json;
use MocnyDrop\Token;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('GET', 'POST');

$uzytkownik = Token::wymagajZalogowania();

$metoda = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'POST'));

if ($metoda === 'GET') {
    Json::success(DarmowaSkrzynka::status($uzytkownik->id));
}

$odbior = DarmowaSkrzynka::odbierz($uzytkownik);

$odpowiedz = $odbior['skrzynka']->odpowiedzOtwarcia($odbior['wynik']);
$odpowiedz['darmowa'] = DarmowaSkrzynka::status($uzytkownik->id);

Json::success($odpowiedz);
