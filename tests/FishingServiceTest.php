<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\ApiException;
use MocnyDrop\Ekwipunek;
use MocnyDrop\FishingService;
use MocnyDrop\Settings;
use MocnyDrop\Portfel;

final class FishingServiceTest extends TestCase
{

    public function testZarzutZuzywaZarzutWedkiINiePobieraMonet(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $wedka = Fixtures::readmeWedka($this->db, 10.0);
        $wpisWedki = Fixtures::dajWedke($this->db, $gracz['id'], $wedka, 3);

        $wynik = FishingService::cast($gracz['id'], $wedka, Fixtures::scriptedRandom([0.1]));

        $this->assertTrue((bool) $wynik['success'], 'Udany zarzut musi zwrócić success=true');
        $this->assertFloat(100.0, (float) $wynik['saldo_po'], 'Zarzut nie pobiera monet');
        $this->assertFloat(100.0, Portfel::dlaUzytkownika($gracz['id'], $this->db)->saldo, 'Saldo w bazie bez zmian');
        $this->assertFloat(10.0, (float) $wynik['rzut']['koszt'], 'Koszt zarzutu = wartość jednego zarzutu wędki');
        $this->assertSame(2, (int) $wynik['wedka']['zarzuty_pozostale'], 'Odpowiedź podaje pozostałe zarzuty');

        $stmt = $this->db->prepare('SELECT zarzuty_pozostale, wartosc FROM ekwipunek WHERE id = ?');
        $stmt->execute([$wpisWedki]);
        $wpis = $stmt->fetch();

        $this->assertSame(2, (int) $wpis['zarzuty_pozostale'], 'Licznik zarzutów w ekwipunku maleje');
        $this->assertFloat(20.0, (float) $wpis['wartosc'], 'Wartość wędki maleje o wartość zarzutu');
    }

    public function testBezWedkiNieMoznaLowic(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $wedka = Fixtures::readmeWedka($this->db, 10.0);

        $this->assertThrows(
            ApiException::class,
            static fn (): array => FishingService::cast($gracz['id'], $wedka),
            'Bez wędki w ekwipunku zarzut jest odrzucany',
            'rod_not_owned'
        );

        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM ekwipunek')->fetchColumn(), 'Nieudany zarzut nie może dodać przedmiotu');
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM historia_losowan')->fetchColumn(), 'Nieudany zarzut nie może zapisać historii');
    }

    public function testOstatniZarzutUsuwaWedkeZEkwipunku(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $wedka = Fixtures::readmeWedka($this->db, 10.0);
        $wpisWedki = Fixtures::dajWedke($this->db, $gracz['id'], $wedka, 1);

        FishingService::cast($gracz['id'], $wedka, Fixtures::scriptedRandom([0.1]));

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM ekwipunek WHERE id = ?');
        $stmt->execute([$wpisWedki]);

        $this->assertSame(0, (int) $stmt->fetchColumn(), 'Wędka bez zarzutów znika z ekwipunku');

        $this->assertThrows(
            ApiException::class,
            static fn (): array => FishingService::cast($gracz['id'], $wedka),
            'Po wyczerpaniu zarzutów nie można dalej łowić',
            'rod_not_owned'
        );

        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM ekwipunek')->fetchColumn(), 'Została tylko złowiona ryba');
    }

    public function testNajpierwZuzywaNajbardziejZuzytaSztuke(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $wedka = Fixtures::readmeWedka($this->db, 10.0);
        $nowa = Fixtures::dajWedke($this->db, $gracz['id'], $wedka, 5);
        $zuzyta = Fixtures::dajWedke($this->db, $gracz['id'], $wedka, 2);

        $wynik = FishingService::cast($gracz['id'], $wedka, Fixtures::scriptedRandom([0.1]));

        $zarzuty = static fn (\PDO $db, int $id): int => (int) $db->query('SELECT zarzuty_pozostale FROM ekwipunek WHERE id = ' . $id)->fetchColumn();

        $this->assertSame(1, $zarzuty($this->db, $zuzyta), 'Zarzut zszedł z bardziej zużytej wędki');
        $this->assertSame(5, $zarzuty($this->db, $nowa), 'Nowa wędka nietknięta');
        $this->assertSame(6, (int) $wynik['wedka']['zarzuty_pozostale'], 'Łącznie zostało 6 zarzutów');
    }

    public function testWylosowanyPrzedmiotTrafiaDoEkwipunku(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $wedka = Fixtures::readmeWedka($this->db, 10.0);
        Fixtures::dajWedke($this->db, $gracz['id'], $wedka);

        $wynik = FishingService::cast($gracz['id'], $wedka, Fixtures::scriptedRandom([0.1]));

        $ryba = $wynik['przedmiot'];

        $this->assertSame('Anchois', $ryba['nazwa'], 'Przy random=0.1 musi wypaść ryba z pierwszego przedziału wag');
        $this->assertSame('pospolita', $ryba['rzadkosc_kod'], 'Kod rzadkości wylosowanej przedmioty');
        $this->assertSame('Pospolita', $ryba['rzadkosc'], 'Nazwa rzadkości musi iść do frontendu w formie czytelnej');
        $this->assertSame('assets/icons/anchovy.png', $ryba['ikona'], 'Ikona musi być ścieżką URL do pliku z assets/icons/');

        $stmt = $this->db->prepare('SELECT * FROM ekwipunek WHERE id = ?');
        $stmt->execute([$ryba['ekwipunek_id']]);
        $wpis = $stmt->fetch();

        $this->assertTrue($wpis !== false, 'Przedmiot musi zostać zapisany w ekwipunku');
        $this->assertSame($gracz['id'], (int) $wpis['user_id'], 'Wpis musi należeć do gracza, który łowił');
        $this->assertSame((int) $ryba['przedmiot_id'], (int) $wpis['przedmiot_id'], 'Wpis musi wskazywać wylosowaną rybę');
        $this->assertSame(0, (int) $wpis['sprzedany'], 'Nowo złowiona ryba nie może być oznaczona jako sprzedana');
    }

    public function testWartoscJestZamrazanaWChwiliNabycia(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $wedka = Fixtures::readmeWedka($this->db, 0.0);
        Fixtures::dajWedke($this->db, $gracz['id'], $wedka);

        $wynik = FishingService::cast($gracz['id'], $wedka, Fixtures::scriptedRandom([0.999995]));

        $this->assertSame('ultra', $wynik['przedmiot']['rzadkosc_kod'], 'Przy random bliskim 1 musi wypaść poziom ULTRA');
        $this->assertFloat(25000.0, (float) $wynik['przedmiot']['wartosc'], 'Wartość najrzadszego przedmiotu');
        $this->assertFloat(
            25000.0,
            (float) $this->db->query('SELECT wartosc FROM ekwipunek')->fetchColumn(),
            'Wartość w ekwipunku musi odpowiadać wartości przedmiotu w momencie nabycia'
        );
    }

    public function testZarzutZapisujeHistorieLosowan(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $wedka = Fixtures::readmeWedka($this->db, 10.0);
        Fixtures::dajWedke($this->db, $gracz['id'], $wedka);

        $wynik = FishingService::cast($gracz['id'], $wedka, Fixtures::scriptedRandom([0.1]));

        $stmt = $this->db->prepare('SELECT * FROM historia_losowan WHERE id = ?');
        $stmt->execute([$wynik['rzut']['id']]);
        $wpis = $stmt->fetch();

        $this->assertTrue($wpis !== false, 'Każdy zarzut musi zostawić ślad w historii');
        $this->assertSame($gracz['id'], (int) $wpis['user_id'], 'Historia: gracz');
        $this->assertSame($wedka, (int) $wpis['wedka_id'], 'Historia: wędka');
        $this->assertFloat(10.0, (float) $wpis['koszt'], 'Historia: koszt zarzutu');
        $this->assertFloat(2.0, (float) $wpis['wygrana_wartosc'], 'Historia: wartość złowionej przedmioty');
        $this->assertFloat(100.001, (float) $wpis['suma_wag'], 'Historia: suma wag puli z README.md');
        $this->assertFloat(50.0, (float) $wpis['waga_wylosowana'], 'Historia: waga wylosowanej przedmioty');
    }

    public function testSzansaProcentWynikaZWagPuli(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $wedka = Fixtures::readmeWedka($this->db, 10.0);
        Fixtures::dajWedke($this->db, $gracz['id'], $wedka);

        $wynik = FishingService::cast($gracz['id'], $wedka, Fixtures::scriptedRandom([0.9]));

        $this->assertSame('niezla', $wynik['przedmiot']['rzadkosc_kod'], 'Przedmiot z przedziału 80..95 to poziom Niezła');
        $this->assertFloat(15.0, (float) $wynik['przedmiot']['szansa_procent'], 'Szansa dla wagi 15 przy sumie ~100');
        $this->assertSame('assets/icons/smallmouth_bass.png', $wynik['przedmiot']['ikona'], 'Ikona przedmioty poziomu Niezła');
    }

    public function testZarzutDzialaPrzyZerowymSaldzie(): void
    {
        $gracz = Fixtures::user($this->db, 'biedak', 0.0);
        $wedka = Fixtures::readmeWedka($this->db, 10.0);
        Fixtures::dajWedke($this->db, $gracz['id'], $wedka);

        $wynik = FishingService::cast($gracz['id'], $wedka, Fixtures::scriptedRandom([0.1]));

        $this->assertFloat(0.0, (float) $wynik['saldo_po'], 'Saldo bez zmian');
        $this->assertCount(1, (new Ekwipunek($gracz['id'], $this->db))->lista(), 'Ryba trafia do ekwipunku');
    }

    public function testNieistniejaceLowiskoRzuca404(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);

        Fixtures::readmeWedka($this->db, 10.0);

        $this->assertThrows(
            ApiException::class,
            static fn (): array => FishingService::cast($gracz['id'], 9999),
            'Zarzut na nieistniejącym łowisku powinien być odrzucony',
            'rod_not_found'
        );

    }

    public function testLowiskoBezRybRzucaBlad(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $pustaWedka = Fixtures::wedka($this->db, 'Pusta wędka', 10.0);

        $this->assertThrows(
            ApiException::class,
            static fn (): array => FishingService::cast($gracz['id'], $pustaWedka),
            'Zarzut na wędce bez przedmiotów powinien być odrzucony',
            'rod_empty'
        );

        $this->assertFloat(100.0, Portfel::dlaUzytkownika($gracz['id'], $this->db)->saldo, 'Walidacja puli musi nastąpić PRZED pobraniem opłaty');
    }

    public function testDezaktywowanyPrzedmiotZnikaZPuli(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $wedka = Fixtures::wedka($this->db, 'Wędka z jednym przedmiotem', 10.0);

        $przedmiotId = Fixtures::przedmiot($this->db, $wedka, 'Anchois', 'pospolita', 2.0, null, 'anchovy.png');

        $this->db->prepare('UPDATE przedmioty SET czy_aktywny = 0 WHERE id = ?')->execute([$przedmiotId]);

        $this->assertThrows(
            ApiException::class,
            static fn (): array => FishingService::cast($gracz['id'], $wedka),
            'Wędka z samymi wyłączonymi przedmiotami powinna być traktowana jak pusta',
            'rod_empty'
        );
    }

    public function testNieaktywnaWedkaJestOdrzucana(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $wedka = Fixtures::readmeWedka($this->db, 10.0);

        $this->db->prepare('UPDATE wedki SET czy_aktywna = 0 WHERE id = ?')->execute([$wedka]);

        $this->assertThrows(
            ApiException::class,
            static fn (): array => FishingService::cast($gracz['id'], $wedka),
            'Nieaktywna wędka nie może przyjmować zarzutów',
            'rod_inactive'
        );

        $this->assertFloat(100.0, Portfel::dlaUzytkownika($gracz['id'], $this->db)->saldo, 'Nieaktywna wędka nie może pobrać opłaty');
    }

    public function testReelMaWygranaNaWskazanymIndeksie(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $wedka = Fixtures::readmeWedka($this->db, 10.0);
        Fixtures::dajWedke($this->db, $gracz['id'], $wedka);

        $wynik = FishingService::cast($gracz['id'], $wedka, Fixtures::scriptedRandom([0.9]));

        $this->assertSame(60, (int) $wynik['reel_dlugosc'], 'Domyślna długość paska z ustawień');
        $this->assertSame(54, (int) $wynik['reel_indeks_wygranej'], 'Domyślny indeks wygranej z ustawień');
        $this->assertCount(60, $wynik['reel'], 'Pasek musi mieć tyle pozycji, ile deklaruje reel_dlugosc');

        $indeks = (int) $wynik['reel_indeks_wygranej'];

        $this->assertSame(
            $wynik['przedmiot']['przedmiot_id'],
            $wynik['reel'][$indeks]['przedmiot_id'],
            'Pod indeksem wygranej musi leżeć TA SAMA ryba, którą zwróciło pole `przedmiot`'
        );
        $this->assertSame(
            $wynik['przedmiot']['nazwa'],
            $wynik['reel'][$indeks]['nazwa'],
            'Nazwa na pasku musi zgadzać się z wynikiem losowania'
        );
    }

    public function testReelReagujeNaZmianeUstawien(): void
    {
        Fixtures::setting($this->db, Settings::KEY_REEL_LENGTH, '12');
        Fixtures::setting($this->db, Settings::KEY_REEL_WINNER_INDEX, '3');

        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $wedka = Fixtures::readmeWedka($this->db, 10.0);
        Fixtures::dajWedke($this->db, $gracz['id'], $wedka);

        $wynik = FishingService::cast($gracz['id'], $wedka, Fixtures::scriptedRandom([0.1]));

        $this->assertSame(12, (int) $wynik['reel_dlugosc'], 'Długość paska musi brać się z ustawień, nie ze stałej w kodzie');
        $this->assertSame(3, (int) $wynik['reel_indeks_wygranej'], 'Indeks wygranej musi brać się z ustawień');
        $this->assertCount(12, $wynik['reel'], 'Pasek musi mieć długość z ustawień');
        $this->assertSame($wynik['przedmiot']['przedmiot_id'], $wynik['reel'][3]['przedmiot_id'], 'Wygrana na nowym indeksie');
    }

    public function testReelPrzycinaIndeksPozaZakresem(): void
    {
        Fixtures::setting($this->db, Settings::KEY_REEL_LENGTH, '5');
        Fixtures::setting($this->db, Settings::KEY_REEL_WINNER_INDEX, '99');

        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $wedka = Fixtures::readmeWedka($this->db, 10.0);
        Fixtures::dajWedke($this->db, $gracz['id'], $wedka);

        $wynik = FishingService::cast($gracz['id'], $wedka, Fixtures::scriptedRandom([0.1]));

        $this->assertCount(5, $wynik['reel'], 'Długość paska po przycięciu');
        $this->assertSame(4, (int) $wynik['reel_indeks_wygranej'], 'Indeks musi zostać przycięty do ostatniej pozycji paska');
        $this->assertSame($wynik['przedmiot']['przedmiot_id'], $wynik['reel'][4]['przedmiot_id'], 'Wygrana na przyciętym indeksie');
    }

    public function testWagaNadpisanaWLowiskuMaPierwszenstwo(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 1000.0);
        $wedka = Fixtures::wedka($this->db, 'Łowisko z nadwagą', 0.0);

        Fixtures::przedmiot($this->db, $wedka, 'Ryba Rzadsza', 'pospolita', 5.0, null, 'anchovy.png');
        Fixtures::przedmiot($this->db, $wedka, 'Ryba Czestsza', 'pospolita', 10.0, 150.0, 'brown_trout.png');

        Fixtures::dajWedke($this->db, $gracz['id'], $wedka, 2);

        $rzadsza = FishingService::cast($gracz['id'], $wedka, Fixtures::scriptedRandom([0.1]));
        $czestsza = FishingService::cast($gracz['id'], $wedka, Fixtures::scriptedRandom([0.9]));

        $this->assertSame('Ryba Rzadsza', $rzadsza['przedmiot']['nazwa'], 'random=0.1 (pick=20) musi trafić w wagę domyślną 50');
        $this->assertFloat(25.0, (float) $rzadsza['przedmiot']['szansa_procent'], 'Szansa 50/200 = 25%');

        $this->assertSame('Ryba Czestsza', $czestsza['przedmiot']['nazwa'], 'random=0.9 (pick=180) musi trafić w wagę nadpisaną 150');
        $this->assertFloat(75.0, (float) $czestsza['przedmiot']['szansa_procent'], 'Szansa 150/200 = 75%');
    }

    public function testRozkladPolowowZgadnaSieZWagami(): void
    {
        $gracz = Fixtures::user($this->db, 'statystyk', 1000000.0);
        $wedka = Fixtures::wedka($this->db, 'Łowisko statystyczne', 0.0);

        $oczekiwane = [
            'pospolita' => ['procent' => 50.0, 'ikona' => 'anchovy.png'],
            'przecietna' => ['procent' => 30.0, 'ikona' => 'brown_trout.png'],
            'niezla' => ['procent' => 15.0, 'ikona' => 'smallmouth_bass.png'],
            'mityczna' => ['procent' => 4.0, 'ikona' => 'blue_marlin.png'],
            'niesamowita' => ['procent' => 1.0, 'ikona' => 'sturgeon.png'],
        ];

        foreach ($oczekiwane as $kod => $dane) {
            Fixtures::przedmiot($this->db, $wedka, 'Ryba ' . $kod, $kod, $dane['procent'], null, $dane['ikona']);
        }

        Fixtures::dajWedke($this->db, $gracz['id'], $wedka, $proby = 15000);

        $licznik = array_fill_keys(array_keys($oczekiwane), 0);

        for ($n = 0; $n < $proby; $n++) {
            $licznik[FishingService::cast($gracz['id'], $wedka)['przedmiot']['rzadkosc_kod']]++;
        }

        $this->assertSame($proby, array_sum($licznik), 'Każdy zarzut musi dać dokładnie jedną rybę');
        $this->assertSame($proby, (int) $this->db->query('SELECT COUNT(*) FROM ekwipunek')->fetchColumn(), 'Liczba przedmiotów w ekwipunku = liczba zarzutów');
        $this->assertSame($proby, (int) $this->db->query('SELECT COUNT(*) FROM historia_losowan')->fetchColumn(), 'Liczba wpisów historii = liczba zarzutów');

        foreach ($oczekiwane as $kod => $dane) {
            $oczekiwanaLiczba = $proby * ($dane['procent'] / 100.0);

            $this->assertBetween(
                $oczekiwanaLiczba * 0.7,
                $oczekiwanaLiczba * 1.3,
                (float) $licznik[$kod],
                sprintf('Rozkład połowów dla "%s" (%.1f%% z %d zarzutów)', $kod, $dane['procent'], $proby)
            );
        }
    }
}
