<?php

declare(strict_types=1);

use MocnyDrop\Json;
use MocnyDrop\Token;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('POST');

$token = Token::bearerToken();

if ($token !== null) {
    $encja = Token::zBazy($token);

    if ($encja !== null) {
        $encja->uniewaznij();
    }
}

Json::success(['message' => 'Wylogowano.']);
