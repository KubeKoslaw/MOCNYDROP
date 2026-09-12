<?php
/**
 * api/cases.php
 * Endpoint pobierający listę skrzynek i przedmioty w nich zawarte (GET).
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../src/CaseManager.php';

try {
    $cases = CaseManager::getAllCases();
    jsonResponse([
        'cases' => $cases
    ]);
} catch (Exception $e) {
    jsonError($e->getMessage(), 500);
}
