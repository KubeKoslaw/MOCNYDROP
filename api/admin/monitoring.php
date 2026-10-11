<?php

declare(strict_types=1);

/**
 * api/admin/monitoring.php — GET /api/admin/monitoring.php
 * Nagłówek: Authorization: Bearer <token> (konto z rola = 'admin')
 *
 * MONITORING AKTYWNOŚCI — przypadek użycia UC_ADM3 z diagramu use case
 * i „Monitoring Aktywności" ze strony 3 diagramu MOCNYDROP_PROJEKT.
 *
 * Pokrywa wszystkie trzy elementy z diagramu:
 *   ADM3_S1  «Przegląd statystyk wygranych»  -> Losowanie::statystyki() (RTP per tryb)
 *   ADM3_EXT «Alert: podejrzane serie wygranych» -> Losowanie::podejrzaneSerie()
 *   «Przegląd pełnego dziennika audytowego»  -> LogAudytowy::ostatnie()
 *
 * Query (opcjonalne):
 *   ?limit_logu=50     liczba wpisów dziennika audytowego (1–500)
 *   ?okno=500          liczba ostatnich zdarzeń branych pod uwagę przy seriach
 *
 * «Eksport raportu» (ADM3_S3) jest osobno: api/admin/raport.php.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\Administrator;
use MocnyDrop\Json;
use MocnyDrop\LogAudytowy;
use MocnyDrop\Losowanie;

require_once dirname(__DIR__) . '/_bootstrap.php';

Json::requireMethod('GET');

$admin = Administrator::zalogowany();

$rawLimit = $_GET['limit_logu'] ?? '50';
$limitLogu = is_scalar($rawLimit) && preg_match('/^\d+$/', trim((string) $rawLimit)) === 1
    ? (int) trim((string) $rawLimit)
    : 50;

$rawOkno = $_GET['okno'] ?? '500';
$okno = is_scalar($rawOkno) && preg_match('/^\d+$/', trim((string) $rawOkno)) === 1
    ? (int) trim((string) $rawOkno)
    : 500;

$alerty = Losowanie::podejrzaneSerie(null, $okno);

Json::success([
    'admin' => $admin->doPublicznej(),
    'statystyki' => Losowanie::statystyki(),
    'alerty' => $alerty['alerty'],
    'progi_alertow' => $alerty['progi'],
    'dziennik_audytowy' => LogAudytowy::ostatnie($limitLogu),
    'eksport' => 'api/admin/raport.php?format=csv|json',
]);
