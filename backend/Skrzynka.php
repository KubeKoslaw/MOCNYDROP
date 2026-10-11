<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;
use Throwable;

/**
 * backend/Skrzynka.php
 *
 * Klasa Skrzynka z diagramu klas:
 *
 *     - id : int
 *     - nazwa : string
 *     - cena : float
 *     - ikona : string
 *     - aktywna : bool
 *     + otworz(u : Uzytkownik) : Przedmiot
 *     + losuj() : Przedmiot
 *     + czyAktywna() : bool
 *
 * Asocjacje z diagramu:
 *   Skrzynka — SkrzynkaPrzedmiot (* : *)  -> tabela skrzynka_przedmioty
 *   Skrzynka — Losowanie (* : 1)          -> historia_losowan.skrzynka_id
 *   Skrzynka ..|> ILosujacy (krawędź e13) -> implements ILosujacy
 *
 * To jest tryb «Otwarcie skrzynki» z diagramu use case (UC_CASE) i ze strony 2
 * diagramu PROJEKT (POST /api/open_case.php). Kolejność kroków w otworz():
 *
 *   1. czyAktywna() + niepusta pula       (bramka, jak Wedka::zarzuc())
 *   2. Portfel::pobierzOplate(cena)       («Brak środków» = CASE_ERR)
 *   3. losuj() -> WeightedRoller          P(i) = w_i / suma(w)
 *   4. Ekwipunek::dodaj(wynik)            wygrana do ekwipunku
 *   5. Losowanie::zapisz()                historia (typ = skrzynka)
 *
 * Kroki 2–5 są w JEDNEJ transakcji: gracz nigdy nie traci monet bez wygranej.
 * «Natychmiastowa sprzedaż wygranej» (CASE_SELL) dzieje się już PO commicie,
 * bo Ekwipunek::sprzedajWpis() prowadzi własną transakcję.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class Skrzynka implements ILosujacy
{
    /** @var list<array<string, mixed>>|null pamięć puli na czas jednego żądania */
    private ?array $pulaCache = null;

    /**
     * Pula przygotowana do zapisu przez Administrator::edytujSkrzynke().
     *
     * Diagram nie rysuje tego atrybutu — to nośnik danych z panelu admina
     * («Przypisanie dropów i wag»), dzięki któremu sygnatura edytujSkrzynke(s)
     * zostaje dokładnie taka jak na diagramie, a zapis jest jeden i transakcyjny.
     *
     * @var list<array{przedmiot_id?: mixed, waga?: mixed}>|null
     */
    public ?array $pulaDoZapisu = null;

    /** @var int 0 = skrzynka jeszcze niezapisana w bazie (kreator admina) */
    public int $id;

    public function __construct(
        public readonly string $nazwa,
        public readonly float $cena,
        public readonly string $ikona = '',
        public readonly bool $aktywna = true,
        public readonly string $opis = '',
        public readonly int $kolejnosc = 0,
        int $id = 0,
        private ?PDO $db = null,
    ) {
        $this->id = $id;
    }

    /** Metoda z diagramu: `+ czyAktywna() : bool`. */
    public function czyAktywna(): bool
    {
        return $this->aktywna;
    }

    /**
     * Metoda z diagramu: `+ losuj() : Przedmiot`.
     *
     * Realizacja interfejsu ILosujacy — ważne losowanie po puli skrzynki.
     * Wybór wpisu robi WeightedRoller, a sam przedmiot zwraca wylosowany
     * SkrzynkaPrzedmiot::losuj() (też ILosujacy, krawędź e14).
     */
    public function losuj(): Przedmiot
    {
        return $this->losujWPuli()[0]->losuj();
    }

    /**
     * Metoda z diagramu: `+ otworz(u : Uzytkownik) : Przedmiot`.
     *
     * Pełny przypadek użycia UC_CASE: opłata, losowanie, ekwipunek, historia.
     */
    public function otworz(Uzytkownik $u): Przedmiot
    {
        /** @var Przedmiot $przedmiot */
        $przedmiot = $this->otworzSzczegolowo($u)['przedmiot'];

        return $przedmiot;
    }

    /** Wszystkie aktywne skrzynki — na start strony sklepu. @return list<self> */
    public static function aktywne(?PDO $pdo = null): array
    {
        return self::lista('SELECT * FROM skrzynki WHERE aktywna = 1 ORDER BY kolejnosc ASC, cena ASC, id ASC', $pdo);
    }

    /** Także nieaktywne — panel administratora (UC_ADM1). @return list<self> */
    public static function wszystkie(?PDO $pdo = null): array
    {
        return self::lista('SELECT * FROM skrzynki ORDER BY kolejnosc ASC, cena ASC, id ASC', $pdo);
    }

    public static function znajdz(int $id, ?PDO $pdo = null): ?self
    {
        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare('SELECT * FROM skrzynki WHERE id = ?');
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return $row === false ? null : self::zWiersza($row, $db);
    }

    /** Jak znajdz(), ale rzuca 404 zamiast zwracać null. */
    public static function wymagaj(int $id, ?PDO $pdo = null): self
    {
        $skrzynka = self::znajdz($id, $pdo);

        if ($skrzynka === null) {
            throw ApiException::notFound('Nie ma takiej skrzynki.', 'case_not_found');
        }

        return $skrzynka;
    }

    /**
     * Nowa skrzynka (jeszcze niezapisana) — dla kreatora w panelu admina.
     * Zapisuje ją Administrator::edytujSkrzynke().
     */
    public static function nowa(
        string $nazwa,
        float $cena,
        string $ikona = '',
        string $opis = '',
        bool $aktywna = true,
        int $kolejnosc = 0,
        ?PDO $pdo = null,
    ): self {
        return new self($nazwa, $cena, $ikona, $aktywna, $opis, $kolejnosc, 0, $pdo);
    }

    /** @return list<self> */
    private static function lista(string $sql, ?PDO $pdo): array
    {
        $db = $pdo ?? Database::connection();

        $out = [];

        foreach ($db->query($sql)->fetchAll() as $row) {
            $out[] = self::zWiersza($row, $db);
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    public static function zWiersza(array $row, ?PDO $pdo = null): self
    {
        return new self(
            (string) $row['nazwa'],
            (float) $row['cena'],
            (string) $row['ikona'],
            (int) $row['aktywna'] === 1,
            (string) $row['opis'],
            (int) $row['kolejnosc'],
            (int) $row['id'],
            $pdo,
        );
    }

    /**
     * Pula przedmiotów skrzynki z wagami i szansami procentowymi.
     *
     * Kształt wiersza jest IDENTYCZNY jak Wedka::pula() — dzięki temu ten sam
     * WeightedRoller, ten sam Reel i ten sam kod frontendu obsługują oba tryby.
     *
     * @return list<array<string, mixed>>
     */
    public function pula(?PDO $pdo = null): array
    {
        if ($this->pulaCache !== null && $pdo === null) {
            return $this->pulaCache;
        }

        $db = $pdo ?? $this->polaczenie();

        $stmt = $db->prepare(
            'SELECT sp.przedmiot_id,
                    p.nazwa,
                    p.wartosc,
                    p.ikona,
                    p.opis,
                    z.kod           AS rzadkosc_kod,
                    z.nazwa         AS rzadkosc_nazwa,
                    z.mnoznik       AS mnoznik,
                    z.waga_domyslna AS waga_domyslna,
                    z.kolor         AS kolor,
                    z.kolejnosc     AS rzadkosc_kolejnosc,
                    COALESCE(sp.waga, z.waga_domyslna) AS waga
             FROM skrzynka_przedmioty sp
             JOIN przedmioty p ON p.id = sp.przedmiot_id
             JOIN rzadkosci z  ON z.kod = p.rzadkosc_kod
             WHERE sp.skrzynka_id = ? AND p.czy_aktywny = 1
             ORDER BY z.kolejnosc ASC, p.wartosc ASC, p.id ASC'
        );
        $stmt->execute([$this->id]);

        $items = [];

        foreach ($stmt->fetchAll() as $row) {
            $ikona = (string) $row['ikona'];

            $items[] = [
                'przedmiot_id' => (int) $row['przedmiot_id'],
                'nazwa' => (string) $row['nazwa'],
                'rzadkosc_kod' => (string) $row['rzadkosc_kod'],
                'rzadkosc_nazwa' => (string) $row['rzadkosc_nazwa'],
                'rzadkosc_kolejnosc' => (int) $row['rzadkosc_kolejnosc'],
                'mnoznik' => (float) $row['mnoznik'],
                'waga_domyslna' => (float) $row['waga_domyslna'],
                'kolor' => (string) $row['kolor'],
                'wartosc' => (float) $row['wartosc'],
                'ikona' => $ikona,
                'ikona_url' => Config::iconUrl($ikona),
                'ikona_istnieje' => Config::iconExists($ikona),
                'opis' => (string) $row['opis'],
                'waga' => (float) $row['waga'],
            ];
        }

        // Szanse liczymy raz dla całej puli — tak samo jak w Wedka::pula().
        $chances = WeightedRoller::chances($items);

        foreach ($items as $i => $item) {
            $items[$i]['szansa_procent'] = $chances[$i];
        }

        if ($pdo === null) {
            $this->pulaCache = $items;
        }

        return $items;
    }

    /**
     * Bramka otwarcia: skrzynka musi być aktywna i mieć przynajmniej jeden drop.
     *
     * Odpowiednik Wedka::zarzuc() — sprawdzamy to PRZED pobraniem opłaty, żeby
     * gracz nie płacił za skrzynkę, której nie da się otworzyć.
     */
    public function czyDajeSieOtworzyc(): bool
    {
        return $this->czyAktywna() && $this->pula() !== [];
    }

    private function wymagajOtwieralna(): void
    {
        if (!$this->czyAktywna()) {
            throw ApiException::badRequest('Ta skrzynka jest obecnie niedostępna.', 'case_inactive');
        }

        if ($this->pula() === []) {
            throw ApiException::badRequest('Ta skrzynka nie ma jeszcze żadnych przedmiotów.', 'case_empty');
        }
    }

    /**
     * Ważone losowanie jednego wpisu puli.
     *
     * @return array{0: SkrzynkaPrzedmiot, 1: array<string, mixed>} wylosowany wpis + wiersz puli
     */
    private function losujWPuli(?callable $random = null): array
    {
        $this->wymagajOtwieralna();

        $winner = WeightedRoller::roll($this->pula(), $random);

        // Wylosowany wpis puli zwraca przedmiot swoją metodą z diagramu
        // (SkrzynkaPrzedmiot::losuj()) — tak działa krawędź e14.
        $wpis = new SkrzynkaPrzedmiot(
            0,
            $this->id,
            (int) $winner['przedmiot_id'],
            (float) $winner['waga'],
            $this->polaczenie()
        );

        return [$wpis, $winner];
    }

    /**
     * Pełny przebieg otwarcia skrzynki — wszystko, czego potrzebuje
     * api/open_case.php (metoda z diagramu `otworz()` jest jej uproszczeniem).
     *
     * @param (callable(): float)|null $random wstrzykiwalny generator (testy)
     * @return array<string, mixed>
     */
    public function otworzSzczegolowo(
        Uzytkownik $u,
        bool $sprzedajNatychmiast = false,
        ?callable $random = null,
        ?PDO $pdo = null
    ): array {
        $db = $pdo ?? $this->polaczenie();

        // Bramka PRZED transakcją: nie pobieramy opłaty za skrzynkę, której
        // nie da się otworzyć.
        $this->wymagajOtwieralna();

        $cena = $this->cena;

        $db->beginTransaction();

        try {
            // Portfel::pobierzOplate() — kompozycja Uzytkownik ◆— Portfel (1:1).
            $portfel = Portfel::dlaUzytkownika($u->id, $db);
            $saldoPrzed = $portfel->saldo;

            if ($cena > 0.0 && !$portfel->pobierzOplate($cena)) {
                // «Brak środków» — przypadek rozszerzający CASE_ERR z diagramu.
                throw ApiException::insufficientFunds($cena, $saldoPrzed);
            }

            $saldoPoOplacie = $portfel->saldo;

            // Ważone losowanie P(i) = w_i / suma(w) — ten sam WeightedRoller,
            // którego używa łowisko.
            [$wpis, $winner] = $this->losujWPuli($random);

            $wynik = $wpis->losuj();

            // Przedmiot::obliczWartosc() i zamrożenie wartości w chwili nabycia.
            $wartosc = $wynik->obliczWartosc();

            $ekwipunek = new Ekwipunek($u->id, $db);
            $ekwipunekId = $ekwipunek->dodaj($wynik, $wartosc);

            $losowanie = new Losowanie(
                $u->id,
                TypLosowania::Skrzynka,
                $wynik,
                date('Y-m-d H:i:s'),
                $cena,
                $wartosc,
                (float) $winner['waga'],
                (float) $winner['suma_wag'],
                0,
                0,
                $db,
                $this->id
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

        $szczegoly = [
            'przedmiot' => $wynik,
            'historia_id' => $historiaId,
            'ekwipunek_id' => $ekwipunekId,
            'cena' => $cena,
            'wartosc' => $wartosc,
            'saldo_przed' => round($saldoPrzed, 2),
            'saldo_po' => round($saldoPoOplacie, 2),
            'waga' => (float) $winner['waga'],
            'suma_wag' => (float) $winner['suma_wag'],
            'szansa_procent' => (float) $winner['szansa_procent'],
            'sprzedaz' => null,
        ];

        // «Natychmiastowa sprzedaż wygranej» (CASE_SELL z diagramu use case) —
        // PO commicie, bo Ekwipunek::sprzedajWpis() prowadzi własną transakcję.
        if ($sprzedajNatychmiast) {
            $szczegoly['sprzedaz'] = (new Ekwipunek($u->id, $db))->sprzedajWpis($ekwipunekId);
            $szczegoly['saldo_po'] = (float) $szczegoly['sprzedaz']['nowe_saldo'];
        }

        // Pasek ruletki budujemy PO commicie — to już tylko prezentacja wyniku.
        $dlugosc = Settings::getInt(Settings::KEY_REEL_LENGTH, 60, $db);
        $indeksWygranej = Settings::getInt(Settings::KEY_REEL_WINNER_INDEX, 54, $db);

        if ($indeksWygranej >= $dlugosc) {
            $indeksWygranej = max(0, $dlugosc - 1);
        }

        $szczegoly['reel'] = Reel::build($this->pula($db), $winner, $dlugosc, $indeksWygranej, $random);
        $szczegoly['reel_dlugosc'] = $dlugosc;
        $szczegoly['reel_indeks_wygranej'] = $indeksWygranej;

        return $szczegoly;
    }

    /** Skrzynka w kształcie, jaki zwraca API. @return array<string, mixed> */
    public function doApi(): array
    {
        $pula = $this->pula();

        return [
            'id' => $this->id,
            'nazwa' => $this->nazwa,
            'opis' => $this->opis,
            'cena' => $this->cena,
            'ikona' => Config::iconUrl($this->ikona),
            'aktywna' => $this->aktywna,
            'liczba_przedmiotow' => count($pula),
            'przedmioty' => array_map(static fn (array $item): array => [
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
        ];
    }

    private function polaczenie(): PDO
    {
        return $this->db ??= Database::connection();
    }
}
