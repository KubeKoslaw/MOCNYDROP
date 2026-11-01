<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;

final class Portfel
{

    public float $saldo;

    public function __construct(
        public readonly int $id,
        public readonly int $uzytkownikId,
        float $saldo,
        public readonly string $waluta,
        private ?PDO $db = null,
    ) {
        $this->saldo = $saldo;
    }

    public function czyStac(float $koszt): bool
    {
        $this->odswiez();

        return $koszt >= 0 && $this->saldo >= $koszt;
    }

    public function pobierzOplate(float $kwota): bool
    {
        self::sprawdzKwote($kwota);

        $db = $this->polaczenie();

        $stmt = $db->prepare('UPDATE portfele SET saldo = saldo - ? WHERE user_id = ? AND saldo >= ?');
        $stmt->execute([$kwota, $this->uzytkownikId, $kwota]);

        if ($stmt->rowCount() === 0 && $kwota > 0.0) {
            $this->odswiez();

            return false;
        }

        $this->odswiez();

        return true;
    }

    public function dodajSrodki(float $kwota): void
    {
        self::sprawdzKwote($kwota);

        $db = $this->polaczenie();

        $stmt = $db->prepare('UPDATE portfele SET saldo = saldo + ? WHERE user_id = ?');
        $stmt->execute([$kwota, $this->uzytkownikId]);

        if ($stmt->rowCount() === 0 && !self::istnieje($this->uzytkownikId, $db)) {
            throw ApiException::notFound('Gracz nie istnieje.', 'user_not_found');
        }

        $this->odswiez();
    }

    public static function dlaUzytkownika(int $userId, ?PDO $pdo = null): self
    {
        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare(Database::insertIfAbsent($db, 'INSERT INTO portfele (user_id, saldo, waluta) VALUES (?, 0, ?)'));
        $stmt->execute([$userId, Settings::get(Settings::KEY_CURRENCY, 'monety', $db)]);

        return self::wczytaj($userId, $db);
    }

    public static function utworz(int $userId, ?float $saldoStartowe = null, ?PDO $pdo = null): self
    {
        $db = $pdo ?? Database::connection();

        $saldo = $saldoStartowe ?? Settings::getFloat(Settings::KEY_START_BALANCE, 50.0, $db);

        $stmt = $db->prepare(Database::upsert(
            $db, 'INSERT INTO portfele (user_id, saldo, waluta) VALUES (?, ?, ?)', 'user_id', 'saldo'
        ));
        $stmt->execute([$userId, $saldo, Settings::get(Settings::KEY_CURRENCY, 'monety', $db)]);

        return self::wczytaj($userId, $db);
    }

    public function doApi(): array
    {
        return [
            'saldo' => round($this->saldo, 2),
            'waluta' => $this->waluta,
        ];
    }

    public function odswiez(): void
    {
        $this->saldo = self::wczytaj($this->uzytkownikId, $this->polaczenie())->saldo;
    }

    private static function wczytaj(int $userId, PDO $db): self
    {
        $stmt = $db->prepare('SELECT id, user_id, saldo, waluta FROM portfele WHERE user_id = ?');
        $stmt->execute([$userId]);

        $row = $stmt->fetch();

        if ($row === false) {
            throw ApiException::notFound('Gracz nie ma portfela.', 'wallet_not_found');
        }

        return new self((int) $row['id'], (int) $row['user_id'], (float) $row['saldo'], (string) $row['waluta'], $db);
    }

    private static function istnieje(int $userId, PDO $db): bool
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM portfele WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private static function sprawdzKwote(float $kwota): void
    {
        if (!is_finite($kwota) || $kwota < 0) {
            throw ApiException::badRequest('Kwota musi być nieujemną, skończoną liczbą.');
        }
    }

    private function polaczenie(): PDO
    {
        return $this->db ??= Database::connection();
    }
}
