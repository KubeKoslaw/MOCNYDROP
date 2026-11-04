<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;

final class SeedLoader
{

    private const FILES = [
        'wedki.csv' => 'loadRods',
        'przedmioty.csv' => 'loadItems',
        'wedka_przedmioty.csv' => 'loadRodItems',
        'skrzynki.csv' => 'loadCases',
        'skrzynka_przedmioty.csv' => 'loadCaseItems',
    ];

    public static function load(PDO $db, ?string $dir = null, array &$warnings = []): array
    {
        $dir ??= Config::seedDir();
        $counts = [];

        if (!is_dir($dir)) {
            $warnings[] = 'Brak katalogu seed: ' . $dir . ' — pomijam (to domena @moxmar26).';

            return ['wczytane' => $counts, 'ostrzezenia' => $warnings];
        }

        foreach (self::FILES as $file => $method) {
            $path = $dir . '/' . $file;

            if (!is_file($path)) {
                $warnings[] = 'Brak pliku ' . $file . ' — pomijam.';
                continue;
            }

            $counts[$file] = self::$method($db, $path, $warnings);

            if ($file === 'wedka_przedmioty.csv') {
                $counts['wedki jako przedmioty'] = self::createRodItems($db, $warnings);
            }
        }

        self::warnAboutBrokenIcons($db, $warnings);

        return ['wczytane' => $counts, 'ostrzezenia' => $warnings];
    }

    private static function readCsv(string $path, array &$warnings): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            $warnings[] = 'Nie udało się otworzyć ' . basename($path);

            return [];
        }

        $header = fgetcsv($handle, 0, ',', '"', '\\');

        if (!is_array($header) || $header === []) {
            fclose($handle);
            $warnings[] = basename($path) . ' jest pusty albo nie ma nagłówków.';

            return [];
        }

        $header = array_map(static fn ($col): string => trim(self::stripBom((string) $col)), $header);

        $rows = [];

        while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (!is_array($data) || self::isEmptyRow($data)) {
                continue;
            }

            if (count($data) !== count($header)) {
                $warnings[] = sprintf(
                    '%s wiersz %d: oczekiwano %d kolumn, jest %d — wiersz pominięty.',
                    basename($path),
                    count($rows) + 2,
                    count($header),
                    count($data)
                );
                continue;
            }

            $rows[] = array_map(
                static fn ($value): string => trim((string) $value),
                array_combine($header, $data)
            );
        }

        fclose($handle);

        return $rows;
    }

    private static function loadRods(PDO $db, string $path, array &$warnings): int
    {
        $rows = self::readCsv($path, $warnings);
        self::$rodRarities = [];

        $stmt = $db->prepare(Database::insertIfAbsent($db, 'INSERT INTO wedki (nazwa, opis, koszt_uzycia, ikona, czy_aktywna, kolejnosc, limit_zarzutow)
             VALUES (?, ?, ?, ?, 1, ?, ?)'));

        $loaded = 0;

        foreach ($rows as $row) {
            $nazwa = $row['nazwa'] ?? '';

            if ($nazwa === '') {
                $warnings[] = 'wedki.csv: wiersz bez nazwy — pominięty.';
                continue;
            }

            $koszt = self::toFloat($row['koszt_uzycia'] ?? '0', 0.0);

            if ($koszt < 0) {
                $warnings[] = sprintf('wedki.csv: "%s" ma ujemny koszt — pominięty.', $nazwa);
                continue;
            }

            $stmt->execute([
                $nazwa,
                $row['opis'] ?? '',
                $koszt,
                $row['ikona'] ?? '',
                (int) self::toFloat($row['kolejnosc'] ?? '0', 0.0),
                max(1, (int) self::toFloat($row['limit_zarzutow'] ?? '10', 10.0)),
            ]);

            self::$rodRarities[$nazwa] = trim($row['rzadkosc_kod'] ?? '') ?: 'pospolita';

            $loaded++;
        }

        return $loaded;
    }

    private static array $rodRarities = [];

    private static function createRodItems(PDO $db, array &$warnings): int
    {
        $items = self::nameToIdMap($db, 'przedmioty');
        $rarities = array_map(static fn (Rzadkosc $r): string => $r->kod, Rzadkosc::wszystkie($db));
        $created = 0;

        foreach ($db->query('SELECT id FROM wedki WHERE przedmiot_id IS NULL')->fetchAll() as $row) {
            $wedka = Wedka::wymagaj((int) $row['id'], $db);

            if (isset($items[$wedka->nazwa])) {
                $warnings[] = sprintf('wedki.csv: "%s" — w katalogu jest już przedmiot o tej nazwie, wędka nie trafi do skrzynek.', $wedka->nazwa);
                continue;
            }

            $kod = self::$rodRarities[$wedka->nazwa] ?? 'pospolita';

            if (!in_array($kod, $rarities, true)) {
                $warnings[] = sprintf('wedki.csv: "%s" ma nieznany rzadkosc_kod "%s" — użyto "pospolita".', $wedka->nazwa, $kod);
                $kod = 'pospolita';
            }

            $wedka->utworzPrzedmiot($kod, $db);
            $created++;
        }

        return $created;
    }

    private static function loadItems(PDO $db, string $path, array &$warnings): int
    {
        $rows = self::readCsv($path, $warnings);

        $stmt = $db->prepare(Database::insertIfAbsent($db, 'INSERT INTO przedmioty (nazwa, rzadkosc_kod, wartosc, ikona, opis, czy_aktywny)
             VALUES (?, ?, ?, ?, ?, 1)'));

        $loaded = 0;

        foreach ($rows as $row) {
            $nazwa = $row['nazwa'] ?? '';
            $kod = $row['rzadkosc_kod'] ?? '';

            if ($nazwa === '') {
                $warnings[] = 'przedmioty.csv: wiersz bez nazwy — pominięty.';
                continue;
            }

            if (!Rzadkosc::exists($kod, $db)) {
                $warnings[] = sprintf(
                    'przedmioty.csv: "%s" ma nieznany rzadkosc_kod "%s" (dozwolone: %s) — pominięty.',
                    $nazwa,
                    $kod,
                    implode(', ', array_keys(Rzadkosc::wszystkie($db)))
                );
                continue;
            }

            $wartosc = self::toFloat($row['wartosc'] ?? '0', 0.0);

            if ($wartosc < 0) {
                $warnings[] = sprintf('przedmioty.csv: "%s" ma ujemną wartość — pominięty.', $nazwa);
                continue;
            }

            $stmt->execute([$nazwa, $kod, $wartosc, $row['ikona'] ?? '', $row['opis'] ?? '']);

            $loaded++;
        }

        return $loaded;
    }

    private static function loadRodItems(PDO $db, string $path, array &$warnings): int
    {
        $rows = self::readCsv($path, $warnings);

        $rodIds = self::nameToIdMap($db, 'wedki');
        $itemIds = self::nameToIdMap($db, 'przedmioty');

        $stmt = $db->prepare(Database::insertIfAbsent($db, 'INSERT INTO wedka_przedmioty (wedka_id, przedmiot_id, waga) VALUES (?, ?, ?)'));

        $loaded = 0;

        foreach ($rows as $row) {
            $rodName = $row['wedka_nazwa'] ?? '';
            $itemName = $row['przedmiot_nazwa'] ?? '';

            if (!isset($rodIds[$rodName])) {
                $warnings[] = sprintf('wedka_przedmioty.csv: nieznana wędka "%s" — wiersz pominięty.', $rodName);
                continue;
            }

            if (!isset($itemIds[$itemName])) {
                $warnings[] = sprintf('wedka_przedmioty.csv: nieznany przedmiot "%s" — wiersz pominięty.', $itemName);
                continue;
            }

            $rawWaga = trim($row['waga'] ?? '');

            $waga = null;

            if ($rawWaga !== '') {
                $parsed = self::toFloat($rawWaga, 0.0);

                if ($parsed <= 0) {
                    $warnings[] = sprintf(
                        'wedka_przedmioty.csv: "%s" / "%s" ma wagę %s — musi być dodatnia albo pusta.',
                        $rodName,
                        $itemName,
                        $rawWaga
                    );
                    continue;
                }

                $waga = $parsed;
            }

            $stmt->execute([$rodIds[$rodName], $itemIds[$itemName], $waga]);

            $loaded++;
        }

        return $loaded;
    }

    private static function loadCases(PDO $db, string $path, array &$warnings): int
    {
        $rows = self::readCsv($path, $warnings);

        $stmt = $db->prepare(Database::insertIfAbsent($db, 'INSERT INTO skrzynki (nazwa, opis, cena, ikona, aktywna, kolejnosc)
             VALUES (?, ?, ?, ?, 1, ?)'));

        $loaded = 0;

        foreach ($rows as $row) {
            $nazwa = $row['nazwa'] ?? '';

            if ($nazwa === '') {
                $warnings[] = 'skrzynki.csv: wiersz bez nazwy — pominięty.';
                continue;
            }

            $cena = self::toFloat($row['cena'] ?? '0', 0.0);

            if ($cena < 0) {
                $warnings[] = sprintf('skrzynki.csv: "%s" ma ujemną cenę — pominięty.', $nazwa);
                continue;
            }

            $stmt->execute([
                $nazwa,
                $row['opis'] ?? '',
                $cena,
                $row['ikona'] ?? '',
                (int) self::toFloat($row['kolejnosc'] ?? '0', 0.0),
            ]);

            $loaded++;
        }

        return $loaded;
    }

    private static function loadCaseItems(PDO $db, string $path, array &$warnings): int
    {
        $rows = self::readCsv($path, $warnings);

        $caseIds = self::nameToIdMap($db, 'skrzynki');
        $itemIds = self::nameToIdMap($db, 'przedmioty');

        $stmt = $db->prepare(Database::insertIfAbsent($db, 'INSERT INTO skrzynka_przedmioty (skrzynka_id, przedmiot_id, waga) VALUES (?, ?, ?)'));

        $loaded = 0;

        foreach ($rows as $row) {
            $caseName = $row['skrzynka_nazwa'] ?? '';
            $itemName = $row['przedmiot_nazwa'] ?? '';

            if (!isset($caseIds[$caseName])) {
                $warnings[] = sprintf('skrzynka_przedmioty.csv: nieznana skrzynka "%s" — wiersz pominięty.', $caseName);
                continue;
            }

            if (!isset($itemIds[$itemName])) {
                $warnings[] = sprintf('skrzynka_przedmioty.csv: nieznany przedmiot "%s" — wiersz pominięty.', $itemName);
                continue;
            }

            $rawWaga = trim($row['waga'] ?? '');
            $waga = null;

            if ($rawWaga !== '') {
                $parsed = self::toFloat($rawWaga, 0.0);

                if ($parsed <= 0) {
                    $warnings[] = sprintf(
                        'skrzynka_przedmioty.csv: "%s" / "%s" ma wagę %s — musi być dodatnia albo pusta.',
                        $caseName,
                        $itemName,
                        $rawWaga
                    );
                    continue;
                }

                $waga = $parsed;
            }

            $stmt->execute([$caseIds[$caseName], $itemIds[$itemName], $waga]);

            $loaded++;
        }

        return $loaded;
    }

    private static function nameToIdMap(PDO $db, string $table): array
    {
        if (!in_array($table, ['wedki', 'przedmioty', 'skrzynki'], true)) {
            throw ApiException::badRequest('Nieobsługiwana tabela: ' . $table);
        }

        $map = [];

        foreach ($db->query('SELECT id, nazwa FROM ' . $table)->fetchAll() as $row) {
            $map[(string) $row['nazwa']] = (int) $row['id'];
        }

        return $map;
    }

    private static function warnAboutBrokenIcons(PDO $db, array &$warnings): void
    {
        $rows = $db->query("SELECT nazwa, ikona FROM przedmioty WHERE ikona != ''")->fetchAll();

        foreach ($rows as $row) {
            $ikona = (string) $row['ikona'];

            if (!Config::iconExists($ikona)) {
                $warnings[] = sprintf(
                    'Przedmiot "%s" wskazuje ikonę "%s", której nie ma w %s/.',
                    (string) $row['nazwa'],
                    $ikona,
                    basename(Config::iconsDir())
                );
            }
        }
    }

    private static function toFloat(string $raw, float $default): float
    {
        $raw = trim($raw);

        if ($raw === '') {
            return $default;
        }

        $normalized = str_replace([' ', ','], ['', '.'], $raw);

        return is_numeric($normalized) ? (float) $normalized : $default;
    }

    private static function isEmptyRow(array $data): bool
    {
        foreach ($data as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }
}
