<?php

declare(strict_types=1);

namespace MocnyDrop;

/**
 * backend/ILosujacy.php
 *
 * Interfejs «Losowanie» z diagramu klas (węzeł ILosujacy, kursywa = interfejs):
 *
 *     + losuj() : Przedmiot
 *
 * Na diagramie realizują go dwie klasy: Skrzynka i SkrzynkaPrzedmiot
 * (krawędzie e13 i e14 — przerywana strzałka do interfejsu). Oba tryby gry
 * (skrzynki) nie są jeszcze zaimplementowane, więc interfejs nie ma na razie
 * implementacji — jest tu, żeby struktura kodu zgadzała się z diagramem i żeby
 * tryb skrzynek miał gotowy kontrakt do podłączenia.
 *
 * W trybie łowienia ważne losowanie wykonuje WeightedRoller na puli wędki
 * (Wedka::pula()), a orkiestruje je FishingService.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
interface ILosujacy
{
    /** Losuje dokładnie jeden przedmiot z własnej puli. */
    public function losuj(): Przedmiot;
}
