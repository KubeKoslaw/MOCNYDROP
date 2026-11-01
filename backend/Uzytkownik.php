<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;
use Throwable;

class Uzytkownik
{

    public const MIN_PASSWORD_LENGTH = 8;

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

            $startowa = Wedka::startowa($db);

            if ($startowa !== null && $startowa->przedmiotId !== null) {
                (new Ekwipunek($this->id, $db))->dodaj(Przedmiot::wymagaj($startowa->przedmiotId, $db));
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }

        return true;
    }

    public function zaloguj(string $haslo): bool
    {
        return $this->sprawdzHaslo($haslo) && !$this->czyZablokowany();
    }

    public function sprawdzHaslo(string $haslo): bool
    {
        return password_verify($haslo, $this->hasloHash);
    }

    public function czyZablokowany(): bool
    {
        return $this->zablokowany;
    }

    public function portfel(?PDO $pdo = null): Portfel
    {
        return Portfel::dlaUzytkownika($this->id, $pdo ?? $this->polaczenie());
    }

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

    public static function szukaj(string $loginLubEmail, ?PDO $pdo = null): ?static
    {
        $db = $pdo ?? Database::connection();

        $login = trim($loginLubEmail);

        $stmt = $db->prepare('SELECT * FROM uzytkownicy WHERE login = ? OR email = ?');
        $stmt->execute([$login, strtolower($login)]);

        $row = $stmt->fetch();

        return $row === false ? null : static::zWiersza($row, $db);
    }

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

            $sql .= " WHERE LOWER(u.login) LIKE LOWER(?) ESCAPE '!' OR LOWER(u.email) LIKE LOWER(?) ESCAPE '!' OR CAST(u.id AS CHAR) = ?";

            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $fraza) . '%';
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
