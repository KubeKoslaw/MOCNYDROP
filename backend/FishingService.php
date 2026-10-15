<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;
use Throwable;

/**
 * backend/FishingService.php
 *
 * Zarzut wędką — POST api/fish.php (strona 2 diagramu MOCNYDROP_PROJEKT:
 * „Rzut wędką w łowisku").
 *
 * To jest warstwa ORKIESTRACJI: diagram klas nie rysuje serwisów, tylko encje,
 * więc ten plik spina klasy z diagramu w kolejności z diagramu przepływu:
 *
 *   1. Wedka::wymagaj()          wybór wędki
 *   2. Wedka::zarzuc()           czy zarzut jest w ogóle możliwy
 *   3. Portfel::pobierzOplate()  pobranie opłaty z portfela
 *   4. WeightedRoller::roll()    ważne losowanie P(i) = w_i / suma(w)
 *   5. Ekwipunek::dodaj()        dodanie wygranej do ekwipunku
 *   6. Losowanie::zapisz()       zapis zdarzenia w historii losowań
 *   7. Reel::build()             pasek ruletki dla animacji
 *
 * Kroki 3–6 dzieją się w JEDNEJ transakcji, więc albo dzieje się wszystko,
 * albo nic — gracz nigdy nie traci monet bez wygranej.
 *
 * Wynik losowania jest znany PRZED animacją. Frontend dostaje `reel` z wygraną
 * pod `reel_indeks_wygranej` i tylko ją prezentuje — dzięki temu gracz nie może
 * wpłynąć na wynik, zatrzymując animację w innym miejscu.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class FishingService
{
    /**
     * @param int                      $userId  id gracza
     * @param int                      $wedkaId id wędki (klasa Wedka z diagramu)
     * @param (callable(): float)|null $random  wstrzykiwalny generator losowy (testy)
     * @return array<string, mixed>     pełny payload dla frontendu
     */
    public static function cast(int $userId, int $wedkaId, ?callable $random = null, ?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        // 1–2. Wędka i jej pula. Wedka::zarzuc() z diagramu jest bramką:
        //      wędka musi być aktywna i mieć przynajmniej jeden przedmiot.
        $wedka = Wedka::wymagaj($wedkaId, $db);
        $pula = $wedka->pula($db);

        if (!$wedka->zarzuc()) {
            throw ApiException::badRequest(
                $wedka->czyAktywna
                    ? 'Ta wędka nie ma jeszcze żadnych przedmiotów.'
                    : 'Ta wędka jest obecnie niedostępna.',
                $wedka->czyAktywna ? 'rod_empty' : 'rod_inactive'
            );
        }

        PosiadaneWedki::startowa($userId, $db);
        if (!PosiadaneWedki::ma($userId, $wedkaId, $db)) {
            throw ApiException::forbidden('Zdobądź tę wędkę ze skrzynki, aby nią łowić.', 'rod_not_owned');
        }
        $koszt = $wedka->kosztUzycia;

        $db->beginTransaction();

        try {
            // 3. Opłata — Portfel::pobierzOplate() (kompozycja Uzytkownik ◆— Portfel).
            $portfel = Portfel::dlaUzytkownika($userId, $db);
            $saldoPrzed = $portfel->saldo;

            // Przy darmowej wędce pomijamy UPDATE, żeby nie polegać na tym, jak
            // SQLite raportuje rowCount dla zapisu wartości identycznej.
            if ($koszt > 0.0 && !$portfel->pobierzOplate($koszt)) {
                throw ApiException::insufficientFunds($koszt, $saldoPrzed);
            }

            $saldoPoOplacie = $portfel->saldo;

            // 4. Ważone losowanie — ten sam algorytm, który obsłuży skrzynki.
            $winner = WeightedRoller::roll($pula, $random);
            $wynik = Przedmiot::zPuli($winner);

            // Wartość liczymy metodą z diagramu (Przedmiot::obliczWartosc), czyli
            // z mnożnikiem rzadkości, i zamrażamy ją w chwili nabycia.
            $wartosc = $wynik->obliczWartosc();

            // 5. Ekwipunek — Ekwipunek::dodaj(Przedmiot).
            $ekwipunek = new Ekwipunek($userId, $db);
            $ekwipunekId = $ekwipunek->dodaj($wynik, $wartosc);

            // 6. Historia — Losowanie::zapisz() (typ = wedka, wynik = Przedmiot).
            $losowanie = new Losowanie(
                $userId,
                TypLosowania::Wedka,
                $wynik,
                date('Y-m-d H:i:s'),
                $koszt,
                $wartosc,
                (float) $winner['waga'],
                (float) $winner['suma_wag'],
                $wedka->id,
                0,
                $db
            );
            $losowanie->zapisz();

            $historiaId = $losowanie->id;

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }

        // 7. Pasek ruletki — budowany PO commicie, bo to już tylko prezentacja.
        $dlugosc = Settings::getInt(Settings::KEY_REEL_LENGTH, 60, $db);
        $indeksWygranej = Settings::getInt(Settings::KEY_REEL_WINNER_INDEX, 54, $db);

        // Pilnujemy spójności, gdyby ktoś wpisał w ustawieniach indeks >= długości.
        if ($indeksWygranej >= $dlugosc) {
            $indeksWygranej = max(0, $dlugosc - 1);
        }

        $reel = Reel::build($pula, $winner, $dlugosc, $indeksWygranej, $random);

        return [
            'success' => true,
            'rzut' => [
                'id' => $historiaId,
                'typ' => TypLosowania::Wedka->value,
                'koszt' => $koszt,
            ],
            'wedka' => [
                'id' => $wedka->id,
                'nazwa' => $wedka->nazwa,
                'koszt_uzycia' => $koszt,
            ],
            'przedmiot' => [
                'ekwipunek_id' => $ekwipunekId,
                'przedmiot_id' => $wynik->id,
                'nazwa' => $wynik->nazwa,
                'rzadkosc' => $wynik->rzadkosc->nazwa,
                'rzadkosc_kod' => $wynik->rzadkosc->kod,
                'kolor' => $wynik->rzadkosc->kolor,
                'wartosc' => $wartosc,
                'ikona' => $wynik->ikonaUrl(),
                'szansa_procent' => (float) $winner['szansa_procent'],
            ],
            'saldo_przed' => round($saldoPrzed, 2),
            'saldo_po' => round($saldoPoOplacie, 2),
            'reel' => $reel,
            'reel_dlugosc' => $dlugosc,
            'reel_indeks_wygranej' => $indeksWygranej,
            'message' => sprintf('Złowiono: %s (%s)!', $wynik->nazwa, $wynik->rzadkosc->nazwa),
        ];
    }
}
