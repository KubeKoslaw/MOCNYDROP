<?php

declare(strict_types=1);

namespace MocnyDrop;

enum Rola: string
{
    case User = 'user';
    case Admin = 'admin';

    public function czyAdmin(): bool
    {
        return $this === self::Admin;
    }

    public static function zBazy(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::User;
    }
}
