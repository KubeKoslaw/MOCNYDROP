<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\ApiException;
use MocnyDrop\FishingService;
use MocnyDrop\InventoryService;
use MocnyDrop\Wallet;

/**
 * tests/InventoryServiceTest.php
 *
 * Testy ekwipunku i sprzedaży złowionych ryb.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class InventoryServiceTest extends TestCase
{
    public function testZlowionaRybaPojawiaSieWEkwipunku(): void
    {
        $gracz = $this->graczZRyba(100.0, 25.0, 0.0);

        $ekwipunek = InventoryService::listCaught($gracz['id']);

        $this->assertCount(1, $ekwipunek, 'Po jednym zarzucie w ekwipunku jest jedna ryba');

        $ryba = $ekwipunek[0];

        $this->assertSame('Anchois', $ryba['nazwa'], 'Nazwa złowionej ryby');
        $this->assertSame('Pospolita', $ryba['rzadkosc'], 'Nazwa poziomu rzadkości');
        $this->assertSame('pospolita', $ryba['rzadkosc_kod'], 'Kod poziomu rzadkości');
        $this->assertSame('#94a3b8', $ryba['kolor'], 'Kolor rzadkości dla frontendu');
        $this->assertFloat(25.0, (float) $ryba['wartosc'], 'Wartość ryby');
        $this->assertSame('assets/icons/anchovy.png', $ryba['ikona'], 'Ikona jako ścieżka URL do assets/icons/');
        $this->assertFalse($ryba['czy_sprzedana'], 'Nowo złowiona ryba nie jest sprzedana');
        $this->assertNotNull($ryba['zdobyto_kiedy'], 'Data połowu musi być zapisana');
    }

    public function testSprzedazDopisujeMonetyDoSalda(): void
    {
        $gracz = $this->graczZRyba(100.0, 25.0, 10.0);

        $ekwipunek = InventoryService::listCaught($gracz['id']);
        $saldoPrzed = Wallet::balance($this->db, $gracz['id']);

        $wynik = InventoryService::sell($gracz['id'], (int) $ekwipunek[0]['ekwipunek_id']);

        $this->assertTrue((bool) $wynik['success'], 'Udanna sprzedaż zwraca success=true');
        $this->assertFloat(25.0, (float) $wynik['otrzymane_monety'], 'Kwota ze sprzedaży');
        $this->assertFloat($saldoPrzed + 25.0, (float) $wynik['nowe_saldo'], 'Saldo po sprzedaży w odpowiedzi');
        $this->assertFloat($saldoPrzed + 25.0, Wallet::balance($this->db, $gracz['id']), 'Saldo w bazie po sprzedaży');
        $this->assertSame('Anchois', $wynik['sprzedana_ryba']['nazwa'], 'Nazwa sprzedanej ryby w odpowiedzi');
    }

    public function testSprzedanaRybaZnikaZListy(): void
    {
        $gracz = $this->graczZRyba(100.0, 25.0, 0.0);

        $ekwipunekId = (int) InventoryService::listCaught($gracz['id'])[0]['ekwipunek_id'];

        InventoryService::sell($gracz['id'], $ekwipunekId);

        $this->assertCount(0, InventoryService::listCaught($gracz['id']), 'Domyślna lista nie pokazuje ryb sprzedanych');
        $this->assertCount(1, InventoryService::listCaught($gracz['id'], true), 'Z flagą z_sprzedanymi ryba nadal jest widoczna');

        $sprzedane = InventoryService::listCaught($gracz['id'], true);

        $this->assertTrue($sprzedane[0]['czy_sprzedana'], 'Flaga sprzedaży musi być ustawiona');
        $this->assertNotNull($sprzedane[0]['sprzedano_kiedy'], 'Data sprzedaży musi zostać zapisana');
    }

    /** Podwójne kliknięcie "Sprzedaj" nie może wypłacić monet dwa razy. */
    public function testPodwojnaSprzedazJestOdrzucana(): void
    {
        $gracz = $this->graczZRyba(100.0, 25.0, 0.0);

        $ekwipunekId = (int) InventoryService::listCaught($gracz['id'])[0]['ekwipunek_id'];

        InventoryService::sell($gracz['id'], $ekwipunekId);
        $saldoPoPierwszej = Wallet::balance($this->db, $gracz['id']);

        $this->assertThrows(
            ApiException::class,
            fn (): array => InventoryService::sell($gracz['id'], $ekwipunekId),
            'Druga sprzedaż tej samej ryby powinna być odrzucona',
            'already_sold'
        );

        $this->assertFloat($saldoPoPierwszej, Wallet::balance($this->db, $gracz['id']), 'Saldo nie może wzrosnąć po drugiej sprzedaży');
    }

    /** Gracz nie może sprzedać ryby z cudzego konta, nawet znając jej id. */
    public function testSprzedazCudzejRybyJestOdrzucana(): void
    {
        $wlasciciel = $this->graczZRyba(100.0, 25.0, 0.0);
        $oszust = Fixtures::user($this->db, 'oszust', 10.0);

        $ekwipunekId = (int) InventoryService::listCaught($wlasciciel['id'])[0]['ekwipunek_id'];

        $this->assertThrows(
            ApiException::class,
            fn (): array => InventoryService::sell($oszust['id'], $ekwipunekId),
            'Sprzedaż cudzej ryby powinna być odrzucona',
            'item_not_found'
        );

        $this->assertFloat(10.0, Wallet::balance($this->db, $oszust['id']), 'Oszust nie może dostać monet');
        $this->assertCount(1, InventoryService::listCaught($wlasciciel['id']), 'Ryba musi zostać u właściciela');
    }

    public function testSprzedazNieistniejacejRybyJestOdrzucana(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);

        $this->assertThrows(
            ApiException::class,
            fn (): array => InventoryService::sell($gracz['id'], 999999),
            'Sprzedaż nieistniejącego wpisu powinna być odrzucona',
            'item_not_found'
        );
    }

    /**
     * Zmiana balansu zrobiona przez @moxmar26 nie może przecenić ryb już
     * złowionych — cena sprzedaży jest zamrożona w chwili połowu.
     */
    public function testCenaSprzedazyNieZalezyOdAktualnejWartosciRyby(): void
    {
        $gracz = $this->graczZRyba(100.0, 25.0, 0.0);

        $ekwipunekId = (int) InventoryService::listCaught($gracz['id'])[0]['ekwipunek_id'];

        // Ktoś drastycznie przecenia rybę w katalogu już po połowie.
        $this->db->exec('UPDATE ryby SET wartosc = 1.0');

        $wynik = InventoryService::sell($gracz['id'], $ekwipunekId);

        $this->assertFloat(25.0, (float) $wynik['otrzymane_monety'], 'Gracz musi dostać cenę z chwili połowu, nie aktualną z katalogu');
    }

    public function testPodsumowanieLiczyLiczbeIWartosc(): void
    {
        $gracz = Fixtures::user($this->db, 'kolekcjoner', 1000.0);
        $lowisko = Fixtures::spot($this->db, 'Łowisko', 0.0);

        Fixtures::fish($this->db, $lowisko, 'Anchois', 'pospolita', 2.0, null, 'anchovy.png');
        Fixtures::fish($this->db, $lowisko, 'Jesiotr', 'niesamowita', 400.0, null, 'sturgeon.png');

        // Dwie ryby o wagach 50 i 1, suma = 51, próg = 50/51 ≈ 0.9804.
        FishingService::cast($gracz['id'], $lowisko, Fixtures::scriptedRandom([0.5]));
        FishingService::cast($gracz['id'], $lowisko, Fixtures::scriptedRandom([0.99]));

        $podsumowanie = InventoryService::summary($gracz['id']);

        $this->assertSame(2, $podsumowanie['liczba_ryb'], 'Liczba ryb w ekwipunku');
        $this->assertFloat(402.0, (float) $podsumowanie['laczna_wartosc'], 'Łączna wartość = 2.0 + 400.0');
    }

    /** Ryby posortowane od najrzadszych — tak chce tego siatka ekwipunku. */
    public function testEkwipunekJestSortowanyOdNajrzadszych(): void
    {
        $gracz = Fixtures::user($this->db, 'kolekcjoner', 1000.0);
        $lowisko = Fixtures::spot($this->db, 'Łowisko', 0.0);

        Fixtures::fish($this->db, $lowisko, 'Anchois', 'pospolita', 2.0, null, 'anchovy.png');
        Fixtures::fish($this->db, $lowisko, 'Jesiotr', 'niesamowita', 400.0, null, 'sturgeon.png');

        FishingService::cast($gracz['id'], $lowisko, Fixtures::scriptedRandom([0.5]));
        FishingService::cast($gracz['id'], $lowisko, Fixtures::scriptedRandom([0.99]));

        $ekwipunek = InventoryService::listCaught($gracz['id']);

        $this->assertSame('niesamowita', $ekwipunek[0]['rzadkosc_kod'], 'Pierwsza musi być ryba najrzadsza');
        $this->assertSame('pospolita', $ekwipunek[1]['rzadkosc_kod'], 'Ostatnia musi być ryba najczęstsza');
    }

    public function testPustyEkwipunek(): void
    {
        $gracz = Fixtures::user($this->db, 'nowicjusz', 100.0);

        $this->assertCount(0, InventoryService::listCaught($gracz['id']), 'Nowy gracz nie ma jeszcze ryb');

        $podsumowanie = InventoryService::summary($gracz['id']);

        $this->assertSame(0, $podsumowanie['liczba_ryb'], 'Liczba ryb u nowego gracza');
        $this->assertFloat(0.0, (float) $podsumowanie['laczna_wartosc'], 'Wartość ekwipunku u nowego gracza');
    }

    /**
     * Pomocnik: gracz, który ma już jedną złowioną rybę.
     * Łowisko z dokładnie jedną rybą jest deterministyczne bez wstrzykiwania
     * generatora, bo cały przedział wag [0, suma) wskazuje ten sam element.
     *
     * @return array{id: int, login: string, password: string}
     */
    private function graczZRyba(float $saldo, float $wartoscRyby, float $kosztZarzutu): array
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', $saldo);
        $lowisko = Fixtures::spot($this->db, 'Łowisko testowe', $kosztZarzutu);

        Fixtures::fish($this->db, $lowisko, 'Anchois', 'pospolita', $wartoscRyby, null, 'anchovy.png');

        FishingService::cast($gracz['id'], $lowisko);

        return $gracz;
    }
}
