<?php

declare(strict_types=1);

use MocnyDrop\Json;
use MocnyDrop\Losowanie;
use MocnyDrop\Token;
use MocnyDrop\TypLosowania;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('GET');

$uzytkownik = Token::wymagajZalogowania();

$rawLimit = $_GET['limit'] ?? '20';
$limit = is_scalar($rawLimit) && preg_match('/^\d+$/', trim((string) $rawLimit)) === 1
    ? (int) trim((string) $rawLimit)
    : 20;

Json::success([
    'historia' => Losowanie::historia($uzytkownik->id, $limit),
    'rozbicie_rzadkosci' => Losowanie::rozbicieRzadkosci($uzytkownik->id),
    'rtp' => Losowanie::rtpDlaTypu(TypLosowania::Wedka),
]);
