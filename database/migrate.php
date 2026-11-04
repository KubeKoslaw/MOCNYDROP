<?php

declare(strict_types=1);

use MocnyDrop\Config;
use MocnyDrop\Database;
use MocnyDrop\SeedLoader;

require_once dirname(__DIR__) . '/backend/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ten skrypt można uruchamiać tylko z linii komend.');
}

$flags = array_slice($argv ?? [], 1);
$fresh = in_array('--fresh', $flags, true);

$options = Config::databaseOptions();
$dbFile = Config::databaseFile();

if ($fresh) {

    foreach ([$dbFile, $dbFile . '-journal', $dbFile . '-wal', $dbFile . '-shm'] as $stale) {
        if (is_file($stale) && unlink($stale)) {
            echo '  usunięto: ' . basename($stale) . PHP_EOL;
        }
    }
}

echo 'Baza: SQLite / ' . $dbFile . ' (driver: ' . $options['driver'] . ')' . PHP_EOL;

$db = Database::connection();

Database::applySchema($db);

echo 'Schemat: OK' . PHP_EOL;

$stareTokeny = (int) $db->query(
    "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'tokeny'"
)->fetchColumn();

if ($stareTokeny > 0) {
    $db->exec('DROP TABLE IF EXISTS tokeny');
    echo '  Migracja: usunięto starą tabelę tokeny (sesje przechodzą na OAuth 2.0).' . PHP_EOL;
}

// Starsze wersje gry miały dodatkowe tryby losowania; obecny enum zna tylko
// skrzynkę i wędkę, więc historyczne wpisy przepisujemy na skrzynkę.
$zmigrowane = $db->exec(
    "UPDATE historia_losowan SET typ = 'skrzynka' WHERE typ NOT IN ('skrzynka', 'wedka')"
);

if ($zmigrowane > 0) {
    echo sprintf(
        '  Migracja: %d nieobsługiwanych wpisów historii oznaczono jako „skrzynka”.%s',
        $zmigrowane,
        PHP_EOL
    );
}

$warnings = [];
$seed = SeedLoader::load($db, null, $warnings);

$wczytane = $seed['wczytane'];

if ($wczytane === []) {
    echo 'Seed: brak plików CSV w database/seed/ (dane startowe dokłada @moxmar26).' . PHP_EOL;
} else {
    foreach ($wczytane as $plik => $ile) {
        echo sprintf('Seed: %s -> %d wierszy%s', $plik, (int) $ile, PHP_EOL);
    }
}

foreach ($warnings as $warning) {
    echo '  OSTRZEŻENIE: ' . $warning . PHP_EOL;
}

$counts = [
    'wędki' => (int) $db->query('SELECT COUNT(*) FROM wedki')->fetchColumn(),
    'skrzynki' => (int) $db->query('SELECT COUNT(*) FROM skrzynki')->fetchColumn(),
    'przedmioty' => (int) $db->query('SELECT COUNT(*) FROM przedmioty')->fetchColumn(),
    'połączenia wędka-przedmiot' => (int) $db->query('SELECT COUNT(*) FROM wedka_przedmioty')->fetchColumn(),
    'połączenia skrzynka-przedmiot' => (int) $db->query('SELECT COUNT(*) FROM skrzynka_przedmioty')->fetchColumn(),
    'użytkownicy' => (int) $db->query('SELECT COUNT(*) FROM uzytkownicy')->fetchColumn(),
];

echo PHP_EOL . 'Stan bazy:' . PHP_EOL;

foreach ($counts as $label => $count) {
    echo sprintf('  %-26s %d%s', $label, $count, PHP_EOL);
}

$wedkiBezPuli = (int) $db->query(
    'SELECT COUNT(*) FROM wedki w
     WHERE w.czy_aktywna = 1
       AND NOT EXISTS (SELECT 1 FROM wedka_przedmioty wp
                       JOIN przedmioty p ON p.id = wp.przedmiot_id AND p.czy_aktywny = 1
                       WHERE wp.wedka_id = w.id)'
)->fetchColumn();

if ($wedkiBezPuli > 0) {
    echo PHP_EOL . '  UWAGA: ' . $wedkiBezPuli . ' aktywnych wędek nie ma ani jednego przedmiotu.'
        . ' Zarzut zwróci błąd "rod_empty".' . PHP_EOL;
}

$skrzynkiBezPuli = (int) $db->query(
    'SELECT COUNT(*) FROM skrzynki s
     WHERE s.aktywna = 1
       AND NOT EXISTS (SELECT 1 FROM skrzynka_przedmioty sp
                       JOIN przedmioty p ON p.id = sp.przedmiot_id AND p.czy_aktywny = 1
                       WHERE sp.skrzynka_id = s.id)'
)->fetchColumn();

if ($skrzynkiBezPuli > 0) {
    echo PHP_EOL . '  UWAGA: ' . $skrzynkiBezPuli . ' aktywnych skrzynek nie ma ani jednego dropu.'
        . ' Otwarcie zwróci błąd "case_empty".' . PHP_EOL;
}

$brakIkony = (int) $db->query(
    "SELECT COUNT(*) FROM przedmioty WHERE ikona != ''"
)->fetchColumn();

echo PHP_EOL . sprintf('Ikon w użyciu: %d (katalog: %s)%s', $brakIkony, Config::iconsDir(), PHP_EOL);
echo 'Gotowe.' . PHP_EOL;
