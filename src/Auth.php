<?php
/**
 * src/Auth.php
 * Prosta obsługa uwierzytelniania: rejestracja, logowanie i weryfikacja tokenów.
 */

require_once __DIR__ . '/Database.php';

class Auth {
    /**
     * Rejestruje nowego gracza w bazie danych.
     */
    public static function register(string $login, string $email, string $password): array {
        $login = trim($login);
        $email = trim($email);

        // 1. Walidacja danych wejściowych
        if (strlen($login) < 3) {
            throw new Exception("Login musi mieć co najmniej 3 znaki.");
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Niepoprawny format adresu e-mail.");
        }
        if (strlen($password) < 8) {
            throw new Exception("Hasło musi mieć co najmniej 8 znaków.");
        }

        $db = Database::getConnection();

        // 2. Sprawdzenie unikalności loginu i emaila
        $stmt = $db->prepare("SELECT id FROM uzytkownicy WHERE login = ? OR email = ?");
        $stmt->execute([$login, $email]);
        if ($stmt->fetch()) {
            throw new Exception("Podany login lub adres e-mail jest już zajęty.");
        }

        // 3. Bezpieczne haszowanie hasła algorytmem BCRYPT
        $hasloHash = password_hash($password, PASSWORD_BCRYPT);

        // 4. Zapis do bazy z darmowym saldem powitalnym 50.00 monet
        $stmt = $db->prepare("
            INSERT INTO uzytkownicy (login, email, haslo_hash, rola, saldo)
            VALUES (?, ?, ?, 'user', 50.00)
        ");
        $stmt->execute([$login, $email, $hasloHash]);

        return [
            'success' => true,
            'user_id' => $db->lastInsertId(),
            'message' => 'Rejestracja udana! Możesz się teraz zalogować.'
        ];
    }

    /**
     * Loguje gracza i generuje token dostępowy (Bearer Token).
     */
    public static function login(string $loginOrEmail, string $password): array {
        $loginOrEmail = trim($loginOrEmail);
        $db = Database::getConnection();

        // 1. Pobieramy użytkownika po loginie lub emailu
        $stmt = $db->prepare("SELECT * FROM uzytkownicy WHERE login = ? OR email = ?");
        $stmt->execute([$loginOrEmail, $loginOrEmail]);
        $user = $stmt->fetch();

        // 2. Weryfikujemy czy użytkownik istnieje i czy hasło jest poprawne
        if (!$user || !password_verify($password, $user['haslo_hash'])) {
            throw new Exception("Nieprawidłowy login lub hasło.");
        }

        // 3. Sprawdzamy czy konto nie jest zablokowane
        if ($user['czy_zablokowany'] == 1) {
            throw new Exception("To konto zostało zablokowane przez administratora.");
        }

        // 4. Generujemy bezpieczny, unikalny token (64 znaki hex)
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + (7 * 24 * 3600)); // Ważny przez 7 dni

        // 5. Zapisujemy token w bazie SQLite
        $stmt = $db->prepare("INSERT INTO tokeny (user_id, token, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$user['id'], $token, $expiresAt]);

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt,
            'user' => [
                'id' => (int)$user['id'],
                'login' => $user['login'],
                'email' => $user['email'],
                'rola' => $user['rola'],
                'saldo' => (float)$user['saldo']
            ]
        ];
    }

    /**
     * Pobiera zalogowanego użytkownika na podstawie tokena Bearer.
     */
    public static function getCurrentUser(): ?array {
        $token = self::extractToken();
        if (!$token) {
            return null;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT u.id, u.login, u.email, u.rola, u.saldo, u.czy_zablokowany
            FROM tokeny t
            JOIN uzytkownicy u ON u.id = t.user_id
            WHERE t.token = ? AND datetime(t.expires_at) > datetime('now')
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if ($user && $user['czy_zablokowany'] == 0) {
            $user['id'] = (int)$user['id'];
            $user['saldo'] = (float)$user['saldo'];
            return $user;
        }

        return null;
    }

    /**
     * Wymusza zalogowanie - jeśli brak tokena lub niepoprawny, zwraca błąd.
     */
    public static function requireAuth(): array {
        $user = self::getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Brak autoryzacji. Zaloguj się i przekaż poprawny Bearer Token.']);
            exit;
        }
        return $user;
    }

    /**
     * Wyciąga token z nagłówka HTTP 'Authorization: Bearer <token>' lub parametrów żądania.
     */
    private static function extractToken(): ?string {
        $authHeader = '';

        // 1. Sprawdzamy getallheaders() (standard dla Apache / wbudowanego serwera PHP)
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        // 2. Fallback na $_SERVER
        if (empty($authHeader)) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        }

        // 3. Dopasowanie prefiksu Bearer
        if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            return $matches[1];
        }

        // 4. Fallback dla prostych testów przez parametr GET lub POST
        return $_GET['token'] ?? $_POST['token'] ?? null;
    }
}
