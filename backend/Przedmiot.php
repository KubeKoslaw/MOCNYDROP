<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;

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

    public function obliczWartosc(): float
    {
        return round($this->wartosc * $this->rzadkosc->mnoznik, 2);
    }

    public function opis(): string
    {
        return $this->opisTekst;
    }

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

    public static function wymagaj(int $id, ?PDO $pdo = null): self
    {
        $przedmiot = self::znajdz($id, $pdo);

        if ($przedmiot === null) {
            throw ApiException::notFound('Nie ma takiego przedmiotu.', 'item_not_found');
        }

        return $przedmiot;
    }

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

    public static function katalog(?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        $rows = $db->query(
            'SELECT p.id AS przedmiot_id, p.nazwa, p.wartosc, p.ikona, p.opis,
                    z.kod           AS rzadkosc_kod,
                    z.nazwa         AS rzadkosc_nazwa,
                    z.mnoznik       AS mnoznik,
                    z.waga_domyslna AS waga_domyslna,
                    z.kolor         AS kolor,
                    z.kolejnosc     AS rzadkosc_kolejnosc,
                    z.waga_domyslna AS waga
             FROM przedmioty p
             JOIN rzadkosci z ON z.kod = p.rzadkosc_kod
             WHERE p.czy_aktywny = 1
               AND NOT EXISTS (SELECT 1 FROM wedki w WHERE w.przedmiot_id = p.id)
             ORDER BY z.kolejnosc ASC, p.wartosc ASC, p.id ASC'
        )->fetchAll();

        return array_map(static fn (array $row): array => self::wierszPuli($row), $rows);
    }

    public static function wierszPuli(array $row): array
    {
        $ikona = (string) ($row['ikona'] ?? '');

        return [
            'przedmiot_id' => (int) ($row['przedmiot_id'] ?? $row['id'] ?? 0),
            'nazwa' => (string) ($row['nazwa'] ?? ''),
            'rzadkosc_kod' => (string) ($row['rzadkosc_kod'] ?? ''),
            'rzadkosc_nazwa' => (string) ($row['rzadkosc_nazwa'] ?? ''),
            'rzadkosc_kolejnosc' => (int) ($row['rzadkosc_kolejnosc'] ?? 0),
            'mnoznik' => (float) ($row['mnoznik'] ?? 1.0),
            'waga_domyslna' => (float) ($row['waga_domyslna'] ?? 0.0),
            'kolor' => (string) ($row['kolor'] ?? ''),
            'wartosc' => (float) ($row['wartosc'] ?? 0.0),
            'ikona' => $ikona,
            'ikona_url' => Config::iconUrl($ikona),
            'ikona_istnieje' => Config::iconExists($ikona),
            'opis' => (string) ($row['opis'] ?? ''),
            'waga' => (float) ($row['waga'] ?? $row['waga_domyslna'] ?? 0.0),
            'szansa_procent' => 0.0,
        ];
    }

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
