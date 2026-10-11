<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;
use Throwable;

/**
 * backend/UpgraderService.php
 *
 * «Ulepszenie przedmiotu (Upgrader)» — przypadek użycia UC_UPGRADE z diagramu
 * use case i „Ulepszanie w Upgraderze (POST /api/upgrade.php)" ze strony 2
 * diagramu MOCNYDROP_PROJEKT.
 *
 * Warstwa ORKIESTRACJI, tak samo jak FishingService: diagram klas nie rysuje
 * serwisów, tylko encje, więc ten plik spina klasy z diagramu w kolejności
 * z diagramu przepływu:
 *
 *   1. Ekwipunek::wpis()          wybór przedmiotu wkładu z ekwipunku
 *   2. Przedmiot::wymagaj()       wybór celu z katalogu
 *   3. szansa()                   kalkulacja % = (W_wkład / W_cel) * (1 - marża)
 *   4. Ekwipunek::spalWpis()      zablokowanie i trwałe usunięcie wkładu
 *   5. koło 0.00%–100.00%         losowanie liczby wirtualnego koła
 *   6a. wygrana  -> Ekwipunek::dodaj(cel)      (przedmiot ulepszony)
 *   6b. porażka  -> wkład przepada bezpowrotnie
 *   7. Losowanie::zapisz()        historia (typ = upgrader, udane = 0/1)
 *
 * Kroki 4–7 są w JEDNEJ transakcji: wkład nie może zniknąć bez zapisania
 * wyniku próby.
 *
 * Ekonomika: marża gwarantuje, że wartość oczekiwana próby jest niższa od
 * wartości wkładu — EV = szansa * W_cel = W_wkład * (1 - marża). Dlatego cel
 * MUSI być droższy od wkładu (inaczej gracz miałby darmową maszynkę do monet).
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class UpgraderService
{
    /**
     * Wzór z diagramu PROJEKT: `szansa % = (W_wkład / W_cel) * (1 - marża)`.
     *
     * Zwraca ułamek 0..1 (nie procenty). Marża jest przycinana do [0, 0.95],
     * żeby błąd w tabeli `ustawienia` nie dał szansy ujemnej ani zerowej.
     */
    public static function szansa(float $wartoscWkladu, float $wartoscCelu, float $marza): float
    {
        if (!is_finite($wartoscWkladu) || !is_finite($wartoscCelu) || $wartoscCelu <= 0.0 || $wartoscWkladu <= 0.0) {
            return 0.0;
        }

        $marza = self::ograniczMarze($marza);

        $szansa = ($wartoscWkladu / $wartoscCelu) * (1.0 - $marza);

        if (!is_finite($szansa) || $szansa <= 0.0) {
            return 0.0;
        }

        return min(1.0, $szansa);
    }

    /** Marża platformy z tabeli `ustawienia` (klucz `upgrader_marza`). */
    public static function marza(?PDO $pdo = null): float
    {
        return self::ograniczMarze(Settings::getFloat(Settings::KEY_UPGRADER_MARGIN, 0.05, $pdo));
    }

    private static function ograniczMarze(float $marza): float
    {
        if (!is_finite($marza)) {
            return 0.05;
        }

        return max(0.0, min(0.95, $marza));
    }

    /**
     * Kalkulacja próby BEZ jej wykonywania — «Kalkulacja szansy %» z diagramu.
     *
     * GET api/upgrade.php zwraca dokładnie ten wynik, żeby frontend mógł
     * pokazać procenty na kole zanim gracz kliknie „Ulepsz".
     *
     * @return array<string, mixed>
     */
    public static function kalkulacja(int $userId, int $ekwipunekId, int $celPrzedmiotId, ?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        // 1. Wkład z ekwipunku gracza (404/409, gdy nie jego albo sprzedany).
        $ekwipunek = new Ekwipunek($userId, $db);
        $wklad = $ekwipunek->wpis($ekwipunekId);

        // 2. Cel z katalogu przedmiotów.
        $cel = Przedmiot::wymagaj($celPrzedmiotId, $db);

        if (!$cel->czyAktywny) {
            throw ApiException::badRequest('Ten przedmiot nie jest już dostępny w katalogu.', 'item_inactive');
        }

        $wartoscWkladu = (float) $wklad['wartosc'];
        $wartoscCelu = $cel->obliczWartosc();

        if ($wartoscWkladu <= 0.0) {
            throw ApiException::badRequest(
                'Przedmiot wkładu ma zerową wartość — nie da się z niego ulepszać.',
                'invalid_input_value'
            );
        }

        // Cel musi być droższy: przy tańszym szansa wyszłaby > 100%, a to była
        // albo maszynka do monet, albo „ulepszenie" w dół.
        if ($wartoscCelu <= $wartoscWkladu) {
            throw ApiException::badRequest(sprintf(
                'Cel musi być wart więcej niż wkład (%s > %s).',
                ApiException::money($wartoscCelu),
                ApiException::money($wartoscWkladu)
            ), 'target_must_be_more_valuable');
        }

        $marza = self::marza($db);
        $szansa = self::szansa($wartoscWkladu, $wartoscCelu, $marza);

        return [
            'wklad' => $wklad,
            'cel' => self::celDoApi($cel),
            'marza' => $marza,
            'szansa' => round($szansa, 6),
            'szansa_procent' => round($szansa * 100, 2),
            'wartosc_wkladu' => $wartoscWkladu,
            'wartosc_celu' => $wartoscCelu,
            'ekwipunek' => $ekwipunek,
            'cel_przedmiot' => $cel,
        ];
    }

    /** @return array<string, mixed> */
    private static function celDoApi(Przedmiot $cel): array
    {
        return [
            'przedmiot_id' => $cel->id,
            'nazwa' => $cel->nazwa,
            'rzadkosc' => $cel->rzadkosc->nazwa,
            'rzadkosc_kod' => $cel->rzadkosc->kod,
            'kolor' => $cel->rzadkosc->kolor,
            'wartosc' => $cel->obliczWartosc(),
            'ikona' => $cel->ikonaUrl(),
            'opis' => $cel->opis(),
        ];
    }

    /**
     * Wykonanie próby ulepszenia — POST api/upgrade.php.
     *
     * @param int                      $userId          id gracza
     * @param int                      $ekwipunekId     wpis wkładu z ekwipunku
     * @param int                      $celPrzedmiotId  przedmiot docelowy z katalogu
     * @param (callable(): float)|null $random          wstrzykiwalne koło (testy)
     * @return array<string, mixed>     pełny payload dla frontendu
     */
    public static function upgrade(
        int $userId,
        int $ekwipunekId,
        int $celPrzedmiotId,
        ?callable $random = null,
        ?PDO $pdo = null
    ): array {
        $db = self::polaczenie($pdo);

        // 1–3. Walidacja i kalkulacja szansy (przed jakimkolwiek zapisem).
        $dane = self::kalkulacja($userId, $ekwipunekId, $celPrzedmiotId, $db);

        /** @var Ekwipunek $ekwipunek */
        $ekwipunek = $dane['ekwipunek'];
        /** @var Przedmiot $cel */
        $cel = $dane['cel_przedmiot'];

        $szansa = (float) $dane['szansa'];
        $wartoscWkladu = (float) $dane['wartosc_wkladu'];

        // 5. Losowanie liczby wirtualnego koła (0.00% – 100.00%).
        $random ??= [WeightedRoller::class, 'randomFloat'];

        $los = (float) $random();

        if (!is_finite($los)) {
            $los = 0.0;
        }

        $los = max(0.0, min(1.0, $los));

        $kolo = round($los * 100, 2);
        $udane = $los < $szansa;

        $db->beginTransaction();

        try {
            // 4. Zablokowanie i trwałe usunięcie wkładu z bazy.
            $ekwipunek->spalWpis($ekwipunekId);

            $nowyWpisId = null;
            $wartoscWygranej = 0.0;

            if ($udane) {
                // 6a. Wygrana: dodanie ulepszonego przedmiotu do bazy.
                $wartoscWygranej = $cel->obliczWartosc();
                $nowyWpisId = $ekwipunek->dodaj($cel, $wartoscWygranej);
            }

            // 6b. Porażka: wkład już spalony, do ekwipunku nic nie trafia.

            // 7. Historia losowań (typ = upgrader). waga/suma = szansa/100,
            //    dzięki czemu Losowanie::historia() policzy szansę tak samo
            //    jak dla łowiska i skrzynki.
            $losowanie = new Losowanie(
                $userId,
                TypLosowania::Upgrader,
                $cel,
                date('Y-m-d H:i:s'),
                $wartoscWkladu,
                $wartoscWygranej,
                $szansa * 100.0,
                100.0,
                0,
                0,
                $db,
                0,
                $udane
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

        $saldo = Portfel::dlaUzytkownika($userId, $db)->saldo;

        $przedmiot = null;

        if ($udane) {
            $przedmiot = self::celDoApi($cel);
            $przedmiot['ekwipunek_id'] = $nowyWpisId;
            $przedmiot['szansa_procent'] = round($szansa * 100, 2);
        }

        return [
            'success' => true,
            'udane' => $udane,
            'kolo' => $kolo,
            'szansa' => round($szansa, 6),
            'szansa_procent' => round($szansa * 100, 2),
            'marza' => $dane['marza'],
            'wklad' => $dane['wklad'],
            'cel' => $dane['cel'],
            'przedmiot' => $przedmiot,
            'historia_id' => $historiaId,
            'saldo' => round($saldo, 2),
            'message' => $udane
                ? sprintf('Ulepszenie udane: %s → %s!', $dane['wklad']['nazwa'], $cel->nazwa)
                : sprintf('Porażka — przedmiot „%s" przepadł bezpowrotnie.', $dane['wklad']['nazwa']),
        ];
    }

    private static function polaczenie(?PDO $pdo): PDO
    {
        return $pdo ?? Database::connection();
    }
}
