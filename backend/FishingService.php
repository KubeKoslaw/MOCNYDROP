<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;
use Throwable;

/**
 * backend/FishingService.php
 *
 * Zarzut wędką — odpowiednik otwarcia skrzynki.
 *
 * Przepływ (wszystko w JEDNEJ transakcji, więc albo dzieje się wszystko,
 * albo nic — gracz nigdy nie traci monet bez ryby):
 *
 *   1. sprawdź, czy łowisko istnieje i jest aktywne
 *   2. pobierz pulę ryb z wagami
 *   3. pobierz opłatę za zarzut              S_po = S_przed - c
 *   4. ważne losowanie P(i) = w_i / suma(w)
 *   5. włóż rybę do ekwipunku
 *   6. zapisz zdarzenie w historii połowów
 *   7. zbuduj pasek ruletki dla animacji
 *
 * Wynik losowania jest znany PRZED animacją. Frontend dostaje `reel` z
 * wygraną pod `reel_indeks_wygranej` i tylko ją prezentuje — dzięki temu
 * gracz nie może wpłynąć na wynik, zatrzymując animację w innym miejscu.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class FishingService
{
    /**
     * @param int                    $userId  id gracza
     * @param int                    $spotId  id łowiska
     * @param (callable(): float)|null $random wstrzykiwalny generator losowy (testy)
     * @return array<string, mixed>  pełny payload dla frontendu
     */
    public static function cast(int $userId, int $spotId, ?callable $random = null, ?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        // 1. Łowisko
        $spot = FishingSpotRepository::require($spotId, $db);

        if (!$spot['czy_aktywne']) {
            throw ApiException::badRequest('To łowisko jest obecnie zamknięte.', 'spot_inactive');
        }

        // 2. Pula ryb
        $pool = $spot['ryby'];

        if ($pool === []) {
            throw ApiException::badRequest('To łowisko nie ma jeszcze żadnych ryb.', 'spot_empty');
        }

        $koszt = $spot['koszt_zarzutu'];

        $db->beginTransaction();

        try {
            $saldoPrzed = Wallet::balance($db, $userId);

            // 3. Opłata. Pomijamy UPDATE przy darmowym łowisku, żeby nie
            //    polegać na tym, jak SQLite raportuje rowCount dla zapisu
            //    wartości identycznej z istniejącą.
            if ($koszt > 0.0) {
                $saldoPoOplacie = Wallet::charge($db, $userId, $koszt);
            } else {
                $saldoPoOplacie = $saldoPrzed;
            }

            // 4. Ważone losowanie — ten sam algorytm co dla skrzynek.
            $winner = WeightedRoller::roll($pool, $random);

            $rybaId = (int) $winner['ryba_id'];
            $wartosc = (float) $winner['wartosc'];

            // 5. Ekwipunek. Cena sprzedaży jest zamrażana w chwili połowu,
            //    żeby zmiana balansu nie przeceniła tego, co gracz już ma.
            $stmt = $db->prepare('INSERT INTO ekwipunek (user_id, ryba_id, cena_sprzedazy) VALUES (?, ?, ?)');
            $stmt->execute([$userId, $rybaId, $wartosc]);
            $ekwipunekId = (int) $db->lastInsertId();

            // 6. Historia — audyt i materiał do testu rozkładu losowań.
            $stmt = $db->prepare(
                'INSERT INTO historia_polowow
                    (user_id, lowisko_id, ryba_id, koszt, wygrana_wartosc, waga_wylosowana, suma_wag)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $spotId,
                $rybaId,
                $koszt,
                $wartosc,
                (float) $winner['waga'],
                (float) $winner['suma_wag'],
            ]);
            $historiaId = (int) $db->lastInsertId();

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

        $reel = Reel::build($pool, $winner, $dlugosc, $indeksWygranej, $random);

        return [
            'success' => true,
            'rzut' => [
                'id' => $historiaId,
                'koszt' => $koszt,
            ],
            'lowisko' => [
                'id' => $spot['id'],
                'nazwa' => $spot['nazwa'],
                'koszt_zarzutu' => $koszt,
            ],
            'zlowiona_ryba' => [
                'ekwipunek_id' => $ekwipunekId,
                'ryba_id' => $rybaId,
                'nazwa' => (string) $winner['nazwa'],
                'rzadkosc' => (string) $winner['rzadkosc_nazwa'],
                'rzadkosc_kod' => (string) $winner['rzadkosc_kod'],
                'kolor' => (string) $winner['kolor'],
                'wartosc' => $wartosc,
                'ikona' => (string) $winner['ikona_url'],
                'szansa_procent' => (float) $winner['szansa_procent'],
            ],
            'saldo_przed' => round($saldoPrzed, 2),
            'saldo_po' => round($saldoPoOplacie, 2),
            'reel' => $reel,
            'reel_dlugosc' => $dlugosc,
            'reel_indeks_wygranej' => $indeksWygranej,
            'message' => sprintf('Złowiono: %s (%s)!', $winner['nazwa'], $winner['rzadkosc_nazwa']),
        ];
    }
}
