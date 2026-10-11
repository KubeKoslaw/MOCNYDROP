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

    /** Upgrader (UC_UPGRADE): marża wchodząca w wzór na szansę. */
    public const KEY_UPGRADER_MARGIN = 'upgrader_marza';

    /** Trader (UC_TRADE): limit przedmiotów i próg wartości kontraktu. */
    public const KEY_TRADER_MIN_ITEMS = 'trader_min_przedmiotow';
    public const KEY_TRADER_MAX_ITEMS = 'trader_max_przedmiotow';
    public const KEY_TRADER_MIN_VALUE = 'trader_min_wartosc';

    /** Monitoring (UC_ADM3): progi alertu «podejrzane serie wygranych». */
    public const KEY_ALERT_STREAK = 'alert_seria_dlugosc';
    public const KEY_ALERT_RARITY_ORDER = 'alert_rzadkosc_kolejnosc';
    public const KEY_ALERT_RTP = 'alert_rtp_prog';
    public const KEY_ALERT_MIN_DRAWS = 'alert_min_losowan';

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
