<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;

/**
 * backend/Token.php
 *
 * Klasa Token z diagramu klas:
 *
 *     - token : string
 *     - expires_at : datetime
 *     + weryfikuj() : bool
 *     + uniewaznij()
 *
 * Asocjacja z diagramu: Uzytkownik ◆— Token (1 : 0..*), w bazie tabela
 * `tokeny` z user_id i kluczem obcym ON DELETE CASCADE.
 *
 * Token to 64 znaki hex z random_bytes() (CSPRNG), trzymany w bazie z datą
 * ważności, więc da się go unieważnić wylogowaniem.
 *
 * Metody statyczne poniżej (wystaw/zaloguj/uwierzytelnij/wymagajZalogowania/
 * bearerToken) to warstwa HTTP, której diagram klas nie rysuje — przejęły
 * obowiązki dawnego backend/Auth.php, żeby w kodzie nie było klasy spoza
 * diagramu zajmującej się domeną.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class Token
{
    public function __construct(
        public readonly string $token,
        public readonly string $expiresAt,
        public readonly int $uzytkownikId = 0,
        public readonly int $id = 0,
        private ?PDO $db = null,
    ) {
    }

    /**
     * Metoda z diagramu: `+ weryfikuj() : bool`.
     *
     * Token jest ważny, gdy istnieje w bazie, nie wygasł i jego właściciel nie
     * jest zablokowany. Pytamy bazę (a nie pól obiektu), bo token mógł zostać
     * unieważniony w innym żądaniu, odkąd ten obiekt powstał.
     */
    public function weryfikuj(): bool
    {
        return self::uwierzytelnij($this->token, $this->polaczenie()) !== null;
    }

    /** Metoda z diagramu: `+ uniewaznij()` — usuwa token z bazy (wylogowanie). */
    public function uniewaznij(): void
    {
        $db = $this->polaczenie();

        $stmt = $db->prepare('DELETE FROM tokeny WHERE token = ?');
        $stmt->execute([$this->token]);
    }

    public function czyWygasl(): bool
    {
        return strtotime($this->expiresAt) !== false && strtotime($this->expiresAt) < time();
    }

    /** Właściciel tokena. Rzuca 401, gdy token już nie jest ważny. */
    public function uzytkownik(): Uzytkownik
    {
        return Uzytkownik::znajdz($this->uzytkownikId, $this->polaczenie());
    }

    /**
     * Wystawia nowy token Bearer dla gracza.
     * Ważność bierze się z ustawienia `token_waznosc_godz`.
     */
    public static function wystaw(Uzytkownik $uzytkownik, ?PDO $pdo = null): self
    {
        $db = $pdo ?? Database::connection();

        $hours = Settings::getInt(Settings::KEY_TOKEN_HOURS, 72, $db);

        if ($hours < 1) {
            $hours = 72;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + ($hours * 3600));

        $stmt = $db->prepare('INSERT INTO tokeny (user_id, token, expires_at) VALUES (?, ?, ?)');
        $stmt->execute([$uzytkownik->id, $token, $expiresAt]);

        // Sprzątanie przy okazji wystawiania — inaczej tabela tokenów rośnie bez końca.
        self::posprzatajWygasle($db);

        return new self($token, $expiresAt, $uzytkownik->id, (int) $db->lastInsertId(), $db);
    }

    /**
     * Logowanie: sprawdza hasło i blokadę konta, potem wystawia token.
     * Zastępuje dawne Auth::login() — kształt odpowiedzi zostaje ten sam,
     * żeby frontend nie musiał się zmieniać.
     *
     * Komunikat jest celowo taki sam dla złego loginu i złego hasła — nie
     * chcemy zdradzać, które konta istnieją. Stan „zablokowane" ujawniamy
     * dopiero po podaniu POPRAWNEGO hasła, żeby ktoś bez hasła nie mógł
     * enumerować zbanowanych kont.
     *
     * @return array{token: string, token_type: string, expires_at: string, user: array<string, mixed>}
     */
    public static function zaloguj(string $loginLubEmail, string $haslo, ?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        $uzytkownik = Uzytkownik::szukaj($loginLubEmail, $db);

        if ($uzytkownik === null || !$uzytkownik->sprawdzHaslo($haslo)) {
            throw ApiException::unauthorized('Nieprawidłowy login lub hasło.', 'invalid_credentials');
        }

        if ($uzytkownik->czyZablokowany()) {
            throw ApiException::forbidden('Konto jest zablokowane.', 'account_blocked');
        }

        $token = self::wystaw($uzytkownik, $db);

        return [
            'token' => $token->token,
            'token_type' => 'Bearer',
            'expires_at' => $token->expiresAt,
            'user' => $uzytkownik->doPublicznej($db),
        ];
    }

    /**
     * Rozpoznaje gracza po tokenie. Zwraca null, gdy token nie istnieje,
     * wygasł albo konto jest zablokowane.
     */
    public static function uwierzytelnij(?string $token, ?PDO $pdo = null): ?Uzytkownik
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
               AND u.zablokowany = 0"
        );
        $stmt->execute([trim($token)]);

        $row = $stmt->fetch();

        return $row === false ? null : Uzytkownik::zWiersza($row, $db);
    }

    /** Jak uwierzytelnij(), ale bierze token z nagłówka i rzuca 401 zamiast zwracać null. */
    public static function wymagajZalogowania(?PDO $pdo = null): Uzytkownik
    {
        $uzytkownik = self::uwierzytelnij(self::bearerToken(), $pdo);

        if ($uzytkownik === null) {
            throw ApiException::unauthorized();
        }

        return $uzytkownik;
    }

    /** Wczytuje token z bazy po jego wartości. */
    public static function zBazy(string $token, ?PDO $pdo = null): ?self
    {
        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare('SELECT id, user_id, token, expires_at FROM tokeny WHERE token = ?');
        $stmt->execute([trim($token)]);

        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return new self(
            (string) $row['token'],
            (string) $row['expires_at'],
            (int) $row['user_id'],
            (int) $row['id'],
            $db,
        );
    }

    /**
     * Wyciąga token z nagłówka Authorization.
     *
     * Apache w konfiguracji CGI/FastCGI potrafi wyciąć ten nagłówek, dlatego
     * sprawdzamy też REDIRECT_HTTP_AUTHORIZATION i apache_request_headers().
     * Bez tego logowanie „działa w XAMPP, nie działa na hostingu".
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

    /** Usuwa wygasłe tokeny — porządek w tabeli bez crona. */
    public static function posprzatajWygasle(?PDO $pdo = null): void
    {
        $db = $pdo ?? Database::connection();

        $db->exec("DELETE FROM tokeny WHERE expires_at < datetime('now')");
    }

    private function polaczenie(): PDO
    {
        return $this->db ??= Database::connection();
    }
}
