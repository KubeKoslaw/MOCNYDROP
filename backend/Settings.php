<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;

/**
 * backend/Settings.php
 *
 * Odczyt parametrów mechaniki z tabeli `ustawienia`.
 *
 * Dzięki temu długość paska ruletki, saldo startowe czy ważność tokena
 * zmienia się jednym UPDATE-em, bez ruszania kodu. Wartości z bazy są
 * traktowane jako tekst i rzutowane dopiero przy odczycie, a parametr
 * $default pilnuje, żeby pusta tabela nie wywróciła gry.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class Settings
{
    /** Klucze używane przez backend — jedna lista, żeby nie szukać po kodzie. */
    public const KEY_START_BALANCE = 'saldo_startowe';
    public const KEY_CURRENCY = 'waluta';
    public const KEY_REEL_LENGTH = 'reel_dlugosc';
    public const KEY_REEL_WINNER_INDEX = 'reel_indeks_wygranej';
    public const KEY_TOKEN_HOURS = 'token_waznosc_godz';

    public static function get(string $key, string $default = '', ?PDO $pdo = null): string
    {
        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare('SELECT wartosc FROM ustawienia WHERE klucz = ?');
        $stmt->execute([$key]);

        $value = $stmt->fetchColumn();

        if ($value === false || $value === null) {
            return $default;
        }

        return (string) $value;
    }

    public static function getInt(string $key, int $default, ?PDO $pdo = null): int
    {
        $raw = self::get($key, '', $pdo);

        if (trim($raw) === '' || !is_numeric(trim($raw))) {
            return $default;
        }

        return (int) round((float) $raw);
    }

    public static function getFloat(string $key, float $default, ?PDO $pdo = null): float
    {
        $raw = self::get($key, '', $pdo);

        if (trim($raw) === '' || !is_numeric(trim($raw))) {
            return $default;
        }

        return (float) $raw;
    }
}
