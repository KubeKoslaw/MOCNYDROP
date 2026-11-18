<?php

declare(strict_types=1);

use MocnyDrop\FishingService;
use MocnyDrop\Json;
use MocnyDrop\Token;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('POST');

$uzytkownik = Token::wymagajZalogowania();
$body = Json::readBody();

$wedkaId = Json::intField($body, 'wedka_id');

Json::success(FishingService::cast($uzytkownik->id, $wedkaId));
