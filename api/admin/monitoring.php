<?php

declare(strict_types=1);

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
