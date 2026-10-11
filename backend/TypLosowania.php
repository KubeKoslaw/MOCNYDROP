<?php

declare(strict_types=1);

namespace MocnyDrop;

/**
 * backend/TypLosowania.php
 *
 * Typ atrybutu `- typ : TypLosowania` z klasy Losowanie na diagramie klas.
 *
 * Cztery przypadki to cztery tryby gry hazardowej ze strony 2 diagramu
 * MOCNYDROP_PROJEKT.drawio. Zaimplementowany jest na razie tylko `Wedka`
 * (POST api/fish.php); reszta czeka na swoje tryby, ale słownik musi być
 * wspólny, bo wszystkie losowania wpadają do jednej tabeli historia_losowan.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
enum TypLosowania: string
{
    case Skrzynka = 'skrzynka';
    case Wedka = 'wedka';
    case Upgrader = 'upgrader';
    case Trader = 'trader';

    /** Nazwa czytelna dla gracza (historia połowów, eksport raportu). */
    public function nazwa(): string
    {
        return match ($this) {
            self::Skrzynka => 'Otwarcie skrzynki',
            self::Wedka => 'Rzut wędką',
            self::Upgrader => 'Upgrader',
            self::Trader => 'Trader',
        };
    }

    /** Tolerancyjny odczyt z bazy — null dla wartości spoza słownika. */
    public static function zBazy(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }
}
