<?php

declare(strict_types=1);

use MocnyDrop\Administrator;
use MocnyDrop\Json;
use MocnyDrop\Losowanie;

require_once dirname(__DIR__) . '/_bootstrap.php';

Json::requireMethod('GET');

$admin = Administrator::zalogowany();

$format = strtolower(trim((string) ($_GET['format'] ?? 'csv')));

if ($format === 'json') {
    Json::success([
        'admin' => $admin->doPublicznej(),
        'wygenerowano' => date('Y-m-d H:i:s'),
        'raport' => Losowanie::statystyki(),
    ]);
}

if ($format !== 'csv') {
    Json::fail('Nieznany format raportu. Dozwolone: csv, json.', 'unknown_format', 400);
}

$csv = $admin->eksportujRaport();

$nazwaPliku = 'raport-mocnydrop-' . date('Ymd-His') . '.csv';

if (!headers_sent()) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nazwaPliku . '"');
    header('Cache-Control: no-store');
    header_remove('X-Powered-By');
}

echo "\xEF\xBB\xBF";
echo $csv;

exit;
