<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;

final class OauthKod
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $kodHash,
        public readonly string $expiresAt,
        public readonly bool $czyZuzyty = false,
        private ?PDO $db = null,
        public readonly ?string $kod = null,
    ) {
    }

    public static function wystaw(int $userId, ?PDO $pdo = null): self
    {
        $db = $pdo ?? Database::connection();

        $sekundy = Settings::getInt(Settings::KEY_OAUTH_CODE_SECONDS, 300, $db);

        if ($sekundy < 1) {
            $sekundy = 300;
        }

        $kod = bin2hex(random_bytes(32));
        $kodHash = self::hashKod($kod);
        $expiresAt = date('Y-m-d H:i:s', time() + $sekundy);

        $stmt = $db->prepare('INSERT INTO oauth_kody (user_id, kod_hash, expires_at) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $kodHash, $expiresAt]);

        self::posprzatajWygasle($db);

        return new self((int) $db->lastInsertId(), $userId, $kodHash, $expiresAt, false, $db, $kod);
    }

    public static function znajdz(string $kod, ?PDO $pdo = null): ?self
    {
        $kod = trim($kod);

        if ($kod === '') {
            return null;
        }

        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare('SELECT id, user_id, kod_hash, expires_at, czy_zuzyty FROM oauth_kody WHERE kod_hash = ?');
        $stmt->execute([self::hashKod($kod)]);

        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return new self(
            (int) $row['id'],
            (int) $row['user_id'],
            (string) $row['kod_hash'],
            (string) $row['expires_at'],
            (int) $row['czy_zuzyty'] === 1,
            $db,
        );
    }

    private static function hashKod(string $kod): string
    {
        return hash('sha256', $kod);
    }

    public function czyWygasl(): bool
    {
        $znacznik = strtotime($this->expiresAt);

        return $znacznik === false || $znacznik < time();
    }

    public function wymien(): int
    {
        $db = $this->polaczenie();

        $stmt = $db->prepare(
            'UPDATE oauth_kody
             SET czy_zuzyty = 1
             WHERE id = ? AND czy_zuzyty = 0 AND expires_at >= CURRENT_TIMESTAMP'
        );
        $stmt->execute([$this->id]);

        if ($stmt->rowCount() === 0) {
            throw ApiException::badRequest(
                'Kod autoryzacyjny wygasł lub został już wykorzystany.',
                'invalid_grant'
            );
        }

        return $this->userId;
    }

    public static function posprzatajWygasle(?PDO $pdo = null): void
    {
        $db = $pdo ?? Database::connection();

        $db->exec('DELETE FROM oauth_kody WHERE expires_at < CURRENT_TIMESTAMP OR czy_zuzyty = 1');
    }

    private function polaczenie(): PDO
    {
        return $this->db ??= Database::connection();
    }
}
