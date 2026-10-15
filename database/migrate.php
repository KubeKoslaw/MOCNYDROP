<?php

declare(strict_types=1);

/**
 * database/migrate.php — skrypt CLI
 *
 * Użycie:
 *   php database/migrate.php            tworzy bazę i ładuje dane startowe
 *   php database/migrate.php --fresh    kasuje bazę i buduje ją od zera
 *
 * Na co dzień nie jest potrzebny: backend tworzy bazę automatycznie przy
 * pierwszym żądaniu. Ten skrypt służy do resetu po zmianie schematu albo
 * do sprawdzenia, czy CSV-y z database/seed/ ładują się bez ostrzeżeń.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

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

$dbFile = Config::databaseFile();

if ($fresh) {
    // WAL zostawia obok bazy pliki -wal i -shm; bez ich usunięcia SQLite
    // potrafi odtworzyć stare dane do świeżo utworzonej bazy.
    $allUnlinked = true;
    foreach ([$dbFile, $dbFile . '-journal', $dbFile . '-wal', $dbFile . '-shm'] as $stale) {
        if (is_file($stale)) {
            if (@unlink($stale)) {
                echo '  usunięto: ' . basename($stale) . PHP_EOL;
            } else {
                $allUnlinked = false;
            }
        }
    }
}

echo 'Baza: ' . $dbFile . PHP_EOL;

$db = Database::connection();

if ($fresh && isset($allUnlinked) && !$allUnlinked) {
    // Jeśli plik bazy był zablokowany przez działający serwer www,
    // czyścimy tabele SQL-em, aby --fresh zawsze skutecznie resetował stan.
    $db->exec('PRAGMA foreign_keys = OFF');
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name != 'sqlite_sequence'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $db->exec("DROP TABLE IF EXISTS \"$table\"");
    }
    $db->exec('PRAGMA foreign_keys = ON');
    echo '  zresetowano tabele w bazie (serwer był aktywny).' . PHP_EOL;
}

// Idempotentne — schemat używa IF NOT EXISTS, a dane słowników INSERT OR IGNORE.
Database::applySchema($db);

echo 'Schemat: OK' . PHP_EOL;

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

// Stan końcowy — bez przedmiotów w puli wędki zarzut zwróci "rod_empty",
// więc warto to zobaczyć od razu, a nie dopiero w przeglądarce.
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
