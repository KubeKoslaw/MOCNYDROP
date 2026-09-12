<?php
/**
 * database/init.php
 * Prosty skrypt do zresetowania lub ponownego utworzenia bazy danych SQLite.
 * Można go uruchomić z terminala: php database/init.php
 * albo z poziomu przeglądarki.
 */

require_once __DIR__ . '/../src/Database.php';

$dbFile = __DIR__ . '/mocnydrop.sqlite';

// Opcjonalne usunięcie starej bazy, jeśli chcemy świeży start
if (file_exists($dbFile)) {
    unlink($dbFile);
    echo "Stary plik bazy usunięty.\n";
}

// Inicjalizacja bazy: getConnection() automatycznie wczytuje schema.sql i seed.sql
$pdo = Database::getConnection();

echo "Baza danych SQLite została pomyślnie zainicjalizowana z plikami schema.sql i seed.sql!\n";
echo "Plik bazy: " . realpath($dbFile) . "\n";
