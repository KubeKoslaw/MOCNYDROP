<?php

declare(strict_types=1);

use MocnyDrop\Ekwipunek;
use MocnyDrop\Json;
use MocnyDrop\Token;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('GET');

$uzytkownik = Token::wymagajZalogowania();
$portfel = $uzytkownik->portfel();

Json::success([
    'user' => $uzytkownik->doPublicznej(),
    'saldo' => round($portfel->saldo, 2),
    'waluta' => $portfel->waluta,
    'ekwipunek' => (new Ekwipunek($uzytkownik->id))->podsumowanie(),
]);
