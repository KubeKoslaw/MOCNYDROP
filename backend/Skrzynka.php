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

    private function losujWPuli(?callable $random = null): array
    {
        $this->wymagajOtwieralna();

        $winner = WeightedRoller::roll($this->pula(), $random);

        $wpis = new SkrzynkaPrzedmiot(
            0,
            $this->id,
            (int) $winner['przedmiot_id'],
            (float) $winner['waga'],
            $this->polaczenie()
        );

        return [$wpis, $winner];
    }

    public function otworzSzczegolowo(
        Uzytkownik $u,
        bool $sprzedajNatychmiast = false,
        ?callable $random = null,
        ?PDO $pdo = null,
        bool $bezOplaty = false
    ): array {
        $db = $pdo ?? $this->polaczenie();

        $this->wymagajOtwieralna();

        $cena = $bezOplaty ? 0.0 : $this->cena;

        $db->beginTransaction();

        try {

            $portfel = Portfel::dlaUzytkownika($u->id, $db);
            $saldoPrzed = $portfel->saldo;

            if ($cena > 0.0 && !$portfel->pobierzOplate($cena)) {

                throw ApiException::insufficientFunds($cena, $saldoPrzed);
            }

            $saldoPoOplacie = $portfel->saldo;

            [$wpis, $winner] = $this->losujWPuli($random);

            $wynik = $wpis->losuj();

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

        if ($sprzedajNatychmiast) {
            $szczegoly['sprzedaz'] = (new Ekwipunek($u->id, $db))->sprzedajWpis($ekwipunekId);
            $szczegoly['saldo_po'] = (float) $szczegoly['sprzedaz']['nowe_saldo'];
        }

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

    public function odpowiedzOtwarcia(array $wynik): array
    {

        $przedmiot = $wynik['przedmiot'];

        return [
            'otwarcie' => [
                'id' => $wynik['historia_id'],
                'typ' => TypLosowania::Skrzynka->value,
                'cena' => $wynik['cena'],
            ],
            'skrzynka' => [
                'id' => $this->id,
                'nazwa' => $this->nazwa,
                'cena' => $wynik['cena'],
            ],
            'przedmiot' => [
                'ekwipunek_id' => $wynik['ekwipunek_id'],
                'przedmiot_id' => $przedmiot->id,
                'nazwa' => $przedmiot->nazwa,
                'rzadkosc' => $przedmiot->rzadkosc->nazwa,
                'rzadkosc_kod' => $przedmiot->rzadkosc->kod,
                'kolor' => $przedmiot->rzadkosc->kolor,
                'wartosc' => $wynik['wartosc'],
                'ikona' => $przedmiot->ikonaUrl(),
                'szansa_procent' => $wynik['szansa_procent'],
            ],
            'saldo_przed' => $wynik['saldo_przed'],
            'saldo_po' => $wynik['saldo_po'],
            'sprzedaz' => $wynik['sprzedaz'],
            'reel' => $wynik['reel'],
            'reel_dlugosc' => $wynik['reel_dlugosc'],
            'reel_indeks_wygranej' => $wynik['reel_indeks_wygranej'],
            'message' => $wynik['sprzedaz'] === null
                ? sprintf('Zdobyto: %s (%s)!', $przedmiot->nazwa, $przedmiot->rzadkosc->nazwa)
                : sprintf(
                    'Zdobyto %s (%s) i sprzedano od razu za %s monet.',
                    $przedmiot->nazwa,
                    $przedmiot->rzadkosc->nazwa,
                    ApiException::money((float) $wynik['sprzedaz']['otrzymane_monety'])
                ),
        ];
    }

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
