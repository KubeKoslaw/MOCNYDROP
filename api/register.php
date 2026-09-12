<?php
/**
 * api/register.php
 * Endpoint rejestracji nowego użytkownika (POST).
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../src/Auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError("Metoda nieobsługiwana. Użyj metody POST.", 405);
}

$input = getJsonInput();
$login = $input['login'] ?? '';
$email = $input['email'] ?? '';
$password = $input['password'] ?? '';

try {
    $result = Auth::register($login, $email, $password);
    jsonResponse($result, 201);
} catch (Exception $e) {
    jsonError($e->getMessage(), 400);
}
