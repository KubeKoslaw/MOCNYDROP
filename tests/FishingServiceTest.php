<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\ApiException;
use MocnyDrop\FishingService;
use MocnyDrop\Settings;
use MocnyDrop\Wallet;

/**
 * tests/FishingServiceTest.php
 *
 * Testy zarzutu wędki — mechaniki działającej jak otwieranie skrzynki:
 * opłata, ważne losowanie, ryba do ekwipunku, wpis do historii, pasek ruletki.
 *
 * Generator losowy jest wstrzykiwany tam, gdzie test sprawdza konkretny
 * wynik. Przy testach statystycznych celowo używamy prawdziwej losowości.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class FishingServiceTest extends TestCase
{
    /** S_po = S_przed - c (wzór z README.md) — tu bez uwzględniania wygranej. */
    public function testZarzutPobieraOplateZgodnieZeWzorem(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $lowisko = Fixtures::readmeSpot($this->db, 10.0);

        $wynik = FishingService::cast($gracz['id'], $lowisko);

        $this->assertTrue((bool) $wynik['success'], 'Udany zarzut musi zwrócić success=true');
        $this->assertFloat(100.0, (float) $wynik['saldo_przed'], 'Saldo przed zarzutem');
        $this->assertFloat(90.0, (float) $wynik['saldo_po'], 'Saldo po zarzucie = 100 - 10');
        $this->assertFloat(90.0, Wallet::balance($this->db, $gracz['id']), 'Saldo w bazie musi zgadzać się z odpowiedzią API');
        $this->assertFloat(10.0, (float) $wynik['rzut']['koszt'], 'Koszt zarzutu w odpowiedzi');
    }

    public function testWylosowanaRybaTrafiaDoEkwipunku(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $lowisko = Fixtures::readmeSpot($this->db, 10.0);

        // 0.1 * 100.001 = 10.0001 → pierwszy przedział (0..50) → Anchois
        $wynik = FishingService::cast($gracz['id'], $lowisko, Fixtures::scriptedRandom([0.1]));

        $ryba = $wynik['zlowiona_ryba'];

        $this->assertSame('Anchois', $ryba['nazwa'], 'Przy random=0.1 musi wypaść ryba z pierwszego przedziału wag');
        $this->assertSame('pospolita', $ryba['rzadkosc_kod'], 'Kod rzadkości wylosowanej ryby');
        $this->assertSame('Pospolita', $ryba['rzadkosc'], 'Nazwa rzadkości musi iść do frontendu w formie czytelnej');
        $this->assertSame('assets/icons/anchovy.png', $ryba['ikona'], 'Ikona musi być ścieżką URL do pliku z assets/icons/');

        // Weryfikacja, że wpis faktycznie jest w bazie, a nie tylko w odpowiedzi.
        $stmt = $this->db->prepare('SELECT * FROM ekwipunek WHERE id = ?');
        $stmt->execute([$ryba['ekwipunek_id']]);
        $wpis = $stmt->fetch();

        $this->assertTrue($wpis !== false, 'Ryba musi zostać zapisana w ekwipunku');
        $this->assertSame($gracz['id'], (int) $wpis['user_id'], 'Wpis musi należeć do gracza, który łowił');
        $this->assertSame((int) $ryba['ryba_id'], (int) $wpis['ryba_id'], 'Wpis musi wskazywać wylosowaną rybę');
        $this->assertSame(0, (int) $wpis['czy_sprzedana'], 'Nowo złowiona ryba nie może być oznaczona jako sprzedana');
    }

    /**
     * Cena sprzedaży jest zamrażana w chwili połowu. Bez tego zmiana balansu
     * dokonana przez @moxmar26 przeceniłaby ryby, które gracze już złowili.
     */
    public function testCenaSprzedazyJestZamrazanaWChwiliPolowu(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $lowisko = Fixtures::readmeSpot($this->db, 0.0);

        $wynik = FishingService::cast($gracz['id'], $lowisko, Fixtures::scriptedRandom([0.999995]));

        $this->assertSame('ultra', $wynik['zlowiona_ryba']['rzadkosc_kod'], 'Przy random bliskim 1 musi wypaść poziom ULTRA');
        $this->assertFloat(25000.0, (float) $wynik['zlowiona_ryba']['wartosc'], 'Wartość najrzadszej ryby');
        $this->assertFloat(
            25000.0,
            (float) $this->db->query('SELECT cena_sprzedazy FROM ekwipunek')->fetchColumn(),
            'Cena sprzedaży w ekwipunku musi odpowiadać wartości ryby w momencie połowu'
        );
    }

    public function testZarzutZapisujeHistoriePolowu(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $lowisko = Fixtures::readmeSpot($this->db, 10.0);

        $wynik = FishingService::cast($gracz['id'], $lowisko, Fixtures::scriptedRandom([0.1]));

        $stmt = $this->db->prepare('SELECT * FROM historia_polowow WHERE id = ?');
        $stmt->execute([$wynik['rzut']['id']]);
        $wpis = $stmt->fetch();

        $this->assertTrue($wpis !== false, 'Każdy zarzut musi zostawić ślad w historii');
        $this->assertSame($gracz['id'], (int) $wpis['user_id'], 'Historia: gracz');
        $this->assertSame($lowisko, (int) $wpis['lowisko_id'], 'Historia: łowisko');
        $this->assertFloat(10.0, (float) $wpis['koszt'], 'Historia: koszt zarzutu');
        $this->assertFloat(2.0, (float) $wpis['wygrana_wartosc'], 'Historia: wartość złowionej ryby');
        $this->assertFloat(100.001, (float) $wpis['suma_wag'], 'Historia: suma wag puli z README.md');
        $this->assertFloat(50.0, (float) $wpis['waga_wylosowana'], 'Historia: waga wylosowanej ryby');
    }

    /** Historia musi pozwalać odtworzyć P(i) dokładnie tak, jak było w chwili rzutu. */
    public function testSzansaProcentWynikaZWagPuli(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $lowisko = Fixtures::readmeSpot($this->db, 10.0);

        // 0.9 * 100.001 = 90.0009 → przedziały narastająco 50, 80, 95 → wpada w 80..95
        $wynik = FishingService::cast($gracz['id'], $lowisko, Fixtures::scriptedRandom([0.9]));

        $this->assertSame('niezla', $wynik['zlowiona_ryba']['rzadkosc_kod'], 'Ryba z przedziału 80..95 to poziom Niezła');
        $this->assertFloat(15.0, (float) $wynik['zlowiona_ryba']['szansa_procent'], 'Szansa dla wagi 15 przy sumie ~100');
        $this->assertSame('assets/icons/smallmouth_bass.png', $wynik['zlowiona_ryba']['ikona'], 'Ikona ryby poziomu Niezła');
    }

    /** Brak środków musi zostawić bazę dokładnie w takim stanie, jak przed zarzutem. */
    public function testBrakSrodkowNiePobieraOplat(): void
    {
        $gracz = Fixtures::user($this->db, 'biedak', 5.0);
        $lowisko = Fixtures::readmeSpot($this->db, 10.0);

        $this->assertThrows(
            ApiException::class,
            static fn (): array => FishingService::cast($gracz['id'], $lowisko),
            'Zarzut bez środków powinien zostać odrzucony',
            'insufficient_funds'
        );

        $this->assertFloat(5.0, Wallet::balance($this->db, $gracz['id']), 'Saldo nie może się zmienić po nieudanym zarzucie');
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM ekwipunek')->fetchColumn(), 'Nieudany zarzut nie może dodać ryby');
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM historia_polowow')->fetchColumn(), 'Nieudany zarzut nie może zapisać historii');
    }

    public function testSaldoNigdyNieSchodziPonizejZera(): void
    {
        $gracz = Fixtures::user($this->db, 'biedak', 10.0);
        $lowisko = Fixtures::readmeSpot($this->db, 10.0);

        FishingService::cast($gracz['id'], $lowisko, Fixtures::scriptedRandom([0.1]));

        $this->assertFloat(0.0, Wallet::balance($this->db, $gracz['id']), 'Po zarzucie za dokładnie całe saldo zostaje 0');

        $this->assertThrows(
            ApiException::class,
            static fn (): array => FishingService::cast($gracz['id'], $lowisko),
            'Drugi zarzut przy zerowym saldzie powinien być odrzucony',
            'insufficient_funds'
        );

        $this->assertFloat(0.0, Wallet::balance($this->db, $gracz['id']), 'Saldo musi zostać zerem, nie liczbą ujemną');
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM ekwipunek')->fetchColumn(), 'Tylko pierwszy zarzut mógł się udać');
    }

    public function testDarmoweLowiskoNieZmieniaSalda(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 77.0);
        $lowisko = Fixtures::readmeSpot($this->db, 0.0);

        $wynik = FishingService::cast($gracz['id'], $lowisko, Fixtures::scriptedRandom([0.1]));

        $this->assertFloat(77.0, (float) $wynik['saldo_po'], 'Darmowy zarzut nie może pobrać opłaty');
        $this->assertFloat(77.0, Wallet::balance($this->db, $gracz['id']), 'Saldo w bazie po darmowym zarzucie');
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM ekwipunek')->fetchColumn(), 'Darmowy zarzut też musi dać rybę');
    }

    public function testNieistniejaceLowiskoRzuca404(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);

        Fixtures::readmeSpot($this->db, 10.0);

        $this->assertThrows(
            ApiException::class,
            static fn (): array => FishingService::cast($gracz['id'], 9999),
            'Zarzut na nieistniejącym łowisku powinien być odrzucony',
            'spot_not_found'
        );

        $this->assertFloat(100.0, Wallet::balance($this->db, $gracz['id']), 'Nieudany zarzut nie może pobrać opłaty');
    }

    public function testLowiskoBezRybRzucaBlad(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $pusteLowisko = Fixtures::spot($this->db, 'Puste łowisko', 10.0);

        $this->assertThrows(
            ApiException::class,
            static fn (): array => FishingService::cast($gracz['id'], $pusteLowisko),
            'Zarzut na łowisku bez ryb powinien być odrzucony',
            'spot_empty'
        );

        $this->assertFloat(100.0, Wallet::balance($this->db, $gracz['id']), 'Walidacja puli musi nastąpić PRZED pobraniem opłaty');
    }

    /**
     * Ryby wyłączone z gry muszą znikać z puli, ale łowisko nie może przez to
     * nagle zacząć pobierać opłaty za pusty rzut.
     */
    public function testDezaktywowanaRybaZnikaZPuli(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $lowisko = Fixtures::spot($this->db, 'Jedno rybowe łowisko', 10.0);

        $rybaId = Fixtures::fish($this->db, $lowisko, 'Anchois', 'pospolita', 2.0, null, 'anchovy.png');

        $this->db->prepare('UPDATE ryby SET czy_aktywna = 0 WHERE id = ?')->execute([$rybaId]);

        $this->assertThrows(
            ApiException::class,
            static fn (): array => FishingService::cast($gracz['id'], $lowisko),
            'Łowisko z samymi wyłączonymi rybami powinno być traktowane jak puste',
            'spot_empty'
        );
    }

    public function testNieaktywneLowiskoJestOdrzucane(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $lowisko = Fixtures::readmeSpot($this->db, 10.0);

        $this->db->prepare('UPDATE lowiska SET czy_aktywne = 0 WHERE id = ?')->execute([$lowisko]);

        $this->assertThrows(
            ApiException::class,
            static fn (): array => FishingService::cast($gracz['id'], $lowisko),
            'Zamknięte łowisko nie może przyjmować zarzutów',
            'spot_inactive'
        );

        $this->assertFloat(100.0, Wallet::balance($this->db, $gracz['id']), 'Zamknięte łowisko nie może pobrać opłaty');
    }

    /** Kontrakt z @jgpostrach: animacja zatrzymuje się na reel_indeks_wygranej. */
    public function testReelMaWygranaNaWskazanymIndeksie(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $lowisko = Fixtures::readmeSpot($this->db, 10.0);

        $wynik = FishingService::cast($gracz['id'], $lowisko, Fixtures::scriptedRandom([0.9]));

        $this->assertSame(60, (int) $wynik['reel_dlugosc'], 'Domyślna długość paska z ustawień');
        $this->assertSame(54, (int) $wynik['reel_indeks_wygranej'], 'Domyślny indeks wygranej z ustawień');
        $this->assertCount(60, $wynik['reel'], 'Pasek musi mieć tyle pozycji, ile deklaruje reel_dlugosc');

        $indeks = (int) $wynik['reel_indeks_wygranej'];

        $this->assertSame(
            $wynik['zlowiona_ryba']['ryba_id'],
            $wynik['reel'][$indeks]['ryba_id'],
            'Pod indeksem wygranej musi leżeć TA SAMA ryba, którą zwróciło zlowiona_ryba'
        );
        $this->assertSame(
            $wynik['zlowiona_ryba']['nazwa'],
            $wynik['reel'][$indeks]['nazwa'],
            'Nazwa na pasku musi zgadzać się z wynikiem losowania'
        );
    }

    public function testReelReagujeNaZmianeUstawien(): void
    {
        Fixtures::setting($this->db, Settings::KEY_REEL_LENGTH, '12');
        Fixtures::setting($this->db, Settings::KEY_REEL_WINNER_INDEX, '3');

        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $lowisko = Fixtures::readmeSpot($this->db, 10.0);

        $wynik = FishingService::cast($gracz['id'], $lowisko, Fixtures::scriptedRandom([0.1]));

        $this->assertSame(12, (int) $wynik['reel_dlugosc'], 'Długość paska musi brać się z ustawień, nie ze stałej w kodzie');
        $this->assertSame(3, (int) $wynik['reel_indeks_wygranej'], 'Indeks wygranej musi brać się z ustawień');
        $this->assertCount(12, $wynik['reel'], 'Pasek musi mieć długość z ustawień');
        $this->assertSame($wynik['zlowiona_ryba']['ryba_id'], $wynik['reel'][3]['ryba_id'], 'Wygrana na nowym indeksie');
    }

    /**
     * Błędna konfiguracja nie może wysypać gry — indeks poza paskiem jest
     * przycinany do ostatniej pozycji zamiast rzucać wyjątkiem.
     */
    public function testReelPrzycinaIndeksPozaZakresem(): void
    {
        Fixtures::setting($this->db, Settings::KEY_REEL_LENGTH, '5');
        Fixtures::setting($this->db, Settings::KEY_REEL_WINNER_INDEX, '99');

        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $lowisko = Fixtures::readmeSpot($this->db, 10.0);

        $wynik = FishingService::cast($gracz['id'], $lowisko, Fixtures::scriptedRandom([0.1]));

        $this->assertCount(5, $wynik['reel'], 'Długość paska po przycięciu');
        $this->assertSame(4, (int) $wynik['reel_indeks_wygranej'], 'Indeks musi zostać przycięty do ostatniej pozycji paska');
        $this->assertSame($wynik['zlowiona_ryba']['ryba_id'], $wynik['reel'][4]['ryba_id'], 'Wygrana na przyciętym indeksie');
    }

    /**
     * Waga wpisana jawnie w lowisko_ryby ma pierwszeństwo przed wagą domyślną
     * poziomu rzadkości. To mechanizm, którym @moxmar26 różnicuje łowiska
     * (np. na "Głębi" ryby mityczne są częstsze niż na "Płyciźnie").
     */
    public function testWagaNadpisanaWLowiskuMaPierwszenstwo(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 1000.0);
        $lowisko = Fixtures::spot($this->db, 'Łowisko z nadwagą', 0.0);

        Fixtures::fish($this->db, $lowisko, 'Ryba Rzadsza', 'pospolita', 5.0, null, 'anchovy.png');
        Fixtures::fish($this->db, $lowisko, 'Ryba Czestsza', 'pospolita', 10.0, 150.0, 'brown_trout.png');

        // Obie są poziomu "pospolita", ale wagi to 50 (domyślna) i 150 (nadpisana).
        // Suma = 200, więc progi: 0..50 -> Rzadsza, 50..200 -> Czestsza.
        $rzadsza = FishingService::cast($gracz['id'], $lowisko, Fixtures::scriptedRandom([0.1]));
        $czestsza = FishingService::cast($gracz['id'], $lowisko, Fixtures::scriptedRandom([0.9]));

        $this->assertSame('Ryba Rzadsza', $rzadsza['zlowiona_ryba']['nazwa'], 'random=0.1 (pick=20) musi trafić w wagę domyślną 50');
        $this->assertFloat(25.0, (float) $rzadsza['zlowiona_ryba']['szansa_procent'], 'Szansa 50/200 = 25%');

        $this->assertSame('Ryba Czestsza', $czestsza['zlowiona_ryba']['nazwa'], 'random=0.9 (pick=180) musi trafić w wagę nadpisaną 150');
        $this->assertFloat(75.0, (float) $czestsza['zlowiona_ryba']['szansa_procent'], 'Szansa 150/200 = 75%');
    }

    /**
     * Test end-to-end całego łańcucha: opłata -> losowanie -> ekwipunek ->
     * historia. Rozkład musi odtwarzać wagi z README.md.
     */
    public function testRozkladPolowowZgadnaSieZWagami(): void
    {
        $gracz = Fixtures::user($this->db, 'statystyk', 1000000.0);
        $lowisko = Fixtures::spot($this->db, 'Łowisko statystyczne', 0.0);

        // Poziom "ultra" pomijamy celowo: przy wadze 0.001 trafilibyśmy go
        // statystycznie raz na kilka tysięcy prób, a taki test byłby szumem.
        $oczekiwane = [
            'pospolita' => ['procent' => 50.0, 'ikona' => 'anchovy.png'],
            'przecietna' => ['procent' => 30.0, 'ikona' => 'brown_trout.png'],
            'niezla' => ['procent' => 15.0, 'ikona' => 'smallmouth_bass.png'],
            'mityczna' => ['procent' => 4.0, 'ikona' => 'blue_marlin.png'],
            'niesamowita' => ['procent' => 1.0, 'ikona' => 'sturgeon.png'],
        ];

        foreach ($oczekiwane as $kod => $dane) {
            Fixtures::fish($this->db, $lowisko, 'Ryba ' . $kod, $kod, $dane['procent'], null, $dane['ikona']);
        }

        $licznik = array_fill_keys(array_keys($oczekiwane), 0);
        $proby = 15000;

        for ($n = 0; $n < $proby; $n++) {
            $licznik[FishingService::cast($gracz['id'], $lowisko)['zlowiona_ryba']['rzadkosc_kod']]++;
        }

        $this->assertSame($proby, array_sum($licznik), 'Każdy zarzut musi dać dokładnie jedną rybę');
        $this->assertSame($proby, (int) $this->db->query('SELECT COUNT(*) FROM ekwipunek')->fetchColumn(), 'Liczba ryb w ekwipunku = liczba zarzutów');
        $this->assertSame($proby, (int) $this->db->query('SELECT COUNT(*) FROM historia_polowow')->fetchColumn(), 'Liczba wpisów historii = liczba zarzutów');

        foreach ($oczekiwane as $kod => $dane) {
            $oczekiwanaLiczba = $proby * ($dane['procent'] / 100.0);

            // Tolerancja 30%: przy 15000 prób i najrzadszym poziomie (1%, czyli
            // 150 trafień) to ponad 3 sigmy, więc test jest stabilny, a zepsuty
            // balans (np. losowanie jednostajne po 20%) i tak go wywali.
            $this->assertBetween(
                $oczekiwanaLiczba * 0.7,
                $oczekiwanaLiczba * 1.3,
                (float) $licznik[$kod],
                sprintf('Rozkład połowów dla "%s" (%.1f%% z %d zarzutów)', $kod, $dane['procent'], $proby)
            );
        }
    }
}
