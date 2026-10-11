<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;
use Throwable;

/**
 * backend/TraderService.php
 *
 * «Wymiana przedmiotów (Trader)» — przypadek użycia UC_TRADE z diagramu use case
 * i „Wymiana w Traderze (POST /api/trade_up.php)" ze strony 2 diagramu
 * MOCNYDROP_PROJEKT.
 *
 * Kolejność kroków dokładnie jak na diagramie przepływu:
 *
 *   1. wybór 3–10 przedmiotów ze swojego ekwipunku   (Ekwipunek::wpisy)
 *   2. zsumowanie łącznej wartości rynkowej wkładu    (Ekwipunek::sumaWartosci)
 *   3. weryfikacja minimalnego progu wartości         (TRADE_ERR = za niska wartość)
 *   4. trwałe usunięcie (spalenie) wybranych          (Ekwipunek::spalWpisy)
 *   5. losowanie 1 nagrody z WYŻSZEJ puli rzadkości   (WeightedRoller)
 *   6. zapis nowego przedmiotu do bazy                (Ekwipunek::dodaj + Losowanie::zapisz)
 *
 * Kroki 4–6 są w JEDNEJ transakcji: spalenie wkładu bez nagrody byłoby
 * najgorszym możliwym błędem tej gry.
 *
 * «Wyższa pula rzadkości» = przedmioty o `rzadkosci.kolejnosc` wyższej niż
 * najrzadszy przedmiot wkładu, ważone wagami domyślnymi z README.md. Jeżeli
 * gracz wrzuci same przedmioty ULTRA, wyższej puli nie ma — odrzucamy kontrakt
 * PRZED spaleniem czegokolwiek (kod no_higher_pool).
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class TraderService
{
    /**
     * Limity kontraktu z tabeli `ustawienia`.
     *
     * @return array{min_przedmiotow: int, max_przedmiotow: int, min_wartosc: float}
     */
    public static function limity(?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        $min = max(1, Settings::getInt(Settings::KEY_TRADER_MIN_ITEMS, 3, $db));
        $max = max($min, Settings::getInt(Settings::KEY_TRADER_MAX_ITEMS, 10, $db));

        $minWartosc = Settings::getFloat(Settings::KEY_TRADER_MIN_VALUE, 10.0, $db);

        if (!is_finite($minWartosc) || $minWartosc < 0) {
            $minWartosc = 0.0;
        }

        return ['min_przedmiotow' => $min, 'max_przedmiotow' => $max, 'min_wartosc' => $minWartosc];
    }

    /**
     * Kalkulacja kontraktu BEZ jego wykonywania — GET api/trade_up.php.
     *
     * Frontend pokazuje dzięki temu sumę wkładu, próg i pulę nagród z szansami
     * zanim gracz kliknie „Wymień".
     *
     * @param list<int> $ekwipunekIds
     * @return array<string, mixed>
     */
    public static function kalkulacja(int $userId, array $ekwipunekIds, ?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        $limity = self::limity($db);

        // 1. Wybór przedmiotów ze swojego ekwipunku.
        $ids = self::normalizujId($ekwipunekIds);

        if (count($ids) < $limity['min_przedmiotow']) {
            throw ApiException::badRequest(sprintf(
                'Kontrakt wymaga co najmniej %d przedmiotów (wybrano %d).',
                $limity['min_przedmiotow'],
                count($ids)
            ), 'too_few_items');
        }

        if (count($ids) > $limity['max_przedmiotow']) {
            throw ApiException::badRequest(sprintf(
                'Kontrakt przyjmuje maksymalnie %d przedmiotów (wybrano %d).',
                $limity['max_przedmiotow'],
                count($ids)
            ), 'too_many_items');
        }

        $ekwipunek = new Ekwipunek($userId, $db);
        $wpisy = $ekwipunek->wpisy($ids);

        // 2. Zsumowanie łącznej wartości rynkowej wkładu.
        $wartoscWkladu = Ekwipunek::sumaWartosci($wpisy);

        // 3. Weryfikacja minimalnego progu — «Odrzucenie: za niska wartość» (TRADE_ERR).
        if ($wartoscWkladu < $limity['min_wartosc']) {
            throw ApiException::badRequest(sprintf(
                'Łączna wartość wkładu (%s) jest poniżej progu kontraktu (%s).',
                ApiException::money($wartoscWkladu),
                ApiException::money($limity['min_wartosc'])
            ), 'trade_below_minimum');
        }

        // 5 (przygotowanie). Pula nagród = przedmioty rzadsze niż najrzadszy wkład.
        $maxKolejnosc = 0;
        $poziomWejsciowy = '';

        foreach ($wpisy as $wpis) {
            if ((int) $wpis['rzadkosc_kolejnosc'] > $maxKolejnosc) {
                $maxKolejnosc = (int) $wpis['rzadkosc_kolejnosc'];
                $poziomWejsciowy = (string) $wpis['rzadkosc_kod'];
            }
        }

        $pula = Przedmiot::pulaRzadszych($maxKolejnosc, $db);

        if ($pula === []) {
            throw ApiException::badRequest(
                'Nie ma już przedmiotów rzadszych niż Twój wkład — kontrakt nie ma puli nagród.',
                'no_higher_pool'
            );
        }

        return [
            'ekwipunek' => $ekwipunek,
            'wpisy' => $wpisy,
            'wartosc_wkladu' => $wartoscWkladu,
            'poziom_wejsciowy' => $poziomWejsciowy,
            'poziom_wejsciowy_kolejnosc' => $maxKolejnosc,
            'pula' => $pula,
            'limity' => $limity,
        ];
    }

    /**
     * @param list<mixed> $ids
     * @return list<int>
     */
    private static function normalizujId(array $ids): array
    {
        $out = [];

        foreach ($ids as $id) {
            if (is_array($id) || is_object($id) || !is_scalar($id)) {
                throw ApiException::badRequest('Lista przedmiotów musi zawierać liczby (ekwipunek_id).', 'invalid_entry_list');
            }

            if (!preg_match('/^\d+$/', trim((string) $id))) {
                throw ApiException::badRequest('Lista przedmiotów musi zawierać liczby (ekwipunek_id).', 'invalid_entry_list');
            }

            $out[] = (int) $id;
        }

        return $out;
    }

    /**
     * Wykonanie kontraktu — POST api/trade_up.php.
     *
     * @param int                      $userId
     * @param list<int>                $ekwipunekIds   wpisy z ekwipunku gracza (3–10)
     * @param (callable(): float)|null $random         wstrzykiwalny generator (testy)
     * @return array<string, mixed>    pełny payload dla frontendu
     */
    public static function trade(int $userId, array $ekwipunekIds, ?callable $random = null, ?PDO $pdo = null): array
    {
        $db = self::polaczenie($pdo);

        // Kroki 1–3: wybór, suma wartości, próg kontraktu (przed zapisem).
        $dane = self::kalkulacja($userId, $ekwipunekIds, $db);

        /** @var Ekwipunek $ekwipunek */
        $ekwipunek = $dane['ekwipunek'];
        /** @var list<array<string, mixed>> $pula */
        $pula = $dane['pula'];
        /** @var list<array<string, mixed>> $wpisy */
        $wpisy = $dane['wpisy'];

        $wartoscWkladu = (float) $dane['wartosc_wkladu'];

        $db->beginTransaction();

        try {
            // 4. Trwałe usunięcie (spalenie) wybranych przedmiotów.
            $spalone = $ekwipunek->spalWpisy(array_map(
                static fn (array $wpis): int => (int) $wpis['ekwipunek_id'],
                $wpisy
            ));

            // 5. Losowanie JEDNEJ nagrody z wyższej puli rzadkości.
            $winner = WeightedRoller::roll($pula, $random);
            $nagroda = Przedmiot::zPuli($winner);
            $wartoscNagrody = $nagroda->obliczWartosc();

            // 6. Zapis nowego wygenerowanego przedmiotu do bazy.
            $nowyWpisId = $ekwipunek->dodaj($nagroda, $wartoscNagrody);

            $losowanie = new Losowanie(
                $userId,
                TypLosowania::Trader,
                $nagroda,
                date('Y-m-d H:i:s'),
                $wartoscWkladu,
                $wartoscNagrody,
                (float) $winner['waga'],
                (float) $winner['suma_wag'],
                0,
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

        // Pasek ruletki — ta sama prezentacja co w łowisku i skrzynce.
        $dlugosc = Settings::getInt(Settings::KEY_REEL_LENGTH, 60, $db);
        $indeksWygranej = Settings::getInt(Settings::KEY_REEL_WINNER_INDEX, 54, $db);

        if ($indeksWygranej >= $dlugosc) {
            $indeksWygranej = max(0, $dlugosc - 1);
        }

        $reel = Reel::build($pula, $winner, $dlugosc, $indeksWygranej, $random);

        return [
            'success' => true,
            'kontrakt' => [
                'liczba_przedmiotow' => count($spalone),
                'wartosc_wkladu' => $wartoscWkladu,
                'min_wartosc' => $dane['limity']['min_wartosc'],
                'min_przedmiotow' => $dane['limity']['min_przedmiotow'],
                'max_przedmiotow' => $dane['limity']['max_przedmiotow'],
                'poziom_wejsciowy' => $dane['poziom_wejsciowy'],
            ],
            'spalone' => $spalone,
            'nagroda' => [
                'ekwipunek_id' => $nowyWpisId,
                'przedmiot_id' => $nagroda->id,
                'nazwa' => $nagroda->nazwa,
                'rzadkosc' => $nagroda->rzadkosc->nazwa,
                'rzadkosc_kod' => $nagroda->rzadkosc->kod,
                'kolor' => $nagroda->rzadkosc->kolor,
                'wartosc' => $wartoscNagrody,
                'ikona' => $nagroda->ikonaUrl(),
                'szansa_procent' => (float) $winner['szansa_procent'],
            ],
            'pula' => array_map(static fn (array $item): array => [
                'przedmiot_id' => $item['przedmiot_id'],
                'nazwa' => $item['nazwa'],
                'rzadkosc' => $item['rzadkosc_nazwa'],
                'rzadkosc_kod' => $item['rzadkosc_kod'],
                'kolor' => $item['kolor'],
                'wartosc' => $item['wartosc'],
                'ikona' => $item['ikona_url'],
                'waga' => $item['waga'],
                'szansa_procent' => $item['szansa_procent'],
            ], $pula),
            'reel' => $reel,
            'reel_dlugosc' => $dlugosc,
            'reel_indeks_wygranej' => $indeksWygranej,
            'historia_id' => $historiaId,
            'saldo' => round(Portfel::dlaUzytkownika($userId, $db)->saldo, 2),
            'message' => sprintf(
                'Kontrakt zrealizowany: %d przedmiotów (%s) → %s (%s).',
                count($spalone),
                ApiException::money($wartoscWkladu),
                $nagroda->nazwa,
                $nagroda->rzadkosc->nazwa
            ),
        ];
    }

    private static function polaczenie(?PDO $pdo): PDO
    {
        return $pdo ?? Database::connection();
    }
}
