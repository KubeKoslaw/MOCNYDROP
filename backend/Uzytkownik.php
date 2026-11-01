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
