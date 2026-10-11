<?php

declare(strict_types=1);

/**
 * api/login.php — POST /api/login.php
 *
 * Body: { "login": "gracz", "password": "haslo1234" }
 * Pole "login" przyjmuje też adres e-mail.
 *
 * Odpowiedź: { success, token, token_type, expires_at, user }
 * Token trafia do localStorage i leci w nagłówku Authorization: Bearer <token>
 *
 * Logowanie obsługuje klasa Token z diagramu klas (wystawienie tokena) razem
 * z Uzytkownik::zaloguj() (weryfikacja hasła i blokady konta).
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\Json;
use MocnyDrop\Token;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('POST');

$body = Json::readBody();

Json::success(
    Token::zaloguj(
        Json::stringField($body, 'login'),
        (string) ($body['password'] ?? '')
    )
);
