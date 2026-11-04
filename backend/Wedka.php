<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;

final class Wedka
{

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
        public readonly int $limitZarzutow = 10,
        public readonly ?int $przedmiotId = null,
    ) {
    }

    public function zarzuc(): bool
    {
        return $this->czyAktywna && $this->pula() !== [];
    }

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

    public static function wymagaj(int $id, ?PDO $pdo = null): self
    {
        $wedka = self::znajdz($id, $pdo);

        if ($wedka === null) {
            throw ApiException::notFound('Nie ma takiej wędki.', 'rod_not_found');
        }

        return $wedka;
    }

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

        $chances = WeightedRoller::chances($items);

        foreach ($items as $i => $item) {
            $items[$i]['szansa_procent'] = $chances[$i];
        }

        return $this->pulaCache = $items;
    }

    public function wartoscZarzutu(?PDO $pdo = null): float
    {
        $ev = 0.0;

        foreach ($this->pula($pdo) as $item) {
            $ev += ((float) $item['szansa_procent'] / 100.0) * (float) $item['wartosc'] * (float) $item['mnoznik'];
        }

        return $ev;
    }

    public static function startowa(?PDO $pdo = null): ?self
    {
        foreach (self::aktywne($pdo) as $wedka) {
            if ($wedka->przedmiotId !== null) {
                return $wedka;
            }
        }

        return null;
    }

    public static function dlaPrzedmiotu(int $przedmiotId, ?PDO $pdo = null): ?self
    {
        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare('SELECT * FROM wedki WHERE przedmiot_id = ?');
        $stmt->execute([$przedmiotId]);

        $row = $stmt->fetch();

        return $row === false ? null : self::zWiersza($row, $db);
    }

    public function utworzPrzedmiot(string $rzadkoscKod, ?PDO $pdo = null): int
    {
        $db = $pdo ?? $this->polaczenie();

        $stmt = $db->prepare(
            'INSERT INTO przedmioty (nazwa, rzadkosc_kod, wartosc, ikona, opis, czy_aktywny)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $this->nazwa,
            $rzadkoscKod,
            round($this->wartoscZarzutu($db) * $this->limitZarzutow, 2),
            $this->ikona,
            $this->opis,
        ]);

        $przedmiotId = (int) $db->lastInsertId();

        $db->prepare('UPDATE wedki SET przedmiot_id = ? WHERE id = ?')->execute([$przedmiotId, $this->id]);

        return $przedmiotId;
    }

    public function zarzutyGracza(int $userId, ?PDO $pdo = null): int
    {
        if ($this->przedmiotId === null) {
            return 0;
        }

        $stmt = ($pdo ?? $this->polaczenie())->prepare(
            'SELECT COALESCE(SUM(zarzuty_pozostale), 0) FROM ekwipunek
             WHERE user_id = ? AND przedmiot_id = ? AND sprzedany = 0'
        );
        $stmt->execute([$userId, $this->przedmiotId]);

        return (int) $stmt->fetchColumn();
    }

    public function doApi(): array
    {
        $pula = $this->pula();

        return [
            'id' => $this->id,
            'nazwa' => $this->nazwa,
            'opis' => $this->opis,
            'koszt_uzycia' => $this->kosztUzycia,
            'limit_zarzutow' => $this->limitZarzutow,
            'przedmiot_id' => $this->przedmiotId,
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
            (int) ($row['limit_zarzutow'] ?? 10),
            isset($row['przedmiot_id']) ? (int) $row['przedmiot_id'] : null,
        );
    }

    private function polaczenie(): PDO
    {
        return $this->db ??= Database::connection();
    }
}
