<?php

declare(strict_types=1);

namespace MocnyDrop;

enum TypLosowania: string
{
    case Skrzynka = 'skrzynka';
    case Wedka = 'wedka';

    public function nazwa(): string
    {
        return match ($this) {
            self::Skrzynka => 'Otwarcie skrzynki',
            self::Wedka => 'Rzut wędką',
        };
    }

    public static function zBazy(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }
}
