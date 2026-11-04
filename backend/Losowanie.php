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

    public static function statystyki(?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        $ogolne = $db->query(
            'SELECT COUNT(*) AS liczba,
                    COUNT(DISTINCT user_id) AS graczy,
                    COALESCE(SUM(koszt), 0) AS koszt,
                    COALESCE(SUM(wygrana_wartosc), 0) AS wygrana,
                    COALESCE(SUM(CASE WHEN udane = 0 THEN 1 ELSE 0 END), 0) AS porazki
             FROM historia_losowan'
        )->fetch();

        $sumaKoszt = (float) ($ogolne['koszt'] ?? 0.0);
        $sumaWygrana = (float) ($ogolne['wygrana'] ?? 0.0);

        $wedlugTypu = [];

        foreach (TypLosowania::cases() as $typ) {
            $stmt = $db->prepare(
                'SELECT COUNT(*) AS liczba,
                        COALESCE(SUM(koszt), 0) AS koszt,
                        COALESCE(SUM(wygrana_wartosc), 0) AS wygrana,
                        COALESCE(SUM(CASE WHEN udane = 0 THEN 1 ELSE 0 END), 0) AS porazki
                 FROM historia_losowan WHERE typ = ?'
            );
            $stmt->execute([$typ->value]);

            $row = $stmt->fetch();
            $koszt = (float) ($row['koszt'] ?? 0.0);
            $wygrana = (float) ($row['wygrana'] ?? 0.0);

            $wedlugTypu[] = [
                'typ' => $typ->value,
                'typ_nazwa' => $typ->nazwa(),
                'liczba_losowan' => (int) ($row['liczba'] ?? 0),
                'liczba_porazek' => (int) ($row['porazki'] ?? 0),
                'suma_kosztow' => round($koszt, 2),
                'suma_wyplat' => round($wygrana, 2),
                'bilans' => round($wygrana - $koszt, 2),
                'rtp' => $koszt > 0.0 ? round($wygrana / $koszt, 4) : 0.0,
            ];
        }

        $rzadkosci = [];

        foreach ($db->query(
            'SELECT z.kod, z.nazwa, z.kolor, z.kolejnosc, COUNT(h.id) AS liczba
             FROM historia_losowan h
             JOIN przedmioty p ON p.id = h.wynik_id
             JOIN rzadkosci z  ON z.kod = p.rzadkosc_kod
             WHERE h.udane = 1
             GROUP BY z.kod, z.nazwa, z.kolor, z.kolejnosc
             ORDER BY z.kolejnosc ASC'
        )->fetchAll() as $row) {
            $rzadkosci[] = [
                'kod' => (string) $row['kod'],
                'nazwa' => (string) $row['nazwa'],
                'kolor' => (string) $row['kolor'],
                'liczba' => (int) $row['liczba'],
            ];
        }

        $najwieksze = [];

        foreach ($db->query(
            'SELECT h.czas, h.typ, h.wygrana_wartosc, u.login, p.nazwa, p.ikona, z.nazwa AS rzadkosc, z.kolor
             FROM historia_losowan h
             JOIN uzytkownicy u ON u.id = h.user_id
             JOIN przedmioty p  ON p.id = h.wynik_id
             JOIN rzadkosci z   ON z.kod = p.rzadkosc_kod
             WHERE h.udane = 1
             ORDER BY h.wygrana_wartosc DESC, h.id DESC
             LIMIT 10'
        )->fetchAll() as $row) {
            $najwieksze[] = [
                'czas' => (string) $row['czas'],
                'typ' => (string) $row['typ'],
                'login' => (string) $row['login'],
                'nazwa' => (string) $row['nazwa'],
                'rzadkosc' => (string) $row['rzadkosc'],
                'kolor' => (string) $row['kolor'],
                'ikona' => Config::iconUrl((string) $row['ikona']),
                'wygrana_wartosc' => (float) $row['wygrana_wartosc'],
            ];
        }

        return [
            'lacznie' => [
                'liczba_losowan' => (int) ($ogolne['liczba'] ?? 0),
                'liczba_graczy' => (int) ($ogolne['graczy'] ?? 0),
                'liczba_porazek' => (int) ($ogolne['porazki'] ?? 0),
                'suma_kosztow' => round($sumaKoszt, 2),
                'suma_wyplat' => round($sumaWygrana, 2),
                'bilans_platformy' => round($sumaKoszt - $sumaWygrana, 2),
                'rtp' => $sumaKoszt > 0.0 ? round($sumaWygrana / $sumaKoszt, 4) : 0.0,
            ],
            'wedlug_typu' => $wedlugTypu,
            'rozbicie_rzadkosci' => $rzadkosci,
            'najwieksze_wygrane' => $najwieksze,
        ];
    }

    public static function podejrzaneSerie(?PDO $pdo = null, int $okno = 500): array
    {
        $db = $pdo ?? Database::connection();

        $dlugoscSerii = max(2, Settings::getInt(Settings::KEY_ALERT_STREAK, 3, $db));
        $minKolejnosc = max(1, Settings::getInt(Settings::KEY_ALERT_RARITY_ORDER, 4, $db));
        $progRtp = Settings::getFloat(Settings::KEY_ALERT_RTP, 3.0, $db);
        $minLosowan = max(1, Settings::getInt(Settings::KEY_ALERT_MIN_DRAWS, 5, $db));

        $progi = [
            'dlugosc_serii' => $dlugoscSerii,
            'min_rzadkosc_kolejnosc' => $minKolejnosc,
            'rtp_prog' => $progRtp,
            'min_losowan' => $minLosowan,
            'okno_zdarzen' => max(1, $okno),
        ];

        $stmt = $db->prepare(
            'SELECT h.id, h.user_id, h.czas, u.login, p.nazwa AS przedmiot, z.kolejnosc
             FROM historia_losowan h
             JOIN uzytkownicy u ON u.id = h.user_id
             JOIN przedmioty p  ON p.id = h.wynik_id
             JOIN rzadkosci z   ON z.kod = p.rzadkosc_kod
             WHERE h.udane = 1 AND h.id > (SELECT COALESCE(MAX(id), 0) - ? FROM historia_losowan)
             ORDER BY h.user_id ASC, h.id ASC'
        );
        $stmt->bindValue(1, max(1, $okno), PDO::PARAM_INT);
        $stmt->execute();

        $stan = [];

        foreach ($stmt->fetchAll() as $row) {
            $userId = (int) $row['user_id'];

            if (!isset($stan[$userId])) {
                $stan[$userId] = [
                    'login' => (string) $row['login'],
                    'seria' => 0,
                    'maxSeria' => 0,
                    'trafienia' => [],
                    'od' => (string) $row['czas'],
                    'do' => (string) $row['czas'],
                ];
            }

            if ((int) $row['kolejnosc'] >= $minKolejnosc) {
                $stan[$userId]['seria'] = (int) $stan[$userId]['seria'] + 1;
                $stan[$userId]['trafienia'][] = (string) $row['przedmiot'];
                $stan[$userId]['do'] = (string) $row['czas'];

                if ($stan[$userId]['seria'] > $stan[$userId]['maxSeria']) {
                    $stan[$userId]['maxSeria'] = (int) $stan[$userId]['seria'];
                }
            } else {

                $stan[$userId]['seria'] = 0;
                $stan[$userId]['trafienia'] = [];
            }
        }

        $alerty = [];

        foreach ($stan as $userId => $dane) {
            $maxSeria = (int) $dane['maxSeria'];

            if ($maxSeria < $dlugoscSerii) {
                continue;
            }

            $alerty[] = [
                'typ_alertu' => 'seria_wygranych',
                'user_id' => $userId,
                'login' => (string) $dane['login'],
                'wartosc' => $maxSeria,
                'czas_od' => (string) $dane['od'],
                'czas_do' => (string) $dane['do'],
                'przedmioty' => array_slice($dane['trafienia'], -$maxSeria),
                'szczegoly' => sprintf(
                    'Seria %d trafień rzadkich przedmiotów z rzędu (próg: %d).',
                    $maxSeria,
                    $dlugoscSerii
                ),
            ];
        }

        foreach (self::alertyRtp($db, $progRtp, $minLosowan) as $alert) {
            $alerty[] = $alert;
        }

        usort($alerty, static function (array $a, array $b): int {
            $priorytet = static fn (array $x): int => $x['typ_alertu'] === 'seria_wygranych' ? 0 : 1;

            return [$priorytet($a), -$a['wartosc']] <=> [$priorytet($b), -$b['wartosc']];
        });

        return ['alerty' => $alerty, 'progi' => $progi];
    }

    private static function alertyRtp(PDO $db, float $progRtp, int $minLosowan): array
    {
        $stmt = $db->prepare(
            'SELECT h.user_id, u.login, COUNT(*) AS liczba,
                    COALESCE(SUM(h.koszt), 0) AS koszt,
                    COALESCE(SUM(h.wygrana_wartosc), 0) AS wygrana
             FROM historia_losowan h
             JOIN uzytkownicy u ON u.id = h.user_id
             GROUP BY h.user_id, u.login
             HAVING liczba >= ?'
        );
        $stmt->bindValue(1, $minLosowan, PDO::PARAM_INT);
        $stmt->execute();

        $alerty = [];

        foreach ($stmt->fetchAll() as $row) {
            $koszt = (float) $row['koszt'];
            $wygrana = (float) $row['wygrana'];

            if ($koszt <= 0.0) {
                continue;
            }

            $rtp = $wygrana / $koszt;

            if ($rtp < $progRtp) {
                continue;
            }

            $alerty[] = [
                'typ_alertu' => 'wysokie_rtp',
                'user_id' => (int) $row['user_id'],
                'login' => (string) $row['login'],
                'wartosc' => round($rtp, 4),
                'liczba_losowan' => (int) $row['liczba'],
                'suma_kosztow' => round($koszt, 2),
                'suma_wyplat' => round($wygrana, 2),
                'szczegoly' => sprintf(
                    'RTP gracza %.2f przy %d losowaniach (próg: %.2f).',
                    $rtp,
                    (int) $row['liczba'],
                    $progRtp
                ),
            ];
        }

        return $alerty;
    }

    public static function rtpGracza(int $userId, ?PDO $pdo = null): float
    {
        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare(
            'SELECT COALESCE(SUM(wygrana_wartosc), 0) AS wygrana, COALESCE(SUM(koszt), 0) AS koszt
             FROM historia_losowan WHERE user_id = ?'
        );
        $stmt->execute([$userId]);

        $row = $stmt->fetch();
        $koszt = (float) ($row['koszt'] ?? 0.0);

        return $koszt > 0.0 ? round((float) ($row['wygrana'] ?? 0.0) / $koszt, 4) : 0.0;
    }

    private function polaczenie(): PDO
    {
        return $this->db ??= Database::connection();
    }
}
