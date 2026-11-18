<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;
use Throwable;

final class Skrzynka implements ILosujacy
{

    private ?array $pulaCache = null;

    public ?array $pulaDoZapisu = null;

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

    public function czyAktywna(): bool
    {
        return $this->aktywna;
    }

    public function losuj(): Przedmiot
    {
        return $this->losujWPuli()[0]->losuj();
    }

    public function otworz(Uzytkownik $u): Przedmiot
    {

        $przedmiot = $this->otworzSzczegolowo($u)['przedmiot'];

        return $przedmiot;
    }

    public static function aktywne(?PDO $pdo = null): array
    {
        return self::lista('SELECT * FROM skrzynki WHERE aktywna = 1 ORDER BY kolejnosc ASC, cena ASC, id ASC', $pdo);
    }

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

    public static function wymagaj(int $id, ?PDO $pdo = null): self
    {
        $skrzynka = self::znajdz($id, $pdo);

        if ($skrzynka === null) {
            throw ApiException::notFound('Nie ma takiej skrzynki.', 'case_not_found');
        }

        return $skrzynka;
    }

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

    private static function lista(string $sql, ?PDO $pdo): array
    {
        $db = $pdo ?? Database::connection();

        $out = [];

        foreach ($db->query($sql)->fetchAll() as $row) {
            $out[] = self::zWiersza($row, $db);
        }

        return $out;
    }

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

        $chances = WeightedRoller::chances($items);

        foreach ($items as $i => $item) {
            $items[$i]['szansa_procent'] = $chances[$i];
        }

        if ($pdo === null) {
            $this->pulaCache = $items;
        }

        return $items;
    }

    private function polaczenie(): PDO
    {
        return $this->db ??= Database::connection();
    }
}
