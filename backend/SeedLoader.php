<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;

/**
 * backend/SeedLoader.php
 *
 * Ładuje dane startowe z database/seed/*.csv.
 *
 * PODZIAŁ RÓL: katalog database/seed/ należy do @moxmar26 (CODEOWNERS) —
 * on decyduje, jakie przedmioty i wędki są w grze. Ten loader jest po stronie
 * backendu i tylko wczytuje to, co tam zastanie. Backend nie hardkoduje
 * żadnego przedmiotu, więc zmiana treści nie wymaga zmiany kodu.
 *
 * Obsługiwane pliki i ich kolumny (pierwszy wiersz = nagłówki):
 *
 *   wedki.csv       nazwa,opis,koszt_uzycia,ikona,kolejnosc
 *   przedmioty.csv    nazwa,rzadkosc_kod,wartosc,ikona,opis
 *   wedka_przedmioty.csv  wedka_nazwa,przedmiot_nazwa,waga
 *   skrzynki.csv    nazwa,opis,cena,ikona,kolejnosc
 *   skrzynka_przedmioty.csv  skrzynka_nazwa,przedmiot_nazwa,waga
 *
 * W plikach *_przedmioty.csv kolumna `waga` może być pusta — wtedy przedmiot
 * dziedziczy wagę domyślną swojego poziomu rzadkości z README.md.
 *
 * Wszystkie inserty są INSERT OR IGNORE, więc loader można odpalać wielokrotnie.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class SeedLoader
{
    /** Plik seed => metoda loadera. Kolejność ma znaczenie: najpierw rodzice. */
    private const FILES = [
        'wedki.csv' => 'loadRods',
        'przedmioty.csv' => 'loadItems',
        'wedka_przedmioty.csv' => 'loadRodItems',
        'skrzynki.csv' => 'loadCases',
        'skrzynka_przedmioty.csv' => 'loadCaseItems',
    ];

    /**
     * @param list<string> $warnings uzupełniana przez referencję
     * @return array<string, mixed> podsumowanie: liczby wierszy i ostrzeżenia
     */
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
        }

        self::warnAboutBrokenIcons($db, $warnings);

        return ['wczytane' => $counts, 'ostrzezenia' => $warnings];
    }

    /**
     * Czyta CSV i zwraca listę wierszy jako tablice klucz => wartość.
     * Wymaga nagłówków w pierwszym wierszu.
     *
     * @param list<string> $warnings
     * @return list<array<string, string>>
     */
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

        // BOM z Excela potrafi dokleić \xEF\xBB\xBF do pierwszej nazwy kolumny,
        // przez co nagłówek "nazwa" staje się nie do rozpoznania.
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

    /**
     * wedki.csv → tabela `wedki`.
     *
     * @param list<string> $warnings
     */
    private static function loadRods(PDO $db, string $path, array &$warnings): int
    {
        $rows = self::readCsv($path, $warnings);

        $stmt = $db->prepare(
            'INSERT OR IGNORE INTO wedki (nazwa, opis, koszt_uzycia, ikona, czy_aktywna, kolejnosc)
             VALUES (?, ?, ?, ?, 1, ?)'
        );

        $loaded = 0;

        foreach ($rows as $row) {
            $nazwa = $row['nazwa'] ?? '';

            if ($nazwa === '') {
                $warnings[] = 'wedki.csv: wiersz bez nazwy — pominięty.';
                continue;
            }

            $koszt = self::toFloat($row['koszt_uzycia'] ?? '0', 0.0);

            // Schemat ma CHECK (koszt_uzycia >= 0), więc taki INSERT wywaliłby
            // się i przerwał całe ładowanie. Lepiej ostrzec i pominąć wiersz.
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
            ]);

            $loaded++;
        }

        return $loaded;
    }

    /**
     * przedmioty.csv → tabela `przedmioty`.
     *
     * @param list<string> $warnings
     */
    private static function loadItems(PDO $db, string $path, array &$warnings): int
    {
        $rows = self::readCsv($path, $warnings);

        $stmt = $db->prepare(
            'INSERT OR IGNORE INTO przedmioty (nazwa, rzadkosc_kod, wartosc, ikona, opis, czy_aktywny)
             VALUES (?, ?, ?, ?, ?, 1)'
        );

        $loaded = 0;

        foreach ($rows as $row) {
            $nazwa = $row['nazwa'] ?? '';
            $kod = $row['rzadkosc_kod'] ?? '';

            if ($nazwa === '') {
                $warnings[] = 'przedmioty.csv: wiersz bez nazwy — pominięty.';
                continue;
            }

            // Klucz obcy na rzadkosci jest ON DELETE RESTRICT, więc literówka
            // w kodzie rzadkości wywaliłaby cały import. Sprawdzamy wcześniej.
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

    /**
     * wedka_przedmioty.csv → tabela `wedka_przedmioty`.
     * CSV odwołuje się do nazw, nie do id — id zależą od kolejności importu.
     *
     * @param list<string> $warnings
     */
    private static function loadRodItems(PDO $db, string $path, array &$warnings): int
    {
        $rows = self::readCsv($path, $warnings);

        $rodIds = self::nameToIdMap($db, 'wedki');
        $itemIds = self::nameToIdMap($db, 'przedmioty');

        $stmt = $db->prepare(
            'INSERT INTO wedka_przedmioty (wedka_id, przedmiot_id, waga) VALUES (?, ?, ?)
             ON CONFLICT(wedka_id, przedmiot_id) DO UPDATE SET waga = excluded.waga'
        );

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

            // Pusta waga = NULL w bazie = dziedziczenie waga_domyslna poziomu
            // rzadkości. Celowe: @moxmar26 nie musi przepisywać wag z README.
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

    /**
     * skrzynki.csv → tabela `skrzynki` (klasa Skrzynka z diagramu).
     *
     * Tryb «Otwarcie skrzynki» (UC_CASE) — te same zasady co przy wędkach:
     * ujemna cena jest odrzucana z ostrzeżeniem, bo schemat ma CHECK (cena >= 0).
     *
     * @param list<string> $warnings
     */
    private static function loadCases(PDO $db, string $path, array &$warnings): int
    {
        $rows = self::readCsv($path, $warnings);

        $stmt = $db->prepare(
            'INSERT OR IGNORE INTO skrzynki (nazwa, opis, cena, ikona, aktywna, kolejnosc)
             VALUES (?, ?, ?, ?, 1, ?)'
        );

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

    /**
     * skrzynka_przedmioty.csv → tabela `skrzynka_przedmioty` (klasa
     * SkrzynkaPrzedmiot z diagramu).
     *
     * CSV odwołuje się do NAZW, nie do id — id zależą od kolejności importu.
     * Pusta waga = NULL = dziedziczenie wagi domyślnej poziomu rzadkości.
     *
     * @param list<string> $warnings
     */
    private static function loadCaseItems(PDO $db, string $path, array &$warnings): int
    {
        $rows = self::readCsv($path, $warnings);

        $caseIds = self::nameToIdMap($db, 'skrzynki');
        $itemIds = self::nameToIdMap($db, 'przedmioty');

        $stmt = $db->prepare(
            'INSERT INTO skrzynka_przedmioty (skrzynka_id, przedmiot_id, waga) VALUES (?, ?, ?)
             ON CONFLICT(skrzynka_id, przedmiot_id) DO UPDATE SET waga = excluded.waga'
        );

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

    /**
     * Mapa nazwa => id dla tabeli z kolumnami `id` i `nazwa`.
     * Nazwa tabeli pochodzi z naszej listy, nie z wejścia użytkownika,
     * więc doklejenie jej do zapytania nie jest podatne na wstrzyknięcie SQL.
     *
     * @return array<string, int>
     */
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

    /**
     * Wyłapuje literówki w nazwach ikon: przedmiot wskazujący nieistniejący plik
     * pokazałaby w animacji pusty kwadrat. Ostrzegamy zamiast rzucać błąd,
     * bo brak jednej grafiki nie powinien blokować uruchomienia gry.
     *
     * @param list<string> $warnings
     */
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

    /**
     * Bezpieczne rzutowanie na float.
     * Akceptuje polski i angielski separator dziesiętny, bo CSV bywa
     * zapisywane z Excela, a tam przecinek zależy od ustawień regionalnych.
     */
    private static function toFloat(string $raw, float $default): float
    {
        $raw = trim($raw);

        if ($raw === '') {
            return $default;
        }

        $normalized = str_replace([' ', ','], ['', '.'], $raw);

        return is_numeric($normalized) ? (float) $normalized : $default;
    }

    /** @param array<int, mixed> $data */
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
