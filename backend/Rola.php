<?php

declare(strict_types=1);

namespace MocnyDrop;

/**
 * backend/Rola.php
 *
 * Typ atrybutu `- rola : Rola` z klasy Uzytkownik na diagramie klas.
 *
 * Administrator NIE ma własnej tabeli: na diagramie jest generalizacja
 * Administrator —|> Uzytkownik, więc w bazie to ten sam wiersz `uzytkownicy`
 * z rola = 'admin'. W kodzie odpowiada temu klasa Administrator extends
 * Uzytkownik.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
enum Rola: string
{
    case User = 'user';
    case Admin = 'admin';

    public function czyAdmin(): bool
    {
        return $this === self::Admin;
    }

    /**
     * Tolerancyjny odczyt z bazy.
     *
     * Nieznana wartość zwraca User, a nie rzuca wyjątkiem: konto o dziwnej
     * roli ma działać jak zwykły gracz, a nie wywalać całą aplikację.
     * Schemat i tak ma CHECK (rola IN ('user','admin')).
     */
    public static function zBazy(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::User;
    }
}
