<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;

final class Token
{
    public function __construct(
        public readonly string $tokenHash,
        public readonly string $refreshTokenHash,
        public readonly string $expiresAt,
        public readonly string $refreshExpiresAt,
        public readonly int $uzytkownikId = 0,
        public readonly int $id = 0,
        private ?PDO $db = null,
        public readonly ?string $token = null,
        public readonly ?string $refreshToken = null,
    ) {
    }

    public function weryfikuj(): bool
    {
        $db = $this->polaczenie();

        $stmt = $db->prepare(
            'SELECT 1
             FROM oauth_tokeny t
             JOIN uzytkownicy u ON u.id = t.user_id
             WHERE t.token_hash = ?
               AND t.expires_at >= CURRENT_TIMESTAMP
               AND u.zablokowany = 0'
        );
        $stmt->execute([$this->tokenHash]);

        return $stmt->fetchColumn() !== false;
    }

    public function uniewaznij(): void
    {
        $db = $this->polaczenie();

        $stmt = $db->prepare('DELETE FROM oauth_tokeny WHERE token_hash = ?');
        $stmt->execute([$this->tokenHash]);
    }

    public function czyWygasl(): bool
    {
        $znacznik = strtotime($this->expiresAt);

        return $znacznik !== false && $znacznik < time();
    }

    public function uzytkownik(): Uzytkownik
    {
        return Uzytkownik::znajdz($this->uzytkownikId, $this->polaczenie());
    }

    public static function wystaw(Uzytkownik $uzytkownik, ?PDO $pdo = null): self
    {
        $db = $pdo ?? Database::connection();

        $sekundy = max(1, Settings::getInt(Settings::KEY_OAUTH_ACCESS_SECONDS, 3600, $db));
        $dni = max(1, Settings::getInt(Settings::KEY_OAUTH_REFRESH_DAYS, 30, $db));

        $token = bin2hex(random_bytes(32));
        $refreshToken = bin2hex(random_bytes(32));
        $tokenHash = self::hashToken($token);
        $refreshTokenHash = self::hashToken($refreshToken);
        $teraz = time();
        $expiresAt = date('Y-m-d H:i:s', $teraz + $sekundy);
        $refreshExpiresAt = date('Y-m-d H:i:s', $teraz + ($dni * 86400));

        $stmt = $db->prepare(
            'INSERT INTO oauth_tokeny (user_id, token_hash, refresh_token_hash, expires_at, refresh_expires_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$uzytkownik->id, $tokenHash, $refreshTokenHash, $expiresAt, $refreshExpiresAt]);

        self::posprzatajWygasle($db);

        return new self(
            $tokenHash,
            $refreshTokenHash,
            $expiresAt,
            $refreshExpiresAt,
            $uzytkownik->id,
            (int) $db->lastInsertId(),
            $db,
            $token,
            $refreshToken,
        );
    }

    public static function odswiez(string $refreshToken, ?PDO $pdo = null): self
    {
        $db = $pdo ?? Database::connection();
        $refreshToken = trim($refreshToken);

        if ($refreshToken === '') {
            throw ApiException::unauthorized('Refresh token jest nieprawidłowy lub wygasł.', 'invalid_grant');
        }

        $stmt = $db->prepare(
            'SELECT id, user_id, refresh_token_hash, refresh_expires_at
             FROM oauth_tokeny
             WHERE refresh_token_hash = ? AND refresh_expires_at >= CURRENT_TIMESTAMP'
        );
        $stmt->execute([self::hashToken($refreshToken)]);

        $row = $stmt->fetch();

        if ($row === false) {
            throw ApiException::unauthorized('Refresh token jest nieprawidłowy lub wygasł.', 'invalid_grant');
        }

        $sekundy = max(1, Settings::getInt(Settings::KEY_OAUTH_ACCESS_SECONDS, 3600, $db));
        $token = bin2hex(random_bytes(32));
        $tokenHash = self::hashToken($token);
        $expiresAt = date('Y-m-d H:i:s', time() + $sekundy);

        $upd = $db->prepare('UPDATE oauth_tokeny SET token_hash = ?, expires_at = ? WHERE id = ?');
        $upd->execute([$tokenHash, $expiresAt, (int) $row['id']]);

        return new self(
            $tokenHash,
            (string) $row['refresh_token_hash'],
            $expiresAt,
            (string) $row['refresh_expires_at'],
            (int) $row['user_id'],
            (int) $row['id'],
            $db,
            $token,
            $refreshToken,
        );
    }

    public function doApi(): array
    {
        $db = $this->polaczenie();

        $sekundy = max(1, Settings::getInt(Settings::KEY_OAUTH_ACCESS_SECONDS, 3600, $db));
        $dni = max(1, Settings::getInt(Settings::KEY_OAUTH_REFRESH_DAYS, 30, $db));

        return [
            'access_token' => $this->token,
            'token_type' => 'Bearer',
            'expires_in' => $sekundy,
            'refresh_token' => $this->refreshToken,
            'refresh_expires_in' => $dni * 86400,
            'expires_at' => $this->expiresAt,
        ];
    }

    public static function autoryzuj(string $loginLubEmail, string $haslo, ?PDO $pdo = null): OauthKod
    {
        $db = $pdo ?? Database::connection();

        $uzytkownik = Uzytkownik::szukaj($loginLubEmail, $db);

        if ($uzytkownik === null || !$uzytkownik->sprawdzHaslo($haslo)) {
            throw ApiException::unauthorized('Nieprawidłowy login lub hasło.', 'invalid_credentials');
        }

        if ($uzytkownik->czyZablokowany()) {
            throw ApiException::forbidden('Konto jest zablokowane.', 'account_blocked');
        }

        return OauthKod::wystaw($uzytkownik->id, $db);
    }

    public static function uwierzytelnij(?string $token, ?PDO $pdo = null): ?Uzytkownik
    {
        if ($token === null || trim($token) === '') {
            return null;
        }

        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare(
            "SELECT u.*
             FROM oauth_tokeny t
             JOIN uzytkownicy u ON u.id = t.user_id
             WHERE t.token_hash = ?
               AND t.expires_at >= CURRENT_TIMESTAMP
               AND u.zablokowany = 0"
        );
        $stmt->execute([self::hashToken(trim($token))]);

        $row = $stmt->fetch();

        return $row === false ? null : Uzytkownik::zWiersza($row, $db);
    }

    public static function wymagajZalogowania(?PDO $pdo = null): Uzytkownik
    {
        $uzytkownik = self::uwierzytelnij(self::bearerToken(), $pdo);

        if ($uzytkownik === null) {
            throw ApiException::unauthorized();
        }

        return $uzytkownik;
    }

    public static function zBazy(string $token, ?PDO $pdo = null): ?self
    {
        $db = $pdo ?? Database::connection();

        $token = trim($token);
        $tokenHash = self::hashToken($token);

        $stmt = $db->prepare(
            'SELECT id, user_id, token_hash, refresh_token_hash, expires_at, refresh_expires_at
             FROM oauth_tokeny WHERE token_hash = ?'
        );
        $stmt->execute([$tokenHash]);

        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return new self(
            (string) $row['token_hash'],
            (string) $row['refresh_token_hash'],
            (string) $row['expires_at'],
            (string) $row['refresh_expires_at'],
            (int) $row['user_id'],
            (int) $row['id'],
            $db,
            $token,
        );
    }

    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();

            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strcasecmp((string) $name, 'Authorization') === 0) {
                        $header = (string) $value;
                        break;
                    }
                }
            }
        }

        if (!preg_match('/^\s*Bearer\s+(\S+)\s*$/i', (string) $header, $matches)) {
            return null;
        }

        return $matches[1];
    }

    public static function posprzatajWygasle(?PDO $pdo = null): void
    {
        $db = $pdo ?? Database::connection();

        $db->exec('DELETE FROM oauth_tokeny WHERE refresh_expires_at < CURRENT_TIMESTAMP');
    }

    private static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function polaczenie(): PDO
    {
        return $this->db ??= Database::connection();
    }
}
