<?php
/**
 * api/login.php
 * Endpoint logowania użytkownika (POST) — zwraca Bearer Token.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../src/Auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError("Metoda nieobsługiwana. Użyj metody POST.", 405);
}

$input = getJsonInput();
$login = $input['login'] ?? $input['email'] ?? '';
$password = $input['password'] ?? '';

try {
    $result = Auth::login($login, $password);
    jsonResponse($result, 200);
} catch (Exception $e) {
    jsonError($e->getMessage(), 401);
}
