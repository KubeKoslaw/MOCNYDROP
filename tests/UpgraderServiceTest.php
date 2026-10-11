<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\ApiException;
use MocnyDrop\Ekwipunek;
use MocnyDrop\Losowanie;
use MocnyDrop\Portfel;
use MocnyDrop\Settings;
use MocnyDrop\TypLosowania;
use MocnyDrop\UpgraderService;

/**
 * tests/UpgraderServiceTest.php
 *
 * Przypadek użycia UC_UPGRADE «Ulepszenie przedmiotu (Upgrader)» z diagramu
 * use case i „Ulepszanie w Upgraderze (POST /api/upgrade.php)" ze strony 2
 * diagramu MOCNYDROP_PROJEKT.
 *
 * Sprawdzone kroki z diagramu przepływu:
 *   - wybór przedmiotu wkładu ze swojego ekwipunku,
 *   - wybór pożądanego przedmiotu celu z katalogu,
 *   - kalkulacja szansy % = (W_wkład / W_cel) * (1 - marża),
 *   - zablokowanie i trwałe usunięcie wkładu,
 *   - losowanie liczby wirtualnego koła (0.00% - 100.00%),
 *   - Porażka: bezpowrotna utrata przedmiotu wkładu (extend),
 *   - Wygrana: dodanie ulepszonego przedmiotu do bazy (extend).
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class UpgraderServiceTest extends TestCase
{
    /** Licznik do unikalnych loginów — w jednym teście bywa kilka scenariuszy. */
    private static int $licznikScenariuszy = 0;

    /**
     * Scenariusz: gracz ma w ekwipunku przedmiot o wartości $wartoscWkladu
     * i chce go ulepszyć na przedmiot z katalogu o wartości $wartoscCelu.
     *
     * @return array{userId: int, ekwipunekId: int, wkladId: int, celId: int}
     */
    private function scenariusz(float $wartoscWkladu = 50.0, float $wartoscCelu = 100.0): array
    {
        $konto = Fixtures::user($this->db, 'ulepszacz_' . (++self::$licznikScenariuszy), 500.0);

        $wkladId = Fixtures::przedmiotKatalog($this->db, 'Anchois', 'pospolita', $wartoscWkladu, 'anchovy.png');
        $celId = Fixtures::przedmiotKatalog($this->db, 'Jesiotr', 'niesamowita', $wartoscCelu, 'sturgeon.png');

        return [
            'userId' => $konto['id'],
            'ekwipunekId' => Fixtures::wpisEkwipunku($this->db, $konto['id'], $wkladId),
            'wkladId' => $wkladId,
            'celId' => $celId,
        ];
    }

    /** Wzór z diagramu PROJEKT: szansa % = (W_wkład / W_cel) * (1 - marża). */
    public function testSzansaLiczySieZWzoruZDiagramu(): void
    {
        $this->assertFloat(0.475, UpgraderService::szansa(50.0, 100.0, 0.05), '50/100 z marżą 5% = 47.5%');
        $this->assertFloat(0.2, UpgraderService::szansa(25.0, 100.0, 0.2), '25/100 z marżą 20% = 20%');
        $this->assertFloat(0.5, UpgraderService::szansa(50.0, 100.0, 0.0), 'Bez marży szansa to czysty stosunek wartości');
        $this->assertFloat(0.0, UpgraderService::szansa(0.0, 100.0, 0.05), 'Zerowy wkład nie daje szansy');
        $this->assertFloat(0.0, UpgraderService::szansa(50.0, 0.0, 0.05), 'Zerowy cel nie daje szansy');
        $this->assertFloat(0.0, UpgraderService::szansa(NAN, 100.0, 0.05), 'NaN nie może dać szansy');
        $this->assertFloat(1.0, UpgraderService::szansa(200.0, 100.0, 0.0), 'Szansa nigdy nie przekracza 100%');
    }

    public function testMarzaPochodziZTabeliUstawienia(): void
    {
        $this->assertFloat(0.05, UpgraderService::marza($this->db), 'Domyślna marża z schema.sql');

        Fixtures::ustawienie($this->db, Settings::KEY_UPGRADER_MARGIN, '0.2');

        $this->assertFloat(0.2, UpgraderService::marza($this->db), 'Marża czytana z bazy, nie z kodu');

        Fixtures::ustawienie($this->db, Settings::KEY_UPGRADER_MARGIN, '5');

        $this->assertFloat(0.95, UpgraderService::marza($this->db), 'Marża jest przycinana do 0.95 (błąd w ustawieniach nie wywraca gry)');
    }

    public function testKalkulacjaZwracaSzansINieZmieniaStanu(): void
    {
        $s = $this->scenariusz(50.0, 100.0);

        $kalkulacja = UpgraderService::kalkulacja($s['userId'], $s['ekwipunekId'], $s['celId'], $this->db);

        $this->assertFloat(47.5, (float) $kalkulacja['szansa_procent'], 'Szansa w procentach dla koła');
        $this->assertFloat(0.475, (float) $kalkulacja['szansa'], 'Szansa jako ułamek do porównania z kołem');
        $this->assertFloat(0.05, (float) $kalkulacja['marza'], 'Marża w odpowiedzi kalkulacji');
        $this->assertSame('Anchois', $kalkulacja['wklad']['nazwa'], 'Kalkulacja pokazuje przedmiot wkładu');
        $this->assertSame('Jesiotr', $kalkulacja['cel']['nazwa'], 'Kalkulacja pokazuje przedmiot celu');
        $this->assertArrayHasKey('ikona', $kalkulacja['cel'], 'Cel ma ikonę dla frontendu');
        $this->assertArrayHasKey('kolor', $kalkulacja['cel'], 'Cel ma kolor rzadkości dla frontendu');

        // Kalkulacja odpowiada na GET — nie może niczego spalić ani zapisać.
        $this->assertCount(1, (new Ekwipunek($s['userId'], $this->db))->lista(), 'Kalkulacja nie usuwa wkładu');
        $this->assertSame(
            0,
            (int) $this->db->query('SELECT COUNT(*) FROM historia_losowan')->fetchColumn(),
            'Kalkulacja nie zapisuje historii'
        );
    }

    /** «Wygrana: Dodanie ulepszonego przedmiotu do bazy SQLite». */
    public function testWygranaDodajeUlepszonyPrzedmiot(): void
    {
        $s = $this->scenariusz(50.0, 100.0);

        $saldoPrzed = Portfel::dlaUzytkownika($s['userId'], $this->db)->saldo;

        // koło = 5.00% < szansa 47.5% -> wygrana
        $wynik = UpgraderService::upgrade($s['userId'], $s['ekwipunekId'], $s['celId'], Fixtures::scriptedRandom([0.05]), $this->db);

        $this->assertTrue((bool) $wynik['udane'], 'Koło poniżej szansy musi dać wygraną');
        $this->assertFloat(5.0, (float) $wynik['kolo'], 'Wylosowana liczba wirtualnego koła w procentach');
        $this->assertNotNull($wynik['przedmiot'], 'Przy wygranej odpowiedź zawiera ulepszony przedmiot');
        $this->assertSame('Jesiotr', $wynik['przedmiot']['nazwa'], 'Ulepszony przedmiot to cel z katalogu');
        $this->assertFloat(100.0, (float) $wynik['przedmiot']['wartosc'], 'Wartość ulepszonego przedmiotu');

        $ekwipunek = (new Ekwipunek($s['userId'], $this->db))->lista(true);

        $this->assertCount(1, $ekwipunek, 'Wkład znika, a w jego miejscu pojawia się cel');
        $this->assertSame('Jesiotr', $ekwipunek[0]['nazwa'], 'W ekwipunku został ulepszony przedmiot');
        $this->assertFloat(100.0, (float) $ekwipunek[0]['wartosc'], 'Wartość zamrożona w chwili ulepszenia');

        $this->assertFloat($saldoPrzed, Portfel::dlaUzytkownika($s['userId'], $this->db)->saldo, 'Upgrader nie rusza portfela');
    }

    /** «Porażka: Bezpowrotna utrata przedmiotu wkładu». */
    public function testPorazkaZabieraWkladBezpowrotnie(): void
    {
        $s = $this->scenariusz(50.0, 100.0);

        // koło = 90.00% > szansa 47.5% -> porażka
        $wynik = UpgraderService::upgrade($s['userId'], $s['ekwipunekId'], $s['celId'], Fixtures::scriptedRandom([0.9]), $this->db);

        $this->assertFalse((bool) $wynik['udane'], 'Koło powyżej szansy musi dać porażkę');
        $this->assertFloat(90.0, (float) $wynik['kolo'], 'Wylosowana liczba koła przy porażce');
        $this->assertNull($wynik['przedmiot'], 'Przy porażce nie ma wygranego przedmiotu');

        $ekwipunek = new Ekwipunek($s['userId'], $this->db);

        $this->assertCount(0, $ekwipunek->lista(true), 'Przegrany wkład znika na zawsze (nawet z flagą z_sprzedanymi)');
        $this->assertSame(
            0,
            (int) $this->db->query('SELECT COUNT(*) FROM ekwipunek')->fetchColumn(),
            'Wpis wkładu jest TRWALE usunięty z tabeli ekwipunek'
        );

        $this->assertThrows(
            ApiException::class,
            fn () => $ekwipunek->sprzedajWpis($s['ekwipunekId']),
            'Spalonego przedmiotu nie da się sprzedać',
            'item_not_found'
        );
    }

    /** Granica koła: wynik dokładnie równy szansie to jeszcze porażka. */
    public function testGranicaSzansyJestPorazka(): void
    {
        $s = $this->scenariusz(50.0, 100.0);

        $wynik = UpgraderService::upgrade($s['userId'], $s['ekwipunekId'], $s['celId'], Fixtures::scriptedRandom([0.475]), $this->db);

        $this->assertFalse((bool) $wynik['udane'], 'Koło równe szansie nie wygrywa (ostra nierówność koło < szansa)');
    }

    public function testHistoriaZapisujeTypUpgraderSzansaIWynik(): void
    {
        $s = $this->scenariusz(50.0, 100.0);

        UpgraderService::upgrade($s['userId'], $s['ekwipunekId'], $s['celId'], Fixtures::scriptedRandom([0.9]), $this->db);

        $row = $this->db->query('SELECT typ, koszt, wygrana_wartosc, udane, waga_wylosowana, suma_wag FROM historia_losowan')->fetch();

        $this->assertSame('upgrader', (string) $row['typ'], 'Typ losowania z enuma TypLosowania::Upgrader');
        $this->assertFloat(50.0, (float) $row['koszt'], 'Koszt próby = wartość wkładu');
        $this->assertFloat(0.0, (float) $row['wygrana_wartosc'], 'Przy porażce wypłata wynosi 0');
        $this->assertSame(0, (int) $row['udane'], 'Porażka jest oznaczona w historii');
        $this->assertFloat(47.5, (float) $row['waga_wylosowana'], 'Szansa zapisana jako waga');
        $this->assertFloat(100.0, (float) $row['suma_wag'], 'Suma wag 100 = skala wirtualnego koła');

        $historia = Losowanie::historia($s['userId'], 10, $this->db);

        $this->assertSame('Upgrader', $historia[0]['typ_nazwa'], 'Nazwa trybu w historii');
        $this->assertFloat(47.5, (float) $historia[0]['szansa_procent'], 'Historia odtwarza szansę próby');
        $this->assertFalse($historia[0]['udane'], 'Flaga porażki w historii gracza');
        $this->assertFloat(-50.0, (float) $historia[0]['bilans'], 'Bilans porażki = -wartość wkładu');
    }

    public function testRtpUpgraderaLiczySieOsobno(): void
    {
        $s = $this->scenariusz(50.0, 100.0);

        // Wygrana (koszt 50, wypłata 100) i porażka (koszt 50, wypłata 0).
        UpgraderService::upgrade($s['userId'], $s['ekwipunekId'], $s['celId'], Fixtures::scriptedRandom([0.05]), $this->db);

        $drugiWklad = Fixtures::wpisEkwipunku($this->db, $s['userId'], $s['wkladId']);

        UpgraderService::upgrade($s['userId'], $drugiWklad, $s['celId'], Fixtures::scriptedRandom([0.9]), $this->db);

        $this->assertFloat(1.0, Losowanie::rtpDlaTypu(TypLosowania::Upgrader, $this->db), 'RTP upgradera = 100 / (50 + 50)');
        $this->assertFloat(0.0, Losowanie::rtpDlaTypu(TypLosowania::Skrzynka, $this->db), 'Inne tryby mają osobne RTP');
    }

    /**
     * Ekonomika trybu: EV = szansa * W_cel = W_wkład * (1 - marża), więc próba
     * ZAWSZE oddaje mniej, niż wkłada gracz. Bez tego upgrader byłby darmową
     * maszynką do monet.
     */
    public function testWartoscOczekiwanaJestNizszaNizWklad(): void
    {
        foreach ([[10.0, 20.0], [50.0, 100.0], [100.0, 250.0], [1.0, 25000.0]] as [$wklad, $cel]) {
            $szansa = UpgraderService::szansa($wklad, $cel, 0.05);

            $this->assertTrue($szansa > 0.0 && $szansa <= 1.0, 'Szansa musi być w (0, 1] dla ' . $wklad . '/' . $cel);
            $this->assertTrue(
                $szansa * $cel < $wklad,
                sprintf('EV (%.4f) musi być niższa od wkładu (%.2f)', $szansa * $cel, $wklad)
            );
        }
    }

    public function testCelTanszyLubRownyWkladowiJestOdrzucany(): void
    {
        $s = $this->scenariusz(100.0, 100.0);

        $this->assertThrows(
            ApiException::class,
            fn () => UpgraderService::kalkulacja($s['userId'], $s['ekwipunekId'], $s['celId'], $this->db),
            'Cel o tej samej wartości co wkład musi być odrzucony',
            'target_must_be_more_valuable'
        );

        // Ten sam scenariusz, ale cel potaniał — „ulepszanie" w dół.
        $this->db->prepare('UPDATE przedmioty SET wartosc = 20.0 WHERE id = ?')->execute([$s['celId']]);

        $this->assertThrows(
            ApiException::class,
            fn () => UpgraderService::upgrade($s['userId'], $s['ekwipunekId'], $s['celId'], null, $this->db),
            'Ulepszanie w dół (cel tańszy od wkładu) musi być odrzucone',
            'target_must_be_more_valuable'
        );

        $this->assertCount(1, (new Ekwipunek($s['userId'], $this->db))->lista(), 'Odrzucona próba nie może spalić wkładu');
    }

    public function testWkladSpozaEkwipunkuJestOdrzucany(): void
    {
        $s = $this->scenariusz();

        $obcy = Fixtures::user($this->db, 'obcy', 100.0);

        $this->assertThrows(
            ApiException::class,
            fn () => UpgraderService::kalkulacja($obcy['id'], $s['ekwipunekId'], $s['celId'], $this->db),
            'Nie da się ulepszyć przedmiotu innego gracza',
            'item_not_found'
        );

        $this->assertThrows(
            ApiException::class,
            fn () => UpgraderService::kalkulacja($s['userId'], 99999, $s['celId'], $this->db),
            'Nieistniejący wpis ekwipunku musi być odrzucony',
            'item_not_found'
        );

        $this->assertThrows(
            ApiException::class,
            fn () => UpgraderService::kalkulacja($s['userId'], $s['ekwipunekId'], 99999, $this->db),
            'Nieistniejący cel musi być odrzucony',
            'item_not_found'
        );
    }

    public function testSprzedanyWkladNieMozeBycUlepszony(): void
    {
        $s = $this->scenariusz();

        (new Ekwipunek($s['userId'], $this->db))->sprzedajWpis($s['ekwipunekId']);

        $this->assertThrows(
            ApiException::class,
            fn () => UpgraderService::upgrade($s['userId'], $s['ekwipunekId'], $s['celId'], Fixtures::scriptedRandom([0.05]), $this->db),
            'Sprzedany przedmiot nie może być wkładem w upgraderze',
            'already_sold'
        );
    }

    public function testNieaktywnyCelJestOdrzucany(): void
    {
        $konto = Fixtures::user($this->db, 'ulepszacz2', 500.0);

        $wkladId = Fixtures::przedmiotKatalog($this->db, 'Anchois', 'pospolita', 50.0);
        $celId = Fixtures::przedmiotKatalog($this->db, 'Wycofany', 'niesamowita', 400.0, 'void_fish.png', false);
        $ekwipunekId = Fixtures::wpisEkwipunku($this->db, $konto['id'], $wkladId);

        $this->assertThrows(
            ApiException::class,
            fn () => UpgraderService::upgrade($konto['id'], $ekwipunekId, $celId, Fixtures::scriptedRandom([0.05]), $this->db),
            'Cel wycofany z katalogu musi być odrzucony',
            'item_inactive'
        );

        $this->assertCount(1, (new Ekwipunek($konto['id'], $this->db))->lista(), 'Odrzucona próba nie może spalić wkładu');
    }

    public function testWkladOZerowejWartosciJestOdrzucany(): void
    {
        $s = $this->scenariusz(0.0, 100.0);

        $this->assertThrows(
            ApiException::class,
            fn () => UpgraderService::kalkulacja($s['userId'], $s['ekwipunekId'], $s['celId'], $this->db),
            'Wkład o zerowej wartości nie daje żadnej szansy',
            'invalid_input_value'
        );
    }

    /** Podwójne kliknięcie „Ulepsz" nie może spalić przedmiotu dwa razy. */
    public function testPodwojnaProbaJestOdrzucana(): void
    {
        $s = $this->scenariusz();

        UpgraderService::upgrade($s['userId'], $s['ekwipunekId'], $s['celId'], Fixtures::scriptedRandom([0.05]), $this->db);

        $this->assertThrows(
            ApiException::class,
            fn () => UpgraderService::upgrade($s['userId'], $s['ekwipunekId'], $s['celId'], Fixtures::scriptedRandom([0.05]), $this->db),
            'Druga próba na tym samym wpisie musi być odrzucona',
            'item_not_found'
        );

        $this->assertSame(
            1,
            (int) $this->db->query('SELECT COUNT(*) FROM historia_losowan')->fetchColumn(),
            'Odrzucona próba nie zapisuje drugiego losowania'
        );
    }
}
