<?php

declare(strict_types=1);

use MocnyDrop\Json;
use MocnyDrop\Uzytkownik;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('POST');

$body = Json::readBody();

$uzytkownik = Uzytkownik::utworz(
    Json::stringField($body, 'login'),
    Json::stringField($body, 'email'),
    (string) ($body['password'] ?? '')
);

$uzytkownik->zarejestruj();

Json::success([
    'user' => $uzytkownik->doPublicznej(),
    'message' => 'Rejestracja udana! Możesz się teraz zalogować.',
], 201);
