<?php

declare(strict_types=1);

/**
 * api/admin/raport.php — GET /api/admin/raport.php?format=csv|json
 * Nagłówek: Authorization: Bearer <token> (konto z rola = 'admin')
 *
 * EKSPORT RAPORTU — przypadek ADM3_S3 z diagramu use case i „Eksport raportu
 * finansowego platformy do CSV/JSON" ze strony 3 diagramu MOCNYDROP_PROJEKT.
 *
 *   format=csv   (domyślne) — plik do pobrania: wszystkie losowania z kolumnami
 *                id, czas, typ, login, koszt, wygrana_wartosc, bilans + wiersz SUMA.
 *                Generuje go Administrator::eksportujRaport() z diagramu klas.
 *   format=json  — podsumowanie finansowe (RTP per tryb, rozbicie rzadkości,
 *                największe wygrane) z Losowanie::statystyki().
 *
 * Każde wywołanie CSV zostawia wpis `eksport_raportu` w log_audytowy.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

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

// BOM UTF-8 — bez niego polski Excel pokazuje krzaki zamiast ą/ę w nazwach ryb.
echo "\xEF\xBB\xBF";
echo $csv;

exit;
