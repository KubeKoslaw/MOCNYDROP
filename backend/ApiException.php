<?php

declare(strict_types=1);

namespace MocnyDrop;

use RuntimeException;
use Throwable;

/**
 * backend/ApiException.php
 *
 * Wyjątek domenowy z gotowym statusem HTTP i kodem błędu dla frontendu.
 *
 * Zamiast łapać ogólne Exception i zgadywać, co poszło nie tak, każda
 * przewidywalna sytuacja biznesowa (brak środków, złe hasło, nieistniejące
 * łowisko) rzuca ten wyjątek. api/kernel.php mapuje go potem 1:1 na
 * odpowiedź JSON, więc @jgpostrach może rozróżniać przypadki po `kod`,
 * a nie po komunikacie tekstowym.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class ApiException extends RuntimeException
{
    public function __construct(string $message, private readonly string $kod, private readonly int $status, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** Kod błędu zwracany w JSON — stabilny, nie podlega tłumaczeniu. */
    public function errorCode(): string
    {
        return $this->kod;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    public static function badRequest(string $message, string $kod = 'bad_request'): self
    {
        return new self($message, $kod, 400);
    }

    public static function unauthorized(string $message = 'Wymagane zalogowanie.', string $kod = 'unauthorized'): self
    {
        return new self($message, $kod, 401);
    }

    public static function forbidden(string $message = 'Brak uprawnień.', string $kod = 'forbidden'): self
    {
        return new self($message, $kod, 403);
    }

    public static function notFound(string $message, string $kod = 'not_found'): self
    {
        return new self($message, $kod, 404);
    }

    /**
     * Osobny kod, bo frontend musi na to zareagować inaczej niż na zwykły błąd:
     * zablokować przycisk "Zarzuć wędkę" i pokazać brak środków, a nie alert.
     */
    public static function insufficientFunds(float $koszt, float $saldo): self
    {
        return new self(
            sprintf('Za mało monet na zarzut. Koszt: %s, Twoje saldo: %s.', self::money($koszt), self::money($saldo)),
            'insufficient_funds',
            400
        );
    }

    public static function conflict(string $message, string $kod = 'conflict'): self
    {
        return new self($message, $kod, 409);
    }

    /**
     * 501 — element opisany w diagramach, ale jeszcze niezaimplementowany
     * (np. Administrator::edytujSkrzynke przed zbudowaniem trybu skrzynek).
     * Osobny kod, żeby frontend odróżnił „zepsute" od „jeszcze nie ma".
     */
    public static function notImplemented(string $message, string $kod = 'not_implemented'): self
    {
        return new self($message, $kod, 501);
    }

    /** Spójny format kwot w komunikatach — 2 miejsca, bez ogonka ".0". */
    public static function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ');
    }
}
