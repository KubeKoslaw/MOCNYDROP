<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;

/**
 * backend/Losowanie.php
 *
 * Klasa Losowanie z diagramu klas:
 *
 *     - id : int
 *     - czas : datetime
 *     - typ : TypLosowania
 *     - wynik : Przedmiot
 *     + zapisz()
 *     + wyliczRTP() : float
 *
 * Asocjacje z diagramu:
 *   Uzytkownik — Losowanie (0..*, «historia»)  -> historia_losowan.user_id
 *   Losowanie — Przedmiot (*:1, «wynik»)       -> historia_losowan.wynik_id
 *   Losowanie — Skrzynka (*:1)                 -> na razie historia_losowan.wedka_id
 *
 * Jedna tabela na WSZYSTKIE tryby gry (typ z enuma TypLosowania) — dlatego
 * monitoring administratora ze strony 3 diagramu PROJEKT może liczyć RTP
 * wspólnie albo w rozbiciu na tryb.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class Losowanie
{
    /** @var int 0 = wpis jeszcze niezapisany */
    public int $id;

    public function __construct(
        public readonly int $uzytkownikId,
        public TypLosowania $typ,
        public ?Przedmiot $wynik,
        public string $czas,
        public readonly float $koszt = 0.0,
        public readonly float $wygranaWartosc = 0.0,
        public readonly float $wagaWylosowana = 0.0,
        public readonly float $sumaWag = 0.0,
        public readonly int $wedkaId = 0,
        int $id = 0,
        private ?PDO $db = null,
    ) {
        $this->id = $id;
    }

    /**
     * Metoda z diagramu: `+ zapisz()`.
     *
     * Wywoływana wewnątrz transakcji FishingService, więc albo zapisze się
     * razem z opłatą i ekwipunkiem, albo wcale.
     */
    public function zapisz(): void
    {
        if ($this->wynik === null) {
            throw ApiException::badRequest('Losowanie bez wyniku nie może zostać zapisane.', 'no_result');
        }

        $db = $this->polaczenie();

        $stmt = $db->prepare(
            'INSERT INTO historia_losowan
                (user_id, typ, wynik_id, wedka_id, koszt, wygrana_wartosc, waga_wylosowana, suma_wag, czas)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $this->uzytkownikId,
            $this->typ->value,
            $this->wynik->id,
            $this->wedkaId > 0 ? $this->wedkaId : null,
            $this->koszt,
            $this->wygranaWartosc,
            $this->wagaWylosowana,
            $this->sumaWag,
            $this->czas !== '' ? $this->czas : date('Y-m-d H:i:s'),
        ]);

        $this->id = (int) $db->lastInsertId();
    }

    /**
     * Metoda z diagramu: `+ wyliczRTP() : float`.
     *
     * Return To Player = suma wypłaconych wartości / suma pobranych opłat dla
     * tego samego typu losowania (wszyscy gracze). 1.0 = gra oddaje dokładnie
     * tyle, ile bierze; poniżej 1.0 = przewaga platformy.
     *
     * To jest liczba, której potrzebuje «Monitoring Aktywności» ze strony 3
     * diagramu PROJEKT (Wyliczenie wskaźnika RTP z tabeli historia_losowan).
     */
    public function wyliczRTP(): float
    {
        return self::rtpDlaTypu($this->typ, $this->polaczenie());
    }

    /** RTP liczone globalnie dla jednego trybu gry. */
    public static function rtpDlaTypu(TypLosowania $typ, ?PDO $pdo = null): float
    {
        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare(
            'SELECT COALESCE(SUM(wygrana_wartosc), 0) AS wygrane, COALESCE(SUM(koszt), 0) AS koszty
             FROM historia_losowan
             WHERE typ = ?'
        );
        $stmt->execute([$typ->value]);

        $row = $stmt->fetch();

        $koszty = (float) ($row['koszty'] ?? 0.0);

        // Bez kosztów nie ma mianownika — zwracamy 0.0 zamiast dzielenia przez zero.
        if ($koszty <= 0.0) {
            return 0.0;
        }

        return round((float) ($row['wygrane'] ?? 0.0) / $koszty, 6);
    }

    /**
     * Historia losowań gracza — tabela „ostatnie połowy" w UI.
     *
     * @return list<array<string, mixed>>
     */
    public static function historia(int $userId, int $limit = 20, ?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        // Limit z API jest liczbą od użytkownika — tniemy zakres, żeby nikt
        // nie wysłał limit=999999 i nie wyciągnął całej tabeli jednym requestem.
        $limit = max(1, min(100, $limit));

        $stmt = $db->prepare(
            'SELECT h.id, h.typ, h.koszt, h.wygrana_wartosc, h.waga_wylosowana, h.suma_wag, h.czas,
                    p.id AS przedmiot_id, p.nazwa, p.ikona,
                    z.nazwa AS rzadkosc_nazwa, z.kolor,
                    w.nazwa AS wedka_nazwa
             FROM historia_losowan h
             JOIN przedmioty p ON p.id = h.wynik_id
             JOIN rzadkosci z  ON z.kod = p.rzadkosc_kod
             LEFT JOIN wedki w ON w.id = h.wedka_id
             WHERE h.user_id = ?
             ORDER BY h.id DESC
             LIMIT ?'
        );

        // LIMIT bindujemy jako integer — przy emulowanych prepare'ach SQLite
        // potrafi potraktować parametr tekstowy jako 0 i nie zwrócić nic.
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        $out = [];

        foreach ($stmt->fetchAll() as $row) {
            $sumaWag = (float) $row['suma_wag'];
            $waga = (float) $row['waga_wylosowana'];
            $typ = TypLosowania::zBazy((string) $row['typ']) ?? TypLosowania::Wedka;

            $out[] = [
                'id' => (int) $row['id'],
                'czas' => (string) $row['czas'],
                'typ' => $typ->value,
                'typ_nazwa' => $typ->nazwa(),
                'wedka' => $row['wedka_nazwa'] === null ? null : (string) $row['wedka_nazwa'],
                'przedmiot_id' => (int) $row['przedmiot_id'],
                'nazwa' => (string) $row['nazwa'],
                'rzadkosc' => (string) $row['rzadkosc_nazwa'],
                'kolor' => (string) $row['kolor'],
                'ikona' => Config::iconUrl((string) $row['ikona']),
                'koszt' => (float) $row['koszt'],
                'wygrana_wartosc' => (float) $row['wygrana_wartosc'],
                'bilans' => round((float) $row['wygrana_wartosc'] - (float) $row['koszt'], 2),
                'szansa_procent' => WeightedRoller::chancePercent($waga, $sumaWag),
            ];
        }

        return $out;
    }

    /**
     * Liczba losowań z podziałem na poziomy rzadkości.
     * Przydatne przy testach rozkładu: porównujemy z wagami z README.md.
     *
     * @return array<string, int>
     */
    public static function rozbicieRzadkosci(int $userId, ?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare(
            'SELECT p.rzadkosc_kod AS kod, COUNT(*) AS liczba
             FROM historia_losowan h
             JOIN przedmioty p ON p.id = h.wynik_id
             WHERE h.user_id = ?
             GROUP BY p.rzadkosc_kod'
        );
        $stmt->execute([$userId]);

        $out = [];

        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['kod']] = (int) $row['liczba'];
        }

        return $out;
    }

    private function polaczenie(): PDO
    {
        return $this->db ??= Database::connection();
    }
}
