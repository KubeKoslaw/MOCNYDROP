<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\ApiException;
use MocnyDrop\FishingService;
use MocnyDrop\Ekwipunek;
use MocnyDrop\Portfel;

/**
 * tests/EkwipunekTest.php
 *
 * Testy ekwipunku i sprzedaży złowionych przedmiotów.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class EkwipunekTest extends TestCase
{
    public function testZlowionyPrzedmiotPojawiaSieWEkwipunku(): void
    {
        $gracz = $this->graczZPrzedmiotem(100.0, 25.0, 0.0);

        $ekwipunek = (new Ekwipunek($gracz['id'], $this->db))->lista();

        $this->assertCount(1, $ekwipunek, 'Po jednym zarzucie w ekwipunku jest jedna ryba');

        $ryba = $ekwipunek[0];

        $this->assertSame('Anchois', $ryba['nazwa'], 'Nazwa złowionej przedmioty');
        $this->assertSame('Pospolita', $ryba['rzadkosc'], 'Nazwa poziomu rzadkości');
        $this->assertSame('pospolita', $ryba['rzadkosc_kod'], 'Kod poziomu rzadkości');
        $this->assertSame('#94a3b8', $ryba['kolor'], 'Kolor rzadkości dla frontendu');
        $this->assertFloat(25.0, (float) $ryba['wartosc'], 'Wartość przedmioty');
        $this->assertSame('assets/icons/anchovy.png', $ryba['ikona'], 'Ikona jako ścieżka URL do assets/icons/');
        $this->assertFalse($ryba['sprzedany'], 'Nowo złowiona ryba nie jest sprzedana');
        $this->assertNotNull($ryba['data_nabycia'], 'Data połowu musi być zapisana');
    }

    public function testSprzedazDopisujeMonetyDoSalda(): void
    {
        $gracz = $this->graczZPrzedmiotem(100.0, 25.0, 10.0);

        $ekwipunek = (new Ekwipunek($gracz['id'], $this->db))->lista();
        $saldoPrzed = Portfel::dlaUzytkownika($gracz['id'], $this->db)->saldo;

        $wynik = (new Ekwipunek($gracz['id'], $this->db))->sprzedajWpis((int) $ekwipunek[0]['ekwipunek_id']);

        $this->assertTrue((bool) $wynik['success'], 'Udanna sprzedaż zwraca success=true');
        $this->assertFloat(25.0, (float) $wynik['otrzymane_monety'], 'Kwota ze sprzedaży');
        $this->assertFloat($saldoPrzed + 25.0, (float) $wynik['nowe_saldo'], 'Saldo po sprzedaży w odpowiedzi');
        $this->assertFloat($saldoPrzed + 25.0, Portfel::dlaUzytkownika($gracz['id'], $this->db)->saldo, 'Saldo w bazie po sprzedaży');
        $this->assertSame('Anchois', $wynik['sprzedany_przedmiot']['nazwa'], 'Nazwa sprzedanej przedmioty w odpowiedzi');
    }

    public function testSprzedanyPrzedmiotZnikaZListy(): void
    {
        $gracz = $this->graczZPrzedmiotem(100.0, 25.0, 0.0);

        $ekwipunekId = (int) (new Ekwipunek($gracz['id'], $this->db))->lista()[0]['ekwipunek_id'];

        (new Ekwipunek($gracz['id'], $this->db))->sprzedajWpis($ekwipunekId);

        $this->assertCount(0, (new Ekwipunek($gracz['id'], $this->db))->lista(), 'Domyślna lista nie pokazuje przedmiotów sprzedanych');
        $this->assertCount(1, (new Ekwipunek($gracz['id'], $this->db))->lista(true), 'Z flagą z_sprzedanymi ryba nadal jest widoczna');

        $sprzedane = (new Ekwipunek($gracz['id'], $this->db))->lista(true);

        $this->assertTrue($sprzedane[0]['sprzedany'], 'Flaga sprzedaży musi być ustawiona');
        $this->assertNotNull($sprzedane[0]['sprzedano_kiedy'], 'Data sprzedaży musi zostać zapisana');
    }

    /** Podwójne kliknięcie "Sprzedaj" nie może wypłacić monet dwa razy. */
    public function testPodwojnaSprzedazJestOdrzucana(): void
    {
        $gracz = $this->graczZPrzedmiotem(100.0, 25.0, 0.0);

        $ekwipunekId = (int) (new Ekwipunek($gracz['id'], $this->db))->lista()[0]['ekwipunek_id'];

        (new Ekwipunek($gracz['id'], $this->db))->sprzedajWpis($ekwipunekId);
        $saldoPoPierwszej = Portfel::dlaUzytkownika($gracz['id'], $this->db)->saldo;

        $this->assertThrows(
            ApiException::class,
            fn (): array => (new Ekwipunek($gracz['id'], $this->db))->sprzedajWpis($ekwipunekId),
            'Druga sprzedaż tej samej przedmioty powinna być odrzucona',
            'already_sold'
        );

        $this->assertFloat($saldoPoPierwszej, Portfel::dlaUzytkownika($gracz['id'], $this->db)->saldo, 'Saldo nie może wzrosnąć po drugiej sprzedaży');
    }

    /** Gracz nie może sprzedać przedmioty z cudzego konta, nawet znając jej id. */
    public function testSprzedazCudzejRybyJestOdrzucana(): void
    {
        $wlasciciel = $this->graczZPrzedmiotem(100.0, 25.0, 0.0);
        $oszust = Fixtures::user($this->db, 'oszust', 10.0);

        $ekwipunekId = (int) (new Ekwipunek($wlasciciel['id'], $this->db))->lista()[0]['ekwipunek_id'];

        $this->assertThrows(
            ApiException::class,
            fn (): array => (new Ekwipunek($oszust['id'], $this->db))->sprzedajWpis($ekwipunekId),
            'Sprzedaż cudzej przedmioty powinna być odrzucona',
            'item_not_found'
        );

        $this->assertFloat(10.0, Portfel::dlaUzytkownika($oszust['id'], $this->db)->saldo, 'Oszust nie może dostać monet');
        $this->assertCount(1, (new Ekwipunek($wlasciciel['id'], $this->db))->lista(), 'Przedmiot musi zostać u właściciela');
    }

    public function testSprzedazNieistniejacejRybyJestOdrzucana(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);

        $this->assertThrows(
            ApiException::class,
            fn (): array => (new Ekwipunek($gracz['id'], $this->db))->sprzedajWpis(999999),
            'Sprzedaż nieistniejącego wpisu powinna być odrzucona',
            'item_not_found'
        );
    }

    /**
     * Zmiana balansu zrobiona przez @moxmar26 nie może przecenić przedmiotów już
     * złowionych — cena sprzedaży jest zamrożona w chwili połowu.
     */
    public function testCenaSprzedazyNieZalezyOdAktualnejWartosciRyby(): void
    {
        $gracz = $this->graczZPrzedmiotem(100.0, 25.0, 0.0);

        $ekwipunekId = (int) (new Ekwipunek($gracz['id'], $this->db))->lista()[0]['ekwipunek_id'];

        // Ktoś drastycznie przecenia rybę w katalogu już po połowie.
        $this->db->exec('UPDATE przedmioty SET wartosc = 1.0');

        $wynik = (new Ekwipunek($gracz['id'], $this->db))->sprzedajWpis($ekwipunekId);

        $this->assertFloat(25.0, (float) $wynik['otrzymane_monety'], 'Gracz musi dostać cenę z chwili połowu, nie aktualną z katalogu');
    }

    public function testPodsumowanieLiczyLiczbeIWartosc(): void
    {
        $gracz = Fixtures::user($this->db, 'kolekcjoner', 1000.0);
        $wedka = Fixtures::wedka($this->db, 'Wędka', 0.0);

        Fixtures::przedmiot($this->db, $wedka, 'Anchois', 'pospolita', 2.0, null, 'anchovy.png');
        Fixtures::przedmiot($this->db, $wedka, 'Jesiotr', 'niesamowita', 400.0, null, 'sturgeon.png');

        // Dwie przedmioty o wagach 50 i 1, suma = 51, próg = 50/51 ≈ 0.9804.
        Fixtures::zarzucPosiadanaWedka($this->db, $gracz['id'], $wedka, Fixtures::scriptedRandom([0.5]));
        Fixtures::zarzucPosiadanaWedka($this->db, $gracz['id'], $wedka, Fixtures::scriptedRandom([0.99]));

        $podsumowanie = (new Ekwipunek($gracz['id'], $this->db))->podsumowanie();

        $this->assertSame(2, $podsumowanie['liczba_przedmiotow'], 'Liczba przedmiotów w ekwipunku');
        $this->assertFloat(402.0, (float) $podsumowanie['laczna_wartosc'], 'Łączna wartość = 2.0 + 400.0');
    }

    /** Ryby posortowane od najrzadszych — tak chce tego siatka ekwipunku. */
    public function testEkwipunekJestSortowanyOdNajrzadszych(): void
    {
        $gracz = Fixtures::user($this->db, 'kolekcjoner', 1000.0);
        $wedka = Fixtures::wedka($this->db, 'Wędka', 0.0);

        Fixtures::przedmiot($this->db, $wedka, 'Anchois', 'pospolita', 2.0, null, 'anchovy.png');
        Fixtures::przedmiot($this->db, $wedka, 'Jesiotr', 'niesamowita', 400.0, null, 'sturgeon.png');

        Fixtures::zarzucPosiadanaWedka($this->db, $gracz['id'], $wedka, Fixtures::scriptedRandom([0.5]));
        Fixtures::zarzucPosiadanaWedka($this->db, $gracz['id'], $wedka, Fixtures::scriptedRandom([0.99]));

        $ekwipunek = (new Ekwipunek($gracz['id'], $this->db))->lista();

        $this->assertSame('niesamowita', $ekwipunek[0]['rzadkosc_kod'], 'Pierwsza musi być ryba najrzadsza');
        $this->assertSame('pospolita', $ekwipunek[1]['rzadkosc_kod'], 'Ostatnia musi być ryba najczęstsza');
    }

    public function testPustyEkwipunek(): void
    {
        $gracz = Fixtures::user($this->db, 'nowicjusz', 100.0);

        $this->assertCount(0, (new Ekwipunek($gracz['id'], $this->db))->lista(), 'Nowy gracz nie ma jeszcze przedmiotów');

        $podsumowanie = (new Ekwipunek($gracz['id'], $this->db))->podsumowanie();

        $this->assertSame(0, $podsumowanie['liczba_przedmiotow'], 'Liczba przedmiotów u nowego gracza');
        $this->assertFloat(0.0, (float) $podsumowanie['laczna_wartosc'], 'Wartość ekwipunku u nowego gracza');
    }

    /**
     * Pomocnik: gracz, który ma już jedną złowioną rybę.
     * Wędka z dokładnie jednym przedmiotem jest deterministyczna bez wstrzykiwania
     * generatora, bo cały przedział wag [0, suma) wskazuje ten sam element.
     *
     * @return array{id: int, login: string, password: string}
     */
    private function graczZPrzedmiotem(float $saldo, float $wartoscPrzedmiotu, float $kosztUzycia): array
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', $saldo);
        $wedka = Fixtures::wedka($this->db, 'Wędka testowa', $kosztUzycia);

        Fixtures::przedmiot($this->db, $wedka, 'Anchois', 'pospolita', $wartoscPrzedmiotu, null, 'anchovy.png');

        Fixtures::zarzucPosiadanaWedka($this->db, $gracz['id'], $wedka);

        return $gracz;
    }
}
