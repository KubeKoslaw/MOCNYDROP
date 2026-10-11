<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\ApiException;
use MocnyDrop\Ekwipunek;
use MocnyDrop\ILosujacy;
use MocnyDrop\Losowanie;
use MocnyDrop\Portfel;
use MocnyDrop\Przedmiot;
use MocnyDrop\Skrzynka;
use MocnyDrop\SkrzynkaPrzedmiot;
use MocnyDrop\TypLosowania;
use MocnyDrop\Uzytkownik;

/**
 * tests/SkrzynkaTest.php
 *
 * Przypadek użycia UC_CASE «Otwarcie skrzynki» z diagramu use case razem z
 * przypadkami rozszerzającymi:
 *   CASE_ERR  «Brak środków»
 *   CASE_SELL «Natychmiastowa sprzedaż wygranej»
 *
 * Oraz klasy z diagramu klas:
 *   Skrzynka (otworz, losuj, czyAktywna) ..|> ILosujacy   (krawędź e13)
 *   SkrzynkaPrzedmiot (losuj)            ..|> ILosujacy   (krawędź e14)
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class SkrzynkaTest extends TestCase
{
    private function gracz(float $saldo = 500.0, string $login = 'gracz'): Uzytkownik
    {
        $konto = Fixtures::user($this->db, $login, $saldo);

        return Uzytkownik::znajdz($konto['id'], $this->db);
    }

    /** Skrzynka z dokładnie jednym dropem — wynik deterministyczny bez generatora. */
    private function prostaSkrzynka(float $cena = 20.0, float $wartoscDropu = 50.0): int
    {
        $id = Fixtures::skrzynka($this->db, 'Skrzynka Prosta', $cena);

        Fixtures::przedmiotSkrzynki($this->db, $id, 'Anchois', 'pospolita', $wartoscDropu, null, 'anchovy.png');

        return $id;
    }

    public function testOtwarciePobieraCeneIDodajePrzedmiotDoEkwipunku(): void
    {
        $gracz = $this->gracz(100.0);
        $skrzynka = Skrzynka::wymagaj($this->prostaSkrzynka(20.0, 50.0), $this->db);

        $wynik = $skrzynka->otworzSzczegolowo($gracz, false, null, $this->db);

        $this->assertInstanceOf(Przedmiot::class, $wynik['przedmiot'], 'otworz() zwraca Przedmiot (sygnatura z diagramu)');
        $this->assertSame('Anchois', $wynik['przedmiot']->nazwa, 'Wylosowany przedmiot z puli skrzynki');
        $this->assertFloat(80.0, Portfel::dlaUzytkownika($gracz->id, $this->db)->saldo, 'Saldo po pobraniu ceny skrzynki');
        $this->assertFloat(20.0, (float) $wynik['saldo_przed'] - (float) $wynik['saldo_po'], 'Różnica salda = cena skrzynki');

        $ekwipunek = (new Ekwipunek($gracz->id, $this->db))->lista();

        $this->assertCount(1, $ekwipunek, 'Wygrana trafia do ekwipunku (Ekwipunek::dodaj)');
        $this->assertFloat(50.0, (float) $ekwipunek[0]['wartosc'], 'Wartość zamrożona w chwili nabycia');
        $this->assertSame($wynik['ekwipunek_id'], (int) $ekwipunek[0]['ekwipunek_id'], 'Odpowiedź wskazuje wpis ekwipunku');
    }

    /** Metoda z diagramu: `+ otworz(u : Uzytkownik) : Przedmiot`. */
    public function testOtworzZDiagramuZwracaPrzedmiot(): void
    {
        $gracz = $this->gracz(100.0);
        $skrzynka = Skrzynka::wymagaj($this->prostaSkrzynka(), $this->db);

        $przedmiot = $skrzynka->otworz($gracz);

        $this->assertInstanceOf(Przedmiot::class, $przedmiot, 'otworz() musi zwracać Przedmiot');
        $this->assertSame('Anchois', $przedmiot->nazwa, 'otworz() zwraca wylosowany przedmiot');
    }

    public function testOtwarcieZapisujeHistorieTypSkrzynka(): void
    {
        $gracz = $this->gracz(100.0);
        $skrzynkaId = $this->prostaSkrzynka(20.0, 50.0);
        $skrzynka = Skrzynka::wymagaj($skrzynkaId, $this->db);

        $wynik = $skrzynka->otworzSzczegolowo($gracz, false, null, $this->db);

        $stmt = $this->db->prepare('SELECT typ, skrzynka_id, koszt, wygrana_wartosc, udane FROM historia_losowan WHERE id = ?');
        $stmt->execute([$wynik['historia_id']]);
        $row = $stmt->fetch();

        $this->assertSame('skrzynka', (string) $row['typ'], 'Typ losowania z enuma TypLosowania::Skrzynka');
        $this->assertSame($skrzynkaId, (int) $row['skrzynka_id'], 'Asocjacja Skrzynka — Losowanie (*:1) z diagramu');
        $this->assertFloat(20.0, (float) $row['koszt'], 'Koszt = cena skrzynki');
        $this->assertFloat(50.0, (float) $row['wygrana_wartosc'], 'Wypłacona wartość wygranej');
        $this->assertSame(1, (int) $row['udane'], 'Otwarcie skrzynki zawsze coś daje');

        $historia = Losowanie::historia($gracz->id, 10, $this->db);

        $this->assertSame('Skrzynka Prosta', $historia[0]['zrodlo'], 'Historia pokazuje nazwę skrzynki jako źródło');
        $this->assertSame('Skrzynka Prosta', $historia[0]['skrzynka'], 'Osobne pole skrzynki w historii');
        $this->assertTrue($historia[0]['udane'], 'Flaga udanego losowania w historii');
    }

    /** CASE_ERR «Brak środków» — przypadek rozszerzający z diagramu use case. */
    public function testBrakSrodkowJestOdrzucany(): void
    {
        $gracz = $this->gracz(5.0);
        $skrzynka = Skrzynka::wymagaj($this->prostaSkrzynka(20.0), $this->db);

        $this->assertThrows(
            ApiException::class,
            fn () => $skrzynka->otworzSzczegolowo($gracz, false, null, $this->db),
            'Otwarcie bez środków musi być odrzucone (CASE_ERR)',
            'insufficient_funds'
        );

        $this->assertFloat(5.0, Portfel::dlaUzytkownika($gracz->id, $this->db)->saldo, 'Odrzucone otwarcie nie rusza salda');
        $this->assertSame(
            0,
            (int) $this->db->query('SELECT COUNT(*) FROM ekwipunek')->fetchColumn(),
            'Odrzucone otwarcie nie dodaje przedmiotu'
        );
        $this->assertSame(
            0,
            (int) $this->db->query('SELECT COUNT(*) FROM historia_losowan')->fetchColumn(),
            'Odrzucone otwarcie nie zapisuje historii (atomowość transakcji)'
        );
    }

    /** CASE_SELL «Natychmiastowa sprzedaż wygranej». */
    public function testNatychmiastowaSprzedazWygranej(): void
    {
        $gracz = $this->gracz(100.0);
        $skrzynka = Skrzynka::wymagaj($this->prostaSkrzynka(20.0, 50.0), $this->db);

        $wynik = $skrzynka->otworzSzczegolowo($gracz, true, null, $this->db);

        $this->assertNotNull($wynik['sprzedaz'], 'Przy sprzedaży natychmiastowej odpowiedź zawiera jej wynik');
        $this->assertFloat(50.0, (float) $wynik['sprzedaz']['otrzymane_monety'], 'Kwota ze sprzedaży wygranej');
        // 100 - 20 (cena skrzynki) + 50 (sprzedaż) = 130.
        $this->assertFloat(130.0, (float) $wynik['saldo_po'], 'Saldo po otwarciu i natychmiastowej sprzedaży');
        $this->assertFloat(130.0, Portfel::dlaUzytkownika($gracz->id, $this->db)->saldo, 'Saldo w bazie po sprzedaży');

        $ekwipunek = new Ekwipunek($gracz->id, $this->db);

        $this->assertCount(0, $ekwipunek->lista(), 'Sprzedana wygrana znika z listy ekwipunku');
        $this->assertCount(1, $ekwipunek->lista(true), 'Z flagą z_sprzedanymi wpis jest widoczny');
    }

    public function testNieaktywnaSkrzynkaJestOdrzucana(): void
    {
        $gracz = $this->gracz(100.0);
        $id = Fixtures::skrzynka($this->db, 'Wyłączona', 10.0, false);

        Fixtures::przedmiotSkrzynki($this->db, $id, 'Anchois', 'pospolita', 2.0);

        $skrzynka = Skrzynka::wymagaj($id, $this->db);

        $this->assertFalse($skrzynka->czyAktywna(), 'czyAktywna() z diagramu oddaje stan skrzynki');
        $this->assertFalse($skrzynka->czyDajeSieOtworzyc(), 'Nieaktywnej skrzynki nie da się otworzyć');

        $this->assertThrows(
            ApiException::class,
            fn () => $skrzynka->otworzSzczegolowo($gracz, false, null, $this->db),
            'Otwarcie nieaktywnej skrzynki musi być odrzucone',
            'case_inactive'
        );

        $this->assertFloat(100.0, Portfel::dlaUzytkownika($gracz->id, $this->db)->saldo, 'Nieaktywna skrzynka nie pobiera opłaty');
    }

    public function testSkrzynkaBezPuliJestOdrzucana(): void
    {
        $gracz = $this->gracz(100.0);
        $skrzynka = Skrzynka::wymagaj(Fixtures::skrzynka($this->db, 'Pusta', 10.0), $this->db);

        $this->assertThrows(
            ApiException::class,
            fn () => $skrzynka->otworzSzczegolowo($gracz, false, null, $this->db),
            'Skrzynka bez dropów musi być odrzucona',
            'case_empty'
        );
    }

    /** Realizacja interfejsu ILosujacy przez obie klasy z diagramu (e13, e14). */
    public function testLosujRealizujeInterfejsILosujacy(): void
    {
        $id = $this->prostaSkrzynka();

        $skrzynka = Skrzynka::wymagaj($id, $this->db);

        $this->assertInstanceOf(ILosujacy::class, $skrzynka, 'Skrzynka realizuje ILosujacy');
        $this->assertInstanceOf(Przedmiot::class, $skrzynka->losuj(), 'Skrzynka::losuj() zwraca Przedmiot');

        $wpis = SkrzynkaPrzedmiot::pula($id, $this->db)[0];

        $this->assertInstanceOf(ILosujacy::class, $wpis, 'SkrzynkaPrzedmiot realizuje ILosujacy');
        $this->assertInstanceOf(Przedmiot::class, $wpis->losuj(), 'SkrzynkaPrzedmiot::losuj() zwraca Przedmiot');
        $this->assertSame('Anchois', $wpis->losuj()->nazwa, 'Wpis puli zwraca swój przedmiot');
        $this->assertFloat(50.0, $wpis->waga, 'Waga wpisu puli (atrybut z diagramu) dziedziczona z poziomu rzadkości');

        // losuj() to samo losowanie — bez opłaty, bez ekwipunku, bez historii.
        $this->assertSame(
            0,
            (int) $this->db->query('SELECT COUNT(*) FROM historia_losowan')->fetchColumn(),
            'losuj() nie zapisuje historii (to nie otwarcie skrzynki)'
        );
        $this->assertSame(
            0,
            (int) $this->db->query('SELECT COUNT(*) FROM ekwipunek')->fetchColumn(),
            'losuj() nie dodaje przedmiotu do ekwipunku'
        );
    }

    public function testPulaDziedziczyWagiDomyslneIPokazujeSzans(): void
    {
        $skrzynka = Skrzynka::wymagaj(Fixtures::readmeSkrzynka($this->db, 20.0), $this->db);

        $pula = $skrzynka->pula($this->db);

        $this->assertCount(6, $pula, 'Pula z README.md ma sześć poziomów rzadkości');
        $this->assertFloat(50.0, (float) $pula[0]['waga'], 'Pospolita dziedziczy wagę 50');
        $this->assertFloat(0.001, (float) $pula[5]['waga'], 'ULTRA dziedziczy wagę 0.001');
        $this->assertBetween(49.9, 50.0, (float) $pula[0]['szansa_procent'], 'Szansa Pospolitej około 50%');

        $suma = 0.0;

        foreach ($pula as $item) {
            $suma += (float) $item['szansa_procent'];
        }

        $this->assertBetween(99.9, 100.1, $suma, 'Szanse całej puli sumują się do 100%');
    }

    /** Kontrakt paska ruletki — identyczny jak w api/fish.php. */
    public function testOtwarcieZwracaPasekRuletkiZWygranaNaIndeksie(): void
    {
        $gracz = $this->gracz(1000.0);
        $skrzynka = Skrzynka::wymagaj(Fixtures::readmeSkrzynka($this->db, 10.0), $this->db);

        $wynik = $skrzynka->otworzSzczegolowo($gracz, false, Fixtures::scriptedRandom([0.5]), $this->db);

        $this->assertSame($wynik['reel_dlugosc'], count($wynik['reel']), 'Pasek ma dokładnie tyle pozycji, ile mówi długość');

        $indeks = (int) $wynik['reel_indeks_wygranej'];

        $this->assertSame(
            $wynik['przedmiot']->id,
            (int) $wynik['reel'][$indeks]['przedmiot_id'],
            'Pod reel_indeks_wygranej leży wygrany przedmiot (kontrakt z frontendem)'
        );
        // pick = 0.5 * 100.001 = 50.0005, czyli tuż ZA wagą Pospolitej (50).
        $this->assertSame('Pstrąg Potokowy', $wynik['przedmiot']->nazwa, 'random 0.5 → drugi poziom puli (próg 80/100.001)');
    }

    public function testWymagajRzuca404DlaNieistniejacejSkrzynki(): void
    {
        $this->assertNull(Skrzynka::znajdz(999, $this->db), 'znajdz() zwraca null dla brakującej skrzynki');

        $this->assertThrows(
            ApiException::class,
            fn () => Skrzynka::wymagaj(999, $this->db),
            'wymagaj() rzuca 404 zamiast zwracać null',
            'case_not_found'
        );
    }

    public function testAktywneZwracajaTylkoAktywneSkrzynki(): void
    {
        Fixtures::skrzynka($this->db, 'Aktywna', 10.0, true);
        Fixtures::skrzynka($this->db, 'Wyłączona', 10.0, false);

        $aktywne = Skrzynka::aktywne($this->db);

        $this->assertCount(1, $aktywne, 'Lista aktywnych pomija wyłączone skrzynki');
        $this->assertSame('Aktywna', $aktywne[0]->nazwa, 'Zwrócona skrzynka jest aktywna');
        $this->assertCount(2, Skrzynka::wszystkie($this->db), 'Panel admina widzi też wyłączone skrzynki');
    }

    public function testDoApiMaKsztaltKontraktu(): void
    {
        $id = $this->prostaSkrzynka(20.0);

        $api = Skrzynka::wymagaj($id, $this->db)->doApi();

        foreach (['id', 'nazwa', 'opis', 'cena', 'ikona', 'aktywna', 'liczba_przedmiotow', 'przedmioty'] as $klucz) {
            $this->assertArrayHasKey($klucz, $api, 'Odpowiedź API musi mieć klucz: ' . $klucz);
        }

        $this->assertSame($id, $api['id'], 'id skrzynki w odpowiedzi');
        $this->assertFloat(20.0, (float) $api['cena'], 'cena skrzynki w odpowiedzi');
        $this->assertTrue((bool) $api['aktywna'], 'stan aktywności w odpowiedzi');

        $drop = $api['przedmioty'][0];

        foreach (['przedmiot_id', 'nazwa', 'rzadkosc', 'kolor', 'wartosc', 'ikona', 'waga', 'szansa_procent'] as $klucz) {
            $this->assertArrayHasKey($klucz, $drop, 'Drop w odpowiedzi API musi mieć klucz: ' . $klucz);
        }

        $this->assertFloat(100.0, (float) $drop['szansa_procent'], 'Przy jednym dropie szansa wynosi 100%');
    }

    /** TypLosowania::Skrzynka ma własne RTP — monitoring liczy je per tryb. */
    public function testRtpSkrzynekLiczySieOsobno(): void
    {
        $gracz = $this->gracz(1000.0);
        $skrzynka = Skrzynka::wymagaj($this->prostaSkrzynka(20.0, 50.0), $this->db);

        $skrzynka->otworzSzczegolowo($gracz, false, null, $this->db);

        $this->assertFloat(2.5, Losowanie::rtpDlaTypu(TypLosowania::Skrzynka, $this->db), 'RTP skrzynek = 50 / 20');
        $this->assertFloat(0.0, Losowanie::rtpDlaTypu(TypLosowania::Wedka, $this->db), 'Łowiska mają osobne RTP');
    }
}
