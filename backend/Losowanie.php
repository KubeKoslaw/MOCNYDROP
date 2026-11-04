<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;

final class Losowanie
{

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

        public readonly int $skrzynkaId = 0,
        public readonly bool $udane = true,
    ) {
        $this->id = $id;
    }

    public function zapisz(): void
    {
        if ($this->wynik === null) {
            throw ApiException::badRequest('Losowanie bez wyniku nie może zostać zapisane.', 'no_result');
        }

        $db = $this->polaczenie();

        $stmt = $db->prepare(
            'INSERT INTO historia_losowan
                (user_id, typ, wynik_id, wedka_id, skrzynka_id, udane,
                 koszt, wygrana_wartosc, waga_wylosowana, suma_wag, czas)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $this->uzytkownikId,
            $this->typ->value,
            $this->wynik->id,
            $this->wedkaId > 0 ? $this->wedkaId : null,
            $this->skrzynkaId > 0 ? $this->skrzynkaId : null,
            $this->udane ? 1 : 0,
            $this->koszt,
            $this->wygranaWartosc,
            $this->wagaWylosowana,
            $this->sumaWag,
            $this->czas !== '' ? $this->czas : date('Y-m-d H:i:s'),
        ]);

        $this->id = (int) $db->lastInsertId();
    }

    public function wyliczRTP(): float
    {
        return self::rtpDlaTypu($this->typ, $this->polaczenie());
    }

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

        if ($koszty <= 0.0) {
            return 0.0;
        }

        return round((float) ($row['wygrane'] ?? 0.0) / $koszty, 6);
    }

    public static function historia(int $userId, int $limit = 20, ?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        $limit = max(1, min(100, $limit));

        $stmt = $db->prepare(
            'SELECT h.id, h.typ, h.koszt, h.wygrana_wartosc, h.waga_wylosowana, h.suma_wag, h.czas, h.udane,
                    p.id AS przedmiot_id, p.nazwa, p.ikona,
                    z.nazwa AS rzadkosc_nazwa, z.kolor,
                    w.nazwa AS wedka_nazwa,
                    s.nazwa AS skrzynka_nazwa
             FROM historia_losowan h
             JOIN przedmioty p ON p.id = h.wynik_id
             JOIN rzadkosci z  ON z.kod = p.rzadkosc_kod
             LEFT JOIN wedki w ON w.id = h.wedka_id
             LEFT JOIN skrzynki s ON s.id = h.skrzynka_id
             WHERE h.user_id = ?
             ORDER BY h.id DESC
             LIMIT ?'
        );

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
                'udane' => (int) $row['udane'] === 1,
                'zrodlo' => self::nazwaZrodla($row, $typ),
                'wedka' => $row['wedka_nazwa'] === null ? null : (string) $row['wedka_nazwa'],
                'skrzynka' => $row['skrzynka_nazwa'] === null ? null : (string) $row['skrzynka_nazwa'],
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

    private static function nazwaZrodla(array $row, TypLosowania $typ): string
    {
        $nazwa = match ($typ) {
            TypLosowania::Wedka => $row['wedka_nazwa'],
            TypLosowania::Skrzynka => $row['skrzynka_nazwa'],
        };

        return $nazwa === null ? $typ->nazwa() : (string) $nazwa;
    }


}
