<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;

final class Settings
{

    public const KEY_START_BALANCE = 'saldo_startowe';
    public const KEY_CURRENCY = 'waluta';
    public const KEY_REEL_LENGTH = 'reel_dlugosc';
    public const KEY_REEL_WINNER_INDEX = 'reel_indeks_wygranej';
    public const KEY_OAUTH_CODE_SECONDS = 'oauth_kod_waznosc_sekund';
    public const KEY_OAUTH_ACCESS_SECONDS = 'oauth_access_waznosc_sekund';
    public const KEY_OAUTH_REFRESH_DAYS = 'oauth_refresh_waznosc_dni';

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

        if ($value === false || $value === null || trim((string) $value) === '') {
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
