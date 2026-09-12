<?php
/**
 * api/me.php
 * Endpoint profilu zalogowanego gracza (GET) — zwraca saldo i dane konta.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../src/Auth.php';

$user = Auth::requireAuth();

jsonResponse([
    'user' => $user
]);
