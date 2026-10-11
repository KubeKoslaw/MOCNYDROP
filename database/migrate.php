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
    foreach ([$dbFile, $dbFile . '-journal', $dbFile . '-wal', $dbFile . '-shm'] as $stale) {
        if (is_file($stale) && unlink($stale)) {
            echo '  usunięto: ' . basename($stale) . PHP_EOL;
        }
    }
}

echo 'Baza: ' . $dbFile . PHP_EOL;

$db = Database::connection();

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

// Stan końcowy — bez ryb w łowiskach zarzut zwróci "spot_empty",
// więc warto to zobaczyć od razu, a nie dopiero w przeglądarce.
$counts = [
    'łowiska' => (int) $db->query('SELECT COUNT(*) FROM lowiska')->fetchColumn(),
    'ryby' => (int) $db->query('SELECT COUNT(*) FROM ryby')->fetchColumn(),
    'połączenia łowisko-ryba' => (int) $db->query('SELECT COUNT(*) FROM lowisko_ryby')->fetchColumn(),
    'użytkownicy' => (int) $db->query('SELECT COUNT(*) FROM uzytkownicy')->fetchColumn(),
];

echo PHP_EOL . 'Stan bazy:' . PHP_EOL;

foreach ($counts as $label => $count) {
    echo sprintf('  %-26s %d%s', $label, $count, PHP_EOL);
}

$lowiskaBezRyb = (int) $db->query(
    'SELECT COUNT(*) FROM lowiska l
     WHERE l.czy_aktywne = 1
       AND NOT EXISTS (SELECT 1 FROM lowisko_ryby lr
                       JOIN ryby r ON r.id = lr.ryba_id AND r.czy_aktywna = 1
                       WHERE lr.lowisko_id = l.id)'
)->fetchColumn();

if ($lowiskaBezRyb > 0) {
    echo PHP_EOL . '  UWAGA: ' . $lowiskaBezRyb . ' aktywnych łowisk nie ma ani jednej ryby.'
        . ' Zarzut zwróci błąd "spot_empty".' . PHP_EOL;
}

$brakIkony = (int) $db->query(
    "SELECT COUNT(*) FROM ryby WHERE ikona != ''"
)->fetchColumn();

echo PHP_EOL . sprintf('Ikon w użyciu: %d (katalog: %s)%s', $brakIkony, Config::iconsDir(), PHP_EOL);
echo 'Gotowe.' . PHP_EOL;
