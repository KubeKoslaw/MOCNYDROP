<?php

declare(strict_types=1);

namespace MocnyDrop;

use RuntimeException;
use Throwable;

final class ApiException extends RuntimeException
{
    public function __construct(string $message, private readonly string $kod, private readonly int $status, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

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

    public static function notImplemented(string $message, string $kod = 'not_implemented'): self
    {
        return new self($message, $kod, 501);
    }

    public static function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ');
    }
}
