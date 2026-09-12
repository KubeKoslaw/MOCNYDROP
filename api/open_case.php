<?php
/**
 * api/open_case.php
 * Endpoint otwierania skrzynki (POST).
 * Wymaga autoryzacji nagłówkiem: Authorization: Bearer <token>
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/CaseManager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError("Metoda nieobsługiwana. Użyj metody POST.", 405);
}

// 1. Wymagamy zalogowanego gracza
$user = Auth::requireAuth();

// 2. Pobieramy id skrzynki z zapytania
$input = getJsonInput();
$caseId = isset($input['case_id']) ? (int)$input['case_id'] : 0;

if ($caseId <= 0) {
    jsonError("Brakujący lub nieprawidłowy identyfikator skrzynki (case_id).", 400);
}

try {
    // 3. Otwieramy skrzynkę w transakcji
    $result = CaseManager::openCase($user['id'], $caseId);
    jsonResponse($result, 200);
} catch (Exception $e) {
    jsonError($e->getMessage(), 400);
}
