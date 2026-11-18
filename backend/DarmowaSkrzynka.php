<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;
use Throwable;

final class DarmowaSkrzynka
{
    public const COOLDOWN_SEKUND = 86400;

    public static function status(int $userId, ?int $teraz = null, ?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();
        $teraz ??= time();

        self::upewnijSieOTabeli($db);

        $stmt = $db->prepare('SELECT odebrano FROM darmowe_skrzynki WHERE user_id = ?');
        $stmt->execute([$userId]);
        $odebrano = $stmt->fetchColumn();

        $zaSekund = 0;

        if (is_string($odebrano) && $odebrano !== '') {
            $nastepna = (int) strtotime($odebrano . ' UTC') + self::COOLDOWN_SEKUND;
            $zaSekund = max(0, $nastepna - $teraz);
        }

        $skrzynka = self::skrzynka($db);

        return [
            'dostepna' => $zaSekund === 0 && $skrzynka !== null,
            'za_sekund' => $zaSekund,
            'skrzynka' => $skrzynka === null ? null : [
                'id' => $skrzynka->id,
                'nazwa' => $skrzynka->nazwa,
            ],
        ];
    }

    public static function odbierz(
        Uzytkownik $u,
        ?int $teraz = null,
        ?callable $random = null,
        ?PDO $pdo = null
    ): array {
        $db = $pdo ?? Database::connection();
        $teraz ??= time();

        self::upewnijSieOTabeli($db);

        $skrzynka = self::skrzynka($db);

        if ($skrzynka === null) {
            throw ApiException::badRequest('Darmowa skrzynka jest teraz niedostępna.', 'free_case_unavailable');
        }

        $poprzedni = $db->prepare('SELECT odebrano FROM darmowe_skrzynki WHERE user_id = ?');
        $poprzedni->execute([$u->id]);
        $poprzednio = $poprzedni->fetchColumn();

        $zajmij = $db->prepare(
            'INSERT INTO darmowe_skrzynki (user_id, odebrano) VALUES (:user, :teraz)
             ON CONFLICT (user_id) DO UPDATE SET odebrano = excluded.odebrano
             WHERE darmowe_skrzynki.odebrano <= :granica'
        );
        $zajmij->execute([
            'user' => $u->id,
            'teraz' => gmdate('Y-m-d H:i:s', $teraz),
            'granica' => gmdate('Y-m-d H:i:s', $teraz - self::COOLDOWN_SEKUND),
        ]);

        if ($zajmij->rowCount() === 0) {
            $status = self::status($u->id, $teraz, $db);

            throw ApiException::conflict(
                sprintf('Darmowa skrzynka będzie dostępna za %s.', self::czytelnyCzas($status['za_sekund'])),
                'free_case_cooldown'
            );
        }

        try {
            $wynik = $skrzynka->otworzSzczegolowo($u, false, $random, $db, true);
        } catch (Throwable $e) {
            if (is_string($poprzednio) && $poprzednio !== '') {
                $db->prepare('UPDATE darmowe_skrzynki SET odebrano = ? WHERE user_id = ? AND odebrano = ?')
                    ->execute([$poprzednio, $u->id, gmdate('Y-m-d H:i:s', $teraz)]);
            } else {
                $db->prepare('DELETE FROM darmowe_skrzynki WHERE user_id = ? AND odebrano = ?')
                    ->execute([$u->id, gmdate('Y-m-d H:i:s', $teraz)]);
            }

            throw $e;
        }

        return ['skrzynka' => $skrzynka, 'wynik' => $wynik];
    }

    private static function skrzynka(PDO $db): ?Skrzynka
    {
        $najtansza = null;

        foreach (Skrzynka::aktywne($db) as $skrzynka) {
            if (!$skrzynka->czyDajeSieOtworzyc()) {
                continue;
            }

            if ($najtansza === null || $skrzynka->cena < $najtansza->cena) {
                $najtansza = $skrzynka;
            }
        }

        return $najtansza;
    }

    private static function upewnijSieOTabeli(PDO $db): void
    {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS darmowe_skrzynki (
                user_id     INTEGER PRIMARY KEY,
                odebrano    TEXT    NOT NULL,
                FOREIGN KEY (user_id) REFERENCES uzytkownicy (id) ON DELETE CASCADE
            )'
        );
    }

    private static function czytelnyCzas(int $sekundy): string
    {
        $godziny = intdiv($sekundy, 3600);
        $minuty = intdiv($sekundy % 3600, 60);

        return $godziny > 0
            ? sprintf('%d godz. %d min', $godziny, $minuty)
            : sprintf('%d min', max(1, $minuty));
    }
}
