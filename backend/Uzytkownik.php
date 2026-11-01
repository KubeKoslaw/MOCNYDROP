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