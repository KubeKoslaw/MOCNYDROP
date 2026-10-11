<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;

/**
 * backend/Przedmiot.php
 *
 * Klasa Przedmiot z diagramu klas:
 *
 *     - id : int
 *     - nazwa : string
 *     - wartosc : float
 *     - rzadkosc : Rzadkosc
 *     + obliczWartosc() : float
 *     + opis() : string
 *
 * Asocjacje z diagramu:
 *   Przedmiot — Rzadkosc (*:1)      -> przedmioty.rzadkosc_kod
 *   Losowanie —wynik→ Przedmiot     -> historia_losowan.wynik_id
 *   Ekwipunek — Przedmiot (*:*)     -> ekwipunek.przedmiot_id
 *
 * W grze przedmiotami są ryby — tabela nazywa się `przedmioty`, a nazwy ryb
 * zostają w danych (database/seed/przedmioty.csv).
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class Przedmiot
{
    public function __construct(
        public readonly int $id,
        public readonly string $nazwa,
        public readonly float $wartosc,
        public readonly Rzadkosc $rzadkosc,
        public readonly string $ikona = '',
        private readonly string $opisTekst = '',
        public readonly bool $czyAktywny = true,
    ) {
    }

    /**
     * Metoda z diagramu: wartość przedmiotu po uwzględnieniu mnożnika rzadkości.
     *
     * To TA liczba trafia do ekwipunku w chwili połowu (zamrożenie wartości),
     * więc późniejsza zmiana balansu nie przecenia tego, co gracz już ma.
     */
    public function obliczWartosc(): float
    {
        return round($this->wartosc * $this->rzadkosc->mnoznik, 2);
    }

    /** Metoda z diagramu: opis słowny przedmiotu (kolumna `przedmioty.opis`). */
    public function opis(): string
    {
        return $this->opisTekst;
    }

    /** Ścieżka URL ikony — frontend nie składa jej sam. */
    public function ikonaUrl(): string
    {
        return Config::iconUrl($this->ikona);
    }

    public static function znajdz(int $id, ?PDO $pdo = null): ?self
    {
        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare(
            'SELECT p.id, p.nazwa, p.wartosc, p.ikona, p.opis, p.czy_aktywny,
                    z.kod, z.nazwa AS rzadkosc_nazwa, z.mnoznik, z.waga_domyslna, z.kolor, z.kolejnosc
             FROM przedmioty p
             JOIN rzadkosci z ON z.kod = p.rzadkosc_kod
             WHERE p.id = ?'
        );
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return $row === false ? null : self::zWiersza($row);
    }

    /** Jak znajdz(), ale rzuca 404 zamiast zwracać null. */
    public static function wymagaj(int $id, ?PDO $pdo = null): self
    {
        $przedmiot = self::znajdz($id, $pdo);

        if ($przedmiot === null) {
            throw ApiException::notFound('Nie ma takiego przedmiotu.', 'item_not_found');
        }

        return $przedmiot;
    }

    /**
     * Buduje przedmiot z wiersza puli wędki.
     *
     * WeightedRoller i Reel działają na tablicach (pula bywa duża i jest
     * tylko przepakowywana do JSON-a), więc encja powstaje dopiero tu —
     * dla wyniku losowania, który trafia do ekwipunku i do historii.
     *
     * @param array<string, mixed> $row
     */
    public static function zPuli(array $row): self
    {
        $rzadkosc = new Rzadkosc(
            (string) ($row['rzadkosc_kod'] ?? ''),
            (string) ($row['rzadkosc_nazwa'] ?? ''),
            (float) ($row['mnoznik'] ?? 1.0),
            (float) ($row['waga_domyslna'] ?? ($row['waga'] ?? 0.0)),
            (string) ($row['kolor'] ?? ''),
            (int) ($row['rzadkosc_kolejnosc'] ?? 0),
        );

        return new self(
            (int) ($row['przedmiot_id'] ?? 0),
            (string) ($row['nazwa'] ?? ''),
            (float) ($row['wartosc'] ?? 0.0),
            $rzadkosc,
            (string) ($row['ikona'] ?? ''),
            (string) ($row['opis'] ?? ''),
        );
    }

    /** @param array<string, mixed> $row wiersz z JOIN-a przedmioty + rzadkosci */
    public static function zWiersza(array $row): self
    {
        $rzadkosc = new Rzadkosc(
            (string) $row['kod'],
            (string) $row['rzadkosc_nazwa'],
            (float) $row['mnoznik'],
            (float) $row['waga_domyslna'],
            (string) $row['kolor'],
            (int) $row['kolejnosc'],
        );

        return new self(
            (int) $row['id'],
            (string) $row['nazwa'],
            (float) $row['wartosc'],
            $rzadkosc,
            (string) $row['ikona'],
            (string) $row['opis'],
            (int) $row['czy_aktywny'] === 1,
        );
    }

    /** Kształt zwracany przez API. @return array<string, mixed> */
    public function doApi(): array
    {
        return [
            'przedmiot_id' => $this->id,
            'nazwa' => $this->nazwa,
            'rzadkosc' => $this->rzadkosc->nazwa,
            'rzadkosc_kod' => $this->rzadkosc->kod,
            'kolor' => $this->rzadkosc->kolor,
            'wartosc' => $this->obliczWartosc(),
            'ikona' => $this->ikonaUrl(),
            'opis' => $this->opis(),
        ];
    }
}
