<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;
use Throwable;

/** Migracja v2 zachowuje istniejące ID, przedmioty i historię otwarć skrzynek. */
final class Migracje
{
    public static function zastosuj(PDO $db, string $schema): void
    {
        $db->exec('PRAGMA foreign_keys = OFF');
        $db->beginTransaction();
        try {
            // Pierwszy zapis blokuje konkurencyjną migrację przed odczytem schematu.
            $db->exec('CREATE TABLE IF NOT EXISTS migracje (wersja INTEGER PRIMARY KEY)');
            $db->exec('INSERT OR IGNORE INTO migracje VALUES (0)');
            $old = $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='wedki'")->fetchColumn();
            if ($old && !in_array('czy_startowa', self::kolumny($db, 'wedki'), true)) {
                $db->exec("ALTER TABLE wedki ADD COLUMN czy_startowa INTEGER NOT NULL DEFAULT 0 CHECK (czy_startowa IN (0,1))");
                $db->exec("ALTER TABLE wedki ADD COLUMN rzadkosc_kod TEXT NOT NULL DEFAULT 'pospolita' REFERENCES rzadkosci(kod)");
                $db->exec('ALTER TABLE wedki ADD COLUMN rekompensata REAL NOT NULL DEFAULT 0 CHECK (rekompensata >= 0)');
            }
            if ($old && !in_array('dzienna', self::kolumny($db, 'skrzynki'), true)) {
                $db->exec('ALTER TABLE skrzynki ADD COLUMN dzienna INTEGER NOT NULL DEFAULT 0 CHECK (dzienna IN (0,1))');
            }
            if ($old && !in_array('wynik_wedka_id', self::kolumny($db, 'historia_losowan'), true)) {
                preg_match('/CREATE TABLE IF NOT EXISTS historia_losowan \([\s\S]*?\n\);/', $schema, $match);
                $db->exec(str_replace('historia_losowan (', 'historia_losowan_v2 (', $match[0]));
                $cols = implode(', ', self::kolumny($db, 'historia_losowan'));
                $db->exec("INSERT INTO historia_losowan_v2 ($cols) SELECT $cols FROM historia_losowan");
                $db->exec('DROP TABLE historia_losowan');
                $db->exec('ALTER TABLE historia_losowan_v2 RENAME TO historia_losowan');
            }
            // PRAGMA w schema.sql jest ignorowana wewnątrz transakcji; włączamy w finally.
            $db->exec($schema);
            $db->exec('INSERT OR IGNORE INTO migracje VALUES (2)');
            $db->exec('PRAGMA user_version = 2');
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        } finally {
            $db->exec('PRAGMA foreign_keys = ON');
        }
    }

    private static function kolumny(PDO $db, string $table): array
    {
        return array_column($db->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    }
}
