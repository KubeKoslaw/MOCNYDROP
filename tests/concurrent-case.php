<?php
// Pomocnik procesu dla testu współbieżności; uruchamiany wyłącznie z CLI.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__).'/backend/bootstrap.php';
use MocnyDrop\{ApiException, Database, Skrzynka, Uzytkownik};
$db = new PDO('sqlite:'.$argv[1]);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
Database::setConnection($db);
$deadline = microtime(true) + 5;
while (!is_file($argv[5]) && microtime(true) < $deadline) usleep(10000);
try {
    $result = Skrzynka::wymagaj((int) $argv[3])->otworzSzczegolowo(Uzytkownik::znajdz((int) $argv[2]), requestId: $argv[4]);
    echo json_encode(['ok' => true, 'id' => $result['otwarcie']['id']]);
} catch (ApiException $e) {
    echo json_encode(['ok' => false, 'kod' => $e->errorCode()]);
}
