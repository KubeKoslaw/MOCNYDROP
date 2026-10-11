<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;

/**
 * backend/Wedka.php
 *
 * Klasa Wedka z diagramu klas:
 *
 *     - id : int
 *     - nazwa : string
 *     - kosztUzycia : float
 *     + zarzuc() : bool
 *
 * Asocjacja z diagramu: Uzytkownik ◆— Wedka (1 : 0..*).
 *
 * Implementacja jest katalogowa, a nie własnościowa: gracz NIE kupuje wędki na
 * stałe, tylko płaci `kosztUzycia` za każdy zarzut (strona 2 diagramu PROJEKT:
 * „Wybór wędki i przynęty (Pobranie opłaty z portfela)"). Stąd brak kolumny
 * user_id w tabeli `wedki`.
 *
 * Diagram nie rysuje asocjacji Wedka — Przedmiot, ale mechanika potrzebuje puli
 * z wagami, więc jest tabela łącząca `wedka_przedmioty` (odpowiednik
 * SkrzynkaPrzedmiot dla trybu skrzynek).
 *
 * Wagi biorą się z COALESCE(wp.waga, z.waga_domyslna): @moxmar26 może w seedzie
 * podać wagę jawnie per wędka albo zostawić NULL i odziedziczyć wagę poziomu
 * rzadkości z README.md.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class Wedka
{
    /** @var list<array<string, mixed>>|null pamięć puli na czas jednego żądania */
    private ?array $pulaCache = null;

    public function __construct(
        public readonly int $id,
        public readonly string $nazwa,
        public readonly float $kosztUzycia,
        public readonly string $opis = '',
        public readonly string $ikona = '',
        public readonly bool $czyAktywna = true,
        public readonly int $kolejnosc = 0,
        private ?PDO $db = null,
    ) {
    }

    /**
     * Metoda z diagramu: `+ zarzuc() : bool`.
     *
     * Odpowiada na pytanie, czy zarzut jest w ogóle możliwy — wędka musi być
     * aktywna i mieć niepustą pulę. Pieniądze i samo losowanie robi
     * FishingService (w transakcji), więc ta metoda jest bramką, nie operacją.
     */
    public function zarzuc(): bool
    {
        return $this->czyAktywna && $this->pula() !== [];
    }

    /** Wszystkie aktywne wędki wraz z pulami — na start strony łowienia. @return list<self> */
    public static function aktywne(?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        $rows = $db
            ->query('SELECT * FROM wedki WHERE czy_aktywna = 1 ORDER BY kolejnosc ASC, koszt_uzycia ASC, id ASC')
            ->fetchAll();

        $out = [];

        foreach ($rows as $row) {
            $out[] = self::zWiersza($row, $db);
        }

        return $out;
    }

    public static function znajdz(int $id, ?PDO $pdo = null): ?self
    {
        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare('SELECT * FROM wedki WHERE id = ?');
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return $row === false ? null : self::zWiersza($row, $db);
    }

    /** Jak znajdz(), ale rzuca 404 zamiast zwracać null. */
    public static function wymagaj(int $id, ?PDO $pdo = null): self
    {
        $wedka = self::znajdz($id, $pdo);

        if ($wedka === null) {
            throw ApiException::notFound('Nie ma takiej wędki.', 'rod_not_found');
        }

        return $wedka;
    }

    /**
     * Pula przedmiotów wędki z wagami i szansami procentowymi.
     * To jest dokładnie ta lista, którą dostaje WeightedRoller::roll().
     *
     * @return list<array<string, mixed>>
     */
    public function pula(?PDO $pdo = null): array
    {
        if ($this->pulaCache !== null) {
            return $this->pulaCache;
        }

        $db = $pdo ?? $this->polaczenie();

        $stmt = $db->prepare(
            'SELECT p.id AS przedmiot_id,
                    p.nazwa,
                    p.rzadkosc_kod,
                    p.wartosc,
                    p.ikona,
                    p.opis,
                    z.nazwa         AS rzadkosc_nazwa,
                    z.mnoznik       AS mnoznik,
                    z.waga_domyslna AS waga_domyslna,
                    z.kolor         AS kolor,
                    z.kolejnosc     AS rzadkosc_kolejnosc,
                    COALESCE(wp.waga, z.waga_domyslna) AS waga
             FROM wedka_przedmioty wp
             JOIN przedmioty p ON p.id = wp.przedmiot_id
             JOIN rzadkosci z  ON z.kod = p.rzadkosc_kod
             WHERE wp.wedka_id = ? AND p.czy_aktywny = 1
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

        // Szanse liczymy raz dla całej puli — taniej i spójniej niż per element.
        $chances = WeightedRoller::chances($items);

        foreach ($items as $i => $item) {
            $items[$i]['szansa_procent'] = $chances[$i];
        }

        return $this->pulaCache = $items;
    }

    /** Wędka w kształcie, jaki zwraca API. @return array<string, mixed> */
    public function doApi(): array
    {
        $pula = $this->pula();

        return [
            'id' => $this->id,
            'nazwa' => $this->nazwa,
            'opis' => $this->opis,
            'koszt_uzycia' => $this->kosztUzycia,
            'ikona' => Config::iconUrl($this->ikona),
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

    /** @param array<string, mixed> $row */
    private static function zWiersza(array $row, PDO $db): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['nazwa'],
            (float) $row['koszt_uzycia'],
            (string) $row['opis'],
            (string) $row['ikona'],
            (int) $row['czy_aktywna'] === 1,
            (int) $row['kolejnosc'],
            $db,
        );
    }

    private function polaczenie(): PDO
    {
        return $this->db ??= Database::connection();
    }
}
