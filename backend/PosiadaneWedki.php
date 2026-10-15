<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;

final class PosiadaneWedki
{
    /** Przyznaje startowy model nowym i istniejącym graczom, bez duplikatów. */
    public static function startowa(int $userId, PDO $db): void
    {
        $db->prepare('INSERT OR IGNORE INTO uzytkownik_wedki (user_id, wedka_id)
            SELECT ?, id FROM wedki WHERE czy_startowa = 1 AND czy_aktywna = 1')->execute([$userId]);
    }

    public static function ma(int $userId, int $wedkaId, PDO $db): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM uzytkownik_wedki WHERE user_id = ? AND wedka_id = ?');
        $stmt->execute([$userId, $wedkaId]);
        return $stmt->fetchColumn() !== false;
    }

    /** true = nowe odblokowanie; false = duplikat. Wywołujący prowadzi transakcję. */
    public static function przyznaj(int $userId, int $wedkaId, PDO $db): bool
    {
        $stmt = $db->prepare('INSERT OR IGNORE INTO uzytkownik_wedki (user_id, wedka_id) VALUES (?, ?)');
        $stmt->execute([$userId, $wedkaId]);
        return $stmt->rowCount() === 1;
    }
}
