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
 * (krawędzie e13 i e14 — przerywana strzałka do interfejsu). Obie są
 * zaimplementowane w backend/ i obsługują tryb «Otwarcie skrzynki»
 * (POST /api/open_case.php).
 *
 * Podział jest dokładnie taki jak na diagramie: Skrzynka::losuj() wybiera
 * ważonym losowaniem, KTÓRY wpis puli wygrywa, a SkrzynkaPrzedmiot::losuj()
 * zwraca Przedmiot tego wpisu.
 *
 * W trybie łowienia ważne losowanie wykonuje WeightedRoller na puli wędki
 * (Wedka::pula()), a orkiestruje je FishingService — Wedka nie realizuje
 * ILosujacy, bo na diagramie ta strzałka idzie tylko od klas skrzynek.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
interface ILosujacy
{
    /** Losuje dokładnie jeden przedmiot z własnej puli. */
    public function losuj(): Przedmiot;
}
