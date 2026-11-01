<?php

declare(strict_types=1);

use MocnyDrop\ApiException;
use MocnyDrop\Json;
use MocnyDrop\Settings;
use MocnyDrop\Token;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('POST');

$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
$accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));

$jsonRequestBody = str_contains($contentType, 'application/json');
$trybJson = $jsonRequestBody || str_contains($accept, 'application/json');

$body = $jsonRequestBody ? Json::readBody() : $_POST;

$login = Json::stringField($body, 'login');
$haslo = (string) ($body['password'] ?? '');

$redirectUri = trim((string) ($body['redirect_uri'] ?? ''));

if ($redirectUri === '') {
    $redirectUri = '/';
} elseif (preg_match('~^/(?!/)[^\r\n]*$~', $redirectUri) !== 1) {
    throw ApiException::badRequest(
        'Nieprawidłowy redirect_uri — dozwolona jest tylko ścieżka same-origin zaczynająca się od /.',
        'invalid_redirect_uri'
    );
}

if ($trybJson) {
    try {
        $kod = Token::autoryzuj($login, $haslo);
    } catch (ApiException $e) {
        Json::failWith($e);
    }

    Json::success([
        'code' => $kod->kod,
        'expires_in' => Settings::getInt(Settings::KEY_OAUTH_CODE_SECONDS, 300),
        'redirect_uri' => $redirectUri,
    ]);
}

try {
    $kod = Token::autoryzuj($login, $haslo);
} catch (ApiException $e) {
    if (!headers_sent()) {
        http_response_code(302);
        header('Location: ' . $redirectUri . '?login_error=' . rawurlencode($e->errorCode()));
    }

    exit;
}

if (!headers_sent()) {
    http_response_code(302);
    header('Location: ' . $redirectUri . '?code=' . rawurlencode($kod->kod));
}

exit;
