<?php

declare(strict_types=1);

use MocnyDrop\Json;
use MocnyDrop\Skrzynka;
use MocnyDrop\Token;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('POST');

$uzytkownik = Token::wymagajZalogowania();
$body = Json::readBody();

$skrzynka = Skrzynka::wymagaj(Json::intField($body, 'skrzynka_id'));

$sprzedajNatychmiast = filter_var($body['sprzedaj_natychmiast'] ?? false, FILTER_VALIDATE_BOOL);

$wynik = $skrzynka->otworzSzczegolowo($uzytkownik, $sprzedajNatychmiast);

Json::success($skrzynka->odpowiedzOtwarcia($wynik));
