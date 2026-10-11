<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\ApiException;
use MocnyDrop\Ekwipunek;
use MocnyDrop\Losowanie;
use MocnyDrop\Settings;
use MocnyDrop\TraderService;
use MocnyDrop\TypLosowania;

/**
 * tests/TraderServiceTest.php
 *
 * Przypadek użycia UC_TRADE «Wymiana przedmiotów (Trader)» z diagramu use case
 * (z przypadkiem rozszerzającym TRADE_ERR «Odrzucenie: za niska wartość»)
 * i „Wymiana w Traderze (POST /api/trade_up.php)" ze strony 2 diagramu
 * MOCNYDROP_PROJEKT.
 *
 * Sprawdzone kroki z diagramu przepływu:
 *   - wybór od 3 do 10 przedmiotów ze swojego ekwipunku,
 *   - zsumowanie łącznej wartości rynkowej wkładu,
 *   - weryfikacja minimalnego progu wartości kontraktu,
 *   - trwałe usunięcie (spalenie) wybranych przedmiotów,
 *   - losowanie 1 nagrody z wyższej puli rzadkości,
 *   - zapis nowego wygenerowanego przedmiotu do bazy.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class TraderServiceTest extends TestCase
{
    /**
     * Scenariusz: katalog z jednym przedmiotem na każdy poziom rzadkości
     * (wagi domyślne z README: 50 / 30 / 15 / 4 / 1 / 0.001) oraz gracz
     * z $liczbaWkladow wpisami poziomu $poziom w ekwipunku.
     *
     * @return array{userId: int, wpisy: list<int>, katalog: array<string, int>}
     */
    private function scenariusz(int $liczbaWkladow = 3, string $poziom = 'pospolita', float $wartoscWpisu = 5.0): array
    {
        $konto = Fixtures::user($this->db, 'handlarz', 500.0);

        $katalog = [
            'pospolita' => Fixtures::przedmiotKatalog($this->db, 'Glon', 'pospolita', 5.0, 'green_seaweed.png'),
            'przecietna' => Fixtures::przedmiotKatalog($this->db, 'Pstrąg', 'przecietna', 8.0, 'brown_trout.png'),
            'niezla' => Fixtures::przedmiotKatalog($this->db, 'Bass', 'niezla', 25.0, 'smallmouth_bass.png'),
            'mityczna' => Fixtures::przedmiotKatalog($this->db, 'Marlin', 'mityczna', 120.0, 'blue_marlin.png'),
            'niesamowita' => Fixtures::przedmiotKatalog($this->db, 'Jesiotr', 'niesamowita', 400.0, 'sturgeon.png'),
            'ultra' => Fixtures::przedmiotKatalog($this->db, 'Otchłań', 'ultra', 25000.0, 'void_fish.png'),
        ];

        $wpisy = [];

        for ($i = 0; $i < $liczbaWkladow; $i++) {
            $wpisy[] = Fixtures::wpisEkwipunku($this->db, $konto['id'], $katalog[$poziom], $wartoscWpisu);
        }

        return ['userId' => $konto['id'], 'wpisy' => $wpisy, 'katalog' => $katalog];
    }

    public function testKontraktZwracaJednaNagrodeZRzadszejPuli(): void
    {
        $s = $this->scenariusz(3, 'pospolita', 5.0);

        // Pula nagród (poziomy > Pospolita): 30 + 15 + 4 + 1 + 0.001 = 50.001.
        // pick = 0.1 * 50.001 = 5.0001 -> pierwszy przedmiot puli (Przeciętna).
        $wynik = TraderService::trade($s['userId'], $s['wpisy'], Fixtures::scriptedRandom([0.1]), $this->db);

        $this->assertSame('Pstrąg', $wynik['nagroda']['nazwa'], 'Nagroda z puli rzadszej niż wkład');
        $this->assertSame('przecietna', $wynik['nagroda']['rzadkosc_kod'], 'Poziom rzadkości nagrody');
        $this->assertFloat(15.0, (float) $wynik['kontrakt']['wartosc_wkladu'], 'Łączna wartość wkładu = 3 × 5');
        $this->assertSame(3, $wynik['kontrakt']['liczba_przedmiotow'], 'Liczba spalonych przedmiotów');
        $this->assertTrue($wynik['nagroda']['ekwipunek_id'] > 0, 'Nagroda dostała wpis w ekwipunku');

        $ekwipunek = (new Ekwipunek($s['userId'], $this->db))->lista(true);

        $this->assertCount(1, $ekwipunek, 'Wkład spalony, w ekwipunku została tylko nagroda');
        $this->assertSame('Pstrąg', $ekwipunek[0]['nazwa'], 'W ekwipunku jest wylosowana nagroda');
        $this->assertFloat(8.0, (float) $ekwipunek[0]['wartosc'], 'Wartość nagrody zamrożona w chwili wylosowania');
    }

    /** «Wybór od 3 do 10 przedmiotów ze swojego ekwipunku». */
    public function testZaMaloIZaDuzoPrzedmiotowJestOdrzucane(): void
    {
        $s = $this->scenariusz(11, 'pospolita', 5.0);

        $zaMalo = array_slice($s['wpisy'], 0, 2);

        $this->assertThrows(
            ApiException::class,
            fn () => TraderService::trade($s['userId'], $zaMalo, Fixtures::scriptedRandom([0.1]), $this->db),
            'Kontrakt z dwóch przedmiotów musi być odrzucony',
            'too_few_items'
        );

        $this->assertThrows(
            ApiException::class,
            fn () => TraderService::trade($s['userId'], $s['wpisy'], Fixtures::scriptedRandom([0.1]), $this->db),
            'Kontrakt z jedenastu przedmiotów musi być odrzucony',
            'too_many_items'
        );

        $this->assertCount(11, (new Ekwipunek($s['userId'], $this->db))->lista(), 'Odrzucone kontrakty nie palą wkładu');

        // Dokładnie 10 przedmiotów to górna granica z diagramu — musi przejść.
        $wynik = TraderService::trade($s['userId'], array_slice($s['wpisy'], 0, 10), Fixtures::scriptedRandom([0.1]), $this->db);

        $this->assertSame(10, $wynik['kontrakt']['liczba_przedmiotow'], 'Dziesięć przedmiotów mieści się w limicie');
    }

    /** TRADE_ERR «Odrzucenie: za niska wartość» — próg z `trader_min_wartosc`. */
    public function testZaNiskaWartoscWkladuJestOdrzucana(): void
    {
        $s = $this->scenariusz(3, 'pospolita', 1.0);

        $this->assertThrows(
            ApiException::class,
            fn () => TraderService::trade($s['userId'], $s['wpisy'], Fixtures::scriptedRandom([0.1]), $this->db),
            'Wkład 3 × 1 = 3 jest poniżej progu 10 (TRADE_ERR)',
            'trade_below_minimum'
        );

        $this->assertCount(3, (new Ekwipunek($s['userId'], $this->db))->lista(), 'Odrzucony kontrakt nie może spalić przedmiotów');
        $this->assertSame(
            0,
            (int) $this->db->query('SELECT COUNT(*) FROM historia_losowan')->fetchColumn(),
            'Odrzucony kontrakt nie zapisuje losowania'
        );
    }

    public function testLimityKontraktuPochodzaZUstawien(): void
    {
        $limity = TraderService::limity($this->db);

        $this->assertSame(3, $limity['min_przedmiotow'], 'Domyślny minimalny rozmiar kontraktu');
        $this->assertSame(10, $limity['max_przedmiotow'], 'Domyślny maksymalny rozmiar kontraktu');
        $this->assertFloat(10.0, $limity['min_wartosc'], 'Domyślny próg wartości kontraktu');

        Fixtures::ustawienie($this->db, Settings::KEY_TRADER_MIN_ITEMS, '5');
        Fixtures::ustawienie($this->db, Settings::KEY_TRADER_MIN_VALUE, '100');

        $poZmianie = TraderService::limity($this->db);

        $this->assertSame(5, $poZmianie['min_przedmiotow'], 'Zmiana progu bez zmiany kodu (tabela ustawienia)');
        $this->assertFloat(100.0, $poZmianie['min_wartosc'], 'Zmiana minimalnej wartości kontraktu');
    }

    /** «Trwałe usunięcie (spalenie) wybranych przedmiotów». */
    public function testWkladJestTrwaleUsunietyZBazy(): void
    {
        $s = $this->scenariusz(3, 'pospolita', 5.0);

        $wynik = TraderService::trade($s['userId'], $s['wpisy'], Fixtures::scriptedRandom([0.1]), $this->db);

        $this->assertCount(3, $wynik['spalone'], 'Odpowiedź wymienia spalone przedmioty');
        $this->assertSame('Glon', $wynik['spalone'][0]['nazwa'], 'Spalony przedmiot w odpowiedzi');

        $this->assertSame(
            1,
            (int) $this->db->query('SELECT COUNT(*) FROM ekwipunek')->fetchColumn(),
            'W tabeli ekwipunek zostaje TYLKO nagroda — wkład jest trwale usunięty'
        );

        $ekwipunek = new Ekwipunek($s['userId'], $this->db);

        foreach ($s['wpisy'] as $wpis) {
            $this->assertThrows(
                ApiException::class,
                fn () => $ekwipunek->sprzedajWpis($wpis),
                'Spalonego przedmiotu nie da się już sprzedać',
                'item_not_found'
            );
        }
    }

    /** «Losowanie 1 nagrody z wyższej puli rzadkości». */
    public function testPulaNagrodZaczynaSiePowyzejNajrzadszegoWkladu(): void
    {
        $s = $this->scenariusz(2, 'pospolita', 5.0);

        // Trzeci wkład jest Mityczny (kolejnosc 4) — pula nagród musi zacząć się
        // dopiero od Niesamowitej (kolejnosc 5).
        $s['wpisy'][] = Fixtures::wpisEkwipunku($this->db, $s['userId'], $s['katalog']['mityczna'], 120.0);

        $kalkulacja = TraderService::kalkulacja($s['userId'], $s['wpisy'], $this->db);

        $this->assertSame('mityczna', $kalkulacja['poziom_wejsciowy'], 'Poziom wejściowy = najrzadszy wkład');
        $this->assertCount(2, $kalkulacja['pula'], 'Pula nagród: Niesamowita i ULTRA');
        $this->assertSame('Jesiotr', $kalkulacja['pula'][0]['nazwa'], 'Pierwszy przedmiot puli nagród');
        $this->assertSame('Otchłań', $kalkulacja['pula'][1]['nazwa'], 'Drugi przedmiot puli nagród');
        $this->assertFloat(130.0, (float) $kalkulacja['wartosc_wkladu'], 'Suma wkładu = 5 + 5 + 120');

        // Wagi domyślne: Niesamowita 1, ULTRA 0.001 -> suma 1.001.
        // pick = 0.5 * 1.001 = 0.5005 -> Jesiotr (waga 1).
        $wynik = TraderService::trade($s['userId'], $s['wpisy'], Fixtures::scriptedRandom([0.5]), $this->db);

        $this->assertSame('Jesiotr', $wynik['nagroda']['nazwa'], 'Nagroda z puli powyżej najrzadszego wkładu');
        $this->assertSame('niesamowita', $wynik['nagroda']['rzadkosc_kod'], 'Rzadkość nagrody wyższa niż wkładu');
    }

    public function testBrakWyzszejPuliJestOdrzucanyPrzedSpaleniemWkladu(): void
    {
        // Wkład z samych przedmiotów ULTRA — rzadszej puli już nie ma.
        $s = $this->scenariusz(3, 'ultra', 25000.0);

        $this->assertThrows(
            ApiException::class,
            fn () => TraderService::trade($s['userId'], $s['wpisy'], Fixtures::scriptedRandom([0.1]), $this->db),
            'Kontrakt bez wyższej puli musi być odrzucony',
            'no_higher_pool'
        );

        $this->assertCount(3, (new Ekwipunek($s['userId'], $this->db))->lista(), 'Odrzucenie następuje PRZED spaleniem wkładu');
    }

    public function testKalkulacjaZwracaPuleZSzanamiINieZmieniaStanu(): void
    {
        $s = $this->scenariusz(3, 'pospolita', 5.0);

        $kalkulacja = TraderService::kalkulacja($s['userId'], $s['wpisy'], $this->db);

        $this->assertCount(5, $kalkulacja['pula'], 'Pula nagród = wszystkie przedmioty rzadsze od Pospolitej');
        $this->assertArrayHasKey('szansa_procent', $kalkulacja['pula'][0], 'Pula pokazuje szanse procentowe');
        $this->assertBetween(59.0, 61.0, (float) $kalkulacja['pula'][0]['szansa_procent'], 'Przeciętna: 30 / 50.001 ≈ 60%');
        $this->assertSame(3, $kalkulacja['limity']['min_przedmiotow'], 'Kalkulacja zwraca limity kontraktu');

        $suma = 0.0;

        foreach ($kalkulacja['pula'] as $item) {
            $suma += (float) $item['szansa_procent'];
        }

        $this->assertBetween(99.9, 100.1, $suma, 'Szanse puli nagród sumują się do 100%');

        $this->assertCount(3, (new Ekwipunek($s['userId'], $this->db))->lista(), 'Kalkulacja (GET) nie pali wkładu');
        $this->assertSame(
            0,
            (int) $this->db->query('SELECT COUNT(*) FROM historia_losowan')->fetchColumn(),
            'Kalkulacja (GET) nie zapisuje losowania'
        );
    }

    public function testZduplikowanyICudzyWpisSaOdrzucane(): void
    {
        $s = $this->scenariusz(3, 'pospolita', 5.0);

        $zDuplikatem = [$s['wpisy'][0], $s['wpisy'][1], $s['wpisy'][1]];

        $this->assertThrows(
            ApiException::class,
            fn () => TraderService::kalkulacja($s['userId'], $zDuplikatem, $this->db),
            'Ten sam wpis wybrany dwa razy musi być odrzucony',
            'duplicate_entry'
        );

        $obcy = Fixtures::user($this->db, 'konkurent', 500.0);
        $cudzyWpis = Fixtures::wpisEkwipunku($this->db, $obcy['id'], $s['katalog']['pospolita'], 5.0);

        $this->assertThrows(
            ApiException::class,
            fn () => TraderService::trade($s['userId'], [$s['wpisy'][0], $s['wpisy'][1], $cudzyWpis], Fixtures::scriptedRandom([0.1]), $this->db),
            'Wpis innego gracza musi być odrzucony',
            'item_not_found'
        );

        $this->assertCount(3, (new Ekwipunek($s['userId'], $this->db))->lista(), 'Odrzucony kontrakt nie pali wkładu');
    }

    public function testHistoriaZapisujeTypTraderIKosztWkladu(): void
    {
        $s = $this->scenariusz(3, 'pospolita', 5.0);

        $wynik = TraderService::trade($s['userId'], $s['wpisy'], Fixtures::scriptedRandom([0.1]), $this->db);

        $row = $this->db->query('SELECT typ, koszt, wygrana_wartosc, udane, wynik_id FROM historia_losowan')->fetch();

        $this->assertSame('trader', (string) $row['typ'], 'Typ losowania z enuma TypLosowania::Trader');
        $this->assertFloat(15.0, (float) $row['koszt'], 'Koszt kontraktu = łączna wartość wkładu');
        $this->assertFloat(8.0, (float) $row['wygrana_wartosc'], 'Wypłata = wartość nagrody');
        $this->assertSame(1, (int) $row['udane'], 'Kontrakt zawsze coś daje');
        $this->assertSame($s['katalog']['przecietna'], (int) $row['wynik_id'], 'Wynik = wylosowana nagroda');

        $historia = Losowanie::historia($s['userId'], 10, $this->db);

        $this->assertSame('Trader', $historia[0]['typ_nazwa'], 'Nazwa trybu w historii gracza');
        $this->assertSame('Trader', $historia[0]['zrodlo'], 'Źródło = nazwa trybu (trader nie ma łowiska ani skrzynki)');
        $this->assertTrue($wynik['historia_id'] > 0, 'Odpowiedź wskazuje wpis historii');

        $this->assertFloat(
            round(8.0 / 15.0, 4),
            Losowanie::rtpDlaTypu(TypLosowania::Trader, $this->db),
            'RTP tradera = wypłata / wartość wkładu'
        );
    }

    public function testReelMaNagrodeNaIndeksieWygranej(): void
    {
        $s = $this->scenariusz(3, 'pospolita', 5.0);

        $wynik = TraderService::trade($s['userId'], $s['wpisy'], Fixtures::scriptedRandom([0.1]), $this->db);

        $this->assertSame($wynik['reel_dlugosc'], count($wynik['reel']), 'Pasek ma dokładnie tyle pozycji, ile mówi długość');
        $this->assertSame(
            $wynik['nagroda']['przedmiot_id'],
            (int) $wynik['reel'][$wynik['reel_indeks_wygranej']]['przedmiot_id'],
            'Pod reel_indeks_wygranej leży nagroda (ten sam kontrakt co w łowisku i skrzynce)'
        );
    }
}
