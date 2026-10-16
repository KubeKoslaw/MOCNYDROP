<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;
use Throwable;

/**
 * backend/Uzytkownik.php
 *
 * Klasa Uzytkownik z diagramu klas:
 *
 *     - id : int
 *     - login : string
 *     - email : string
 *     - haslo_hash : string
 *     - zablokowany : bool
 *     - rola : Rola
 *     + zaloguj(haslo : string) : bool
 *     + zarejestruj() : bool
 *     + czyZablokowany() : bool
 *
 * Asocjacje z diagramu obsłużone w tej klasie:
 *   Uzytkownik ◆— Portfel (1:1, «posiada»)  -> portfel()
 *   Uzytkownik ◆— Token (1:0..*)            -> backend/Token.php
 *   Uzytkownik ◆— Ekwipunek (1:1..*)        -> backend/Ekwipunek.php
 *   Uzytkownik — Losowanie («historia»)     -> backend/Losowanie.php
 *
 * Generalizacja Administrator —|> Uzytkownik: klasa NIE jest final, a
 * `rola : Rola` (enum Rola) rozróżnia zwykłego gracza od administratora —
 * w bazie to ten sam wiersz tabeli `uzytkownicy`.
 *
 * Hasła tylko przez password_hash()/password_verify() — nigdy w postaci
 * jawnej ani przez MD5.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
class Uzytkownik
{
    /** Minimalna długość hasła. Bez typowanej stałej — `const int` wymaga PHP 8.3, a CI lintuje na 8.2. */
    public const MIN_PASSWORD_LENGTH = 8;

    /** @var int 0 = encja jeszcze niezapisana w bazie */
    public int $id;

    public function __construct(
        public readonly string $login,
        public readonly string $email,
        protected string $hasloHash,
        public bool $zablokowany = false,
        public Rola $rola = Rola::User,
        public readonly string $createdAt = '',
        int $id = 0,
        private ?PDO $db = null,
    ) {
        $this->id = $id;
    }

    /**
     * Metoda z diagramu: `+ zarejestruj() : bool`.
     *
     * Zakłada konto i od razu tworzy Portfel z saldem startowym — kompozycja
     * 1:1 z diagramu oznacza, że gracz bez portfela nie ma prawa istnieć.
     * Wszystko w jednej transakcji: albo jest konto i portfel, albo nic.
     */
    public function zarejestruj(): bool
    {
        $db = $this->polaczenie();

        self::walidujLogin($this->login);
        self::walidujEmail($this->email);

        if (self::loginZajety($this->login, $db)) {
            throw ApiException::conflict('Ten login jest już zajęty.', 'login_taken');
        }

        if (self::emailZajety($this->email, $db)) {
            throw ApiException::conflict('Ten e-mail jest już zarejestrowany.', 'email_taken');
        }

        $db->beginTransaction();

        try {
            $stmt = $db->prepare(
                'INSERT INTO uzytkownicy (login, email, haslo_hash, zablokowany, rola)
                 VALUES (?, ?, ?, 0, ?)'
            );
            $stmt->execute([$this->login, $this->email, $this->hasloHash, $this->rola->value]);

            $this->id = (int) $db->lastInsertId();

            Portfel::utworz($this->id, null, $db);
            PosiadaneWedki::startowa($this->id, $db);

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }

        return true;
    }

    /**
     * Metoda z diagramu: `+ zaloguj(haslo : string) : bool`.
     *
     * Sprawdza hasło ORAZ blokadę konta — zwraca true tylko dla gracza, który
     * może wejść do gry. Kody błędu dla API (invalid_credentials vs
     * account_blocked) rozróżnia wywołujący: patrz Token::zaloguj().
     */
    public function zaloguj(string $haslo): bool
    {
        return $this->sprawdzHaslo($haslo) && !$this->czyZablokowany();
    }

    /**
     * Sama weryfikacja hasła, bez sprawdzania blokady.
     *
     * Potrzebna, bo `haslo_hash` jest prywatny (atrybut z diagramu), a API musi
     * odróżnić „złe hasło" od „konto zablokowane" — patrz Token::zaloguj().
     */
    public function sprawdzHaslo(string $haslo): bool
    {
        return password_verify($haslo, $this->hasloHash);
    }

    /** Metoda z diagramu: `+ czyZablokowany() : bool`. */
    public function czyZablokowany(): bool
    {
        return $this->zablokowany;
    }

    /** Kompozycja Uzytkownik ◆— Portfel (1:1) z diagramu. */
    public function portfel(?PDO $pdo = null): Portfel
    {
        return Portfel::dlaUzytkownika($this->id, $pdo ?? $this->polaczenie());
    }

    /** Wersja publiczna — bez hasha i bez flagi blokady. @return array<string, mixed> */
    public function doPublicznej(?PDO $pdo = null): array
    {
        $portfel = $this->portfel($pdo);

        return [
            'id' => $this->id,
            'login' => $this->login,
            'email' => $this->email,
            'rola' => $this->rola->value,
            'saldo' => round($portfel->saldo, 2),
            'waluta' => $portfel->waluta,
        ];
    }

    /**
     * Fabryka nowego gracza (jeszcze niezapisanego — id = 0).
     * Hasło jest hashowane od razu, więc w obiekcie nigdy nie ma jawnego hasła.
     */
    public static function utworz(string $login, string $email, string $haslo, ?PDO $pdo = null): static
    {
        self::walidujHaslo($haslo);

        return new static(
            trim($login),
            strtolower(trim($email)),
            password_hash($haslo, PASSWORD_DEFAULT),
            false,
            Rola::User,
            '',
            0,
            $pdo,
        );
    }

    /** Szuka po id; rzuca 404, gdy gracza nie ma. */
    public static function znajdz(int $userId, ?PDO $pdo = null): static
    {
        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare('SELECT * FROM uzytkownicy WHERE id = ?');
        $stmt->execute([$userId]);

        $row = $stmt->fetch();

        if ($row === false) {
            throw ApiException::notFound('Gracz nie istnieje.', 'user_not_found');
        }

        return static::zWiersza($row, $db);
    }

    /**
     * Szuka po loginie ALBO e-mailu; zwraca null, gdy nie ma takiego konta.
     * Null (a nie wyjątek) jest celowy: api/login.php musi odpowiedzieć tak
     * samo na „nie ma konta" i „złe hasło", żeby nie zdradzać, które loginy
     * istnieją.
     */
    public static function szukaj(string $loginLubEmail, ?PDO $pdo = null): ?static
    {
        $db = $pdo ?? Database::connection();

        $login = trim($loginLubEmail);

        $stmt = $db->prepare('SELECT * FROM uzytkownicy WHERE login = ? OR email = ?');
        $stmt->execute([$login, strtolower($login)]);

        $row = $stmt->fetch();

        return $row === false ? null : static::zWiersza($row, $db);
    }

    /**
     * Wyszukiwarka kont dla panelu administratora (UC_ADM2: „Wyszukiwanie konta
     * po ID, loginie lub emailu").
     *
     * Zwraca od razu saldo, rolę, blokadę i aktywność gracza, żeby panel nie
     * musiał robić N+1 zapytań na liście.
     *
     * @return list<array<string, mixed>>
     */
    public static function szukajDlaAdmina(string $fraza = '', int $limit = 50, ?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        $limit = max(1, min(200, $limit));

        $sql = 'SELECT u.id, u.login, u.email, u.rola, u.zablokowany, u.created_at,
                       COALESCE(p.saldo, 0) AS saldo,
                       COALESCE(p.waluta, \'\') AS waluta,
                       (SELECT COUNT(*) FROM historia_losowan h WHERE h.user_id = u.id) AS liczba_losowan,
                       (SELECT MAX(czas) FROM historia_losowan h WHERE h.user_id = u.id) AS ostatnia_aktywnosc,
                       (SELECT COUNT(*) FROM ekwipunek e WHERE e.user_id = u.id AND e.sprzedany = 0) AS przedmioty
                FROM uzytkownicy u
                LEFT JOIN portfele p ON p.user_id = u.id';

        $parametry = [];
        $fraza = trim($fraza);

        if ($fraza !== '') {
            // Czyste liczbowe id traktujemy też jako trafienie w id — panel
            // pozwala szukać po ID, loginie albo e-mailu.
            //
            // ESCAPE '\' jest konieczne: bez niego SQLite nie zna backslasha
            // jako znaku ucieczki i traktowałby \_ dosłownie, więc login
            // „anna_nowak" nigdy by się nie znalazł.
            $sql .= " WHERE u.login LIKE ? ESCAPE '\\' OR u.email LIKE ? ESCAPE '\\' OR CAST(u.id AS TEXT) = ?";

            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $fraza) . '%';
            $parametry = [$like, $like, $fraza];
        }

        $sql .= ' ORDER BY u.id ASC LIMIT ' . $limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($parametry);

        $out = [];

        foreach ($stmt->fetchAll() as $row) {
            $userId = (int) $row['id'];

            $out[] = [
                'id' => $userId,
                'login' => (string) $row['login'],
                'email' => (string) $row['email'],
                'rola' => (string) $row['rola'],
                'zablokowany' => (int) $row['zablokowany'] === 1,
                'created_at' => (string) $row['created_at'],
                'saldo' => round((float) $row['saldo'], 2),
                'waluta' => (string) $row['waluta'],
                'liczba_losowan' => (int) $row['liczba_losowan'],
                'przedmioty' => (int) $row['przedmioty'],
                'ostatnia_aktywnosc' => $row['ostatnia_aktywnosc'] === null ? null : (string) $row['ostatnia_aktywnosc'],
                'rtp' => Losowanie::rtpGracza($userId, $db),
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $row wiersz z tabeli `uzytkownicy` */
    public static function zWiersza(array $row, ?PDO $pdo = null): static
    {
        return new static(
            (string) $row['login'],
            (string) $row['email'],
            (string) $row['haslo_hash'],
            (int) $row['zablokowany'] === 1,
            Rola::zBazy((string) $row['rola']),
            (string) ($row['created_at'] ?? ''),
            (int) $row['id'],
            $pdo,
        );
    }

    protected static function loginZajety(string $login, PDO $db): bool
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM uzytkownicy WHERE login = ?');
        $stmt->execute([$login]);

        return (int) $stmt->fetchColumn() > 0;
    }

    protected static function emailZajety(string $email, PDO $db): bool
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM uzytkownicy WHERE email = ?');
        $stmt->execute([$email]);

        return (int) $stmt->fetchColumn() > 0;
    }

    protected static function walidujLogin(string $login): void
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

    protected static function walidujEmail(string $email): void
    {
        if ($email === '' || mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ApiException::badRequest('Nieprawidłowy adres e-mail.', 'email_invalid');
        }
    }

    protected static function walidujHaslo(string $haslo): void
    {
        if (mb_strlen($haslo) < self::MIN_PASSWORD_LENGTH) {
            throw ApiException::badRequest(
                'Hasło musi mieć co najmniej ' . self::MIN_PASSWORD_LENGTH . ' znaków.',
                'password_too_short'
            );
        }

        if (mb_strlen($haslo) > 255) {
            throw ApiException::badRequest('Hasło jest za długie (maks. 255 znaków).', 'password_too_long');
        }
    }

    protected function polaczenie(): PDO
    {
        return $this->db ??= Database::connection();
    }
}
