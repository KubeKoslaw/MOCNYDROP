<?php
/**
 * api/helpers.php
 * Pomocnicze funkcje dla endpointów API (CORS, formatowanie JSON).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Obsługa zapytania wstępnego CORS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Odczytuje dane wejściowe przekazane jako JSON lub z formularza POST.
 */
function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (is_array($data)) {
        return $data;
    }
    return $_POST;
}

/**
 * Zwraca odpowiedź JSON z podanym kodem HTTP.
 */
function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Zwraca błąd JSON.
 */
function jsonError(string $message, int $statusCode = 400): void {
    jsonResponse(['error' => $message], $statusCode);
}
