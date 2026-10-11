<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;

/**
 * backend/Auth.php
 *
 * Rejestracja, logowanie i autoryzacja tokenem Bearer.
 *
 * Hasła tylko przez password_hash()/password_verify() — nigdy w postaci
 * jawnej ani przez MD5. Token to 64 znaki hex z random_bytes(), trzymany
 * w tabeli `tokeny` z datą ważności, więc da się go unieważnić wylogowaniem.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class Auth
{
    /** Minimalna długość hasła. Bez typowanej stałej — `const int` wymaga PHP 8.3, a CI lintuje na 8.2. */
    public const MIN_PASSWORD_LENGTH = 8;

    /** @return array<string, mixed> utworzony użytkownik (bez hasha) */
    public static function register(string $login, string $email, string $password, ?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        $login = trim($login);
        $email = strtolower(trim($email));

        self::validateLogin($login);
        self::validateEmail($email);
        self::validatePassword($password);

        if (self::loginTaken($login, $db)) {
            throw ApiException::conflict('Ten login jest już zajęty.', 'login_taken');
        }

        if (self::emailTaken($email, $db)) {
            throw ApiException::conflict('Ten e-mail jest już zarejestrowany.', 'email_taken');
        }

        $saldoStartowe = Settings::getFloat(Settings::KEY_START_BALANCE, 50.0, $db);

        $stmt = $db->prepare(
            'INSERT INTO uzytkownicy (login, email, haslo_hash, rola, saldo)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $login,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            'user',
            $saldoStartowe,
        ]);

        return self::findUser((int) $db->lastInsertId(), $db);
    }

    /**
     * Logowanie. Zwraca token i dane gracza.
     *
     * Komunikat jest celowo taki sam dla złego loginu i złego hasła —
     * nie chcemy zdradzać, które konta istnieją.
     *
     * @return array{token: string, token_type: string, expires_at: string, user: array<string, mixed>}
     */
    public static function login(string $login, string $password, ?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare('SELECT * FROM uzytkownicy WHERE login = ? OR email = ?');
        $stmt->execute([trim($login), strtolower(trim($login))]);

        $row = $stmt->fetch();

        if ($row === false || !password_verify($password, (string) $row['haslo_hash'])) {
            throw ApiException::unauthorized('Nieprawidłowy login lub hasło.', 'invalid_credentials');
        }

        if ((int) $row['czy_zablokowany'] === 1) {
            throw ApiException::forbidden('Konto jest zablokowane.', 'account_blocked');
        }

        $userId = (int) $row['id'];
        $hours = Settings::getInt(Settings::KEY_TOKEN_HOURS, 72, $db);

        if ($hours < 1) {
            $hours = 72;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + ($hours * 3600));

        $stmt = $db->prepare('INSERT INTO tokeny (user_id, token, expires_at) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $token, $expiresAt]);

        // Sprzątanie przy okazji logowania — inaczej tabela tokenów rośnie bez końca.
        $db->exec("DELETE FROM tokeny WHERE expires_at < datetime('now')");

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt,
            'user' => self::toPublic(self::findUser($userId, $db)),
        ];
    }

    /** Unieważnia token (wylogowanie). */
    public static function logout(string $token, ?PDO $pdo = null): void
    {
        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare('DELETE FROM tokeny WHERE token = ?');
        $stmt->execute([$token]);
    }

    /**
     * Rozpoznaje gracza po tokenie. Zwraca null, gdy token nie istnieje,
     * wygasł albo konto jest zablokowane.
     *
     * @return array<string, mixed>|null
     */
    public static function authenticate(?string $token, ?PDO $pdo = null): ?array
    {
        if ($token === null || trim($token) === '') {
            return null;
        }

        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare(
            "SELECT u.*
             FROM tokeny t
             JOIN uzytkownicy u ON u.id = t.user_id
             WHERE t.token = ?
               AND t.expires_at >= datetime('now')
               AND u.czy_zablokowany = 0"
        );
        $stmt->execute([trim($token)]);

        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    /** Jak authenticate(), ale bierze token z nagłówka i rzuca 401 zamiast zwracać null. */
    public static function requireAuth(?PDO $pdo = null): array
    {
        $user = self::authenticate(self::bearerToken(), $pdo);

        if ($user === null) {
            throw ApiException::unauthorized();
        }

        return $user;
    }

    /**
     * Wyciąga token z nagłówka Authorization.
     *
     * Apache w konfiguracji CGI/FastCGI potrafi wyciąć ten nagłówek, dlatego
     * sprawdzamy też REDIRECT_HTTP_AUTHORIZATION i apache_request_headers().
     * Bez tego logowanie "działa w XAMPP, nie działa na hostingu".
     */
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

    /** @return array<string, mixed> */
    public static function findUser(int $userId, ?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare('SELECT * FROM uzytkownicy WHERE id = ?');
        $stmt->execute([$userId]);

        $row = $stmt->fetch();

        if ($row === false) {
            throw ApiException::notFound('Gracz nie istnieje.', 'user_not_found');
        }

        return self::hydrate($row);
    }

    /** Wersja publiczna — bez hasha i bez flagi blokady. */
    public static function toPublic(array $user): array
    {
        return [
            'id' => $user['id'],
            'login' => $user['login'],
            'email' => $user['email'],
            'rola' => $user['rola'],
            'saldo' => $user['saldo'],
        ];
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'login' => (string) $row['login'],
            'email' => (string) $row['email'],
            'rola' => (string) $row['rola'],
            'saldo' => (float) $row['saldo'],
            'czy_zablokowany' => (int) $row['czy_zablokowany'] === 1,
            'created_at' => (string) $row['created_at'],
        ];
    }

    private static function loginTaken(string $login, PDO $db): bool
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM uzytkownicy WHERE login = ?');
        $stmt->execute([$login]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private static function emailTaken(string $email, PDO $db): bool
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM uzytkownicy WHERE email = ?');
        $stmt->execute([$email]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private static function validateLogin(string $login): void
    {
        $length = mb_strlen($login);

        if ($length < 3 || $length > 32) {
            throw ApiException::badRequest('Login musi mieć od 3 do 32 znaków.', 'login_length');
        }

        if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $login)) {
            throw ApiException::badRequest(
                'Login może zawierać tylko litery, cyfry oraz znaki . _ -',
                'login_charset'
            );
        }
    }

    private static function validateEmail(string $email): void
    {
        if ($email === '' || mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ApiException::badRequest('Nieprawidłowy adres e-mail.', 'email_invalid');
        }
    }

    private static function validatePassword(string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw ApiException::badRequest(
                'Hasło musi mieć co najmniej ' . self::MIN_PASSWORD_LENGTH . ' znaków.',
                'password_too_short'
            );
        }

        if (mb_strlen($password) > 255) {
            throw ApiException::badRequest('Hasło jest za długie (maks. 255 znaków).', 'password_too_long');
        }
    }
}
