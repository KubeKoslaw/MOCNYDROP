<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\Config;
use MocnyDrop\Wedka;
use MocnyDrop\SeedLoader;

/**
 * tests/SeedLoaderTest.php
 *
 * Testy ładowania danych startowych z database/seed/*.csv.
 *
 * Katalog seed/ należy do @moxmar26, więc backend musi być odporny na to,
 * co tam zastanie: puste wagi, literówki w kodach rzadkości, nierówne liczby
 * kolumn, ikony wskazujące nieistniejące pliki. Nic z tego nie może
 * przerwać ładowania — ma skończyć się ostrzeżeniem.
 *
 * Testy piszą CSV do katalogu tymczasowego, więc nie dotykają repozytorium.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class SeedLoaderTest extends TestCase
{
    private string $katalog = '';

    public function setUp(): void
    {
        parent::setUp();

        $this->katalog = sys_get_temp_dir() . '/mocnydrop_seed_' . getmypid() . '_' . uniqid();

        mkdir($this->katalog, 0777, true);
    }

    public function tearDown(): void
    {
        if ($this->katalog !== '') {
            foreach (glob($this->katalog . '/*') ?: [] as $plik) {
                if (is_file($plik)) {
                    unlink($plik);
                }
            }

            if (is_dir($this->katalog)) {
                rmdir($this->katalog);
            }
        }

        parent::tearDown();
    }

    public function testWczytujeWedkiPrzedmiotyIPolaczenia(): void
    {
        $this->zapiszPodstawoweCsv();

        $ostrzezenia = [];
        $wynik = SeedLoader::load($this->db, $this->katalog, $ostrzezenia);

        $this->assertSame(2, $wynik['wczytane']['wedki.csv'], 'Liczba wczytanych wędek');
        $this->assertSame(2, $wynik['wczytane']['przedmioty.csv'], 'Liczba wczytanych przedmiotów');
        $this->assertSame(3, $wynik['wczytane']['wedka_przedmioty.csv'], 'Liczba połączeń wędka-przedmiot');
        $this->assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM wedki')->fetchColumn(), 'Łowiska w bazie');
        $this->assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM przedmioty')->fetchColumn(), 'Ryby w bazie');
        $this->assertSame(3, (int) $this->db->query('SELECT COUNT(*) FROM wedka_przedmioty')->fetchColumn(), 'Połączenia w bazie');

        $stmt = $this->db->prepare('SELECT koszt_uzycia FROM wedki WHERE nazwa = ?');
        $stmt->execute(['Jezioro Ciche']);

        $this->assertFloat(10.0, (float) $stmt->fetchColumn(), 'Koszt zarzutu łowiska z CSV');
        $this->assertCount(0, $this->zawierajace($ostrzezenia, 'pominięty'), 'Poprawny seed nie powinien niczego pomijać');
    }

    /**
     * Pusta kolumna `waga` w CSV = NULL w bazie = dziedziczenie wagi domyślnej
     * poziomu rzadkości. Dzięki temu @moxmar26 nie musi przepisywać wag z README.
     */
    public function testPustaWagaDziedziczyWageDomyslna(): void
    {
        $this->zapiszPodstawoweCsv();

        SeedLoader::load($this->db, $this->katalog);

        $jezioro = $this->idLowiska('Jezioro Ciche');
        $rzeka = $this->idLowiska('Rzeka Bystra');

        $stmt = $this->db->prepare(
            'SELECT lr.waga FROM wedka_przedmioty lr
             JOIN przedmioty r ON r.id = lr.przedmiot_id
             WHERE lr.wedka_id = ? AND r.nazwa = ?'
        );
        $stmt->execute([$jezioro, 'Anchois']);

        $this->assertTrue($stmt->fetchColumn() === null, 'Pusta waga w CSV musi dać NULL w bazie, nie zero');

        $anchovis = $this->znajdzWPuli(Wedka::wymagaj($jezioro, $this->db)->pula($this->db), 'Anchois');
        $jesiotr = $this->znajdzWPuli(Wedka::wymagaj($jezioro, $this->db)->pula($this->db), 'Jesiotr');

        $this->assertFloat(50.0, (float) $anchovis['waga'], 'Anchois (Pospolita) dziedziczy wagę 50 z README');
        $this->assertFloat(1.0, (float) $jesiotr['waga'], 'Jesiotr (Niesamowita) dziedziczy wagę 1 z README');

        $rzekaAnchovis = $this->znajdzWPuli(Wedka::wymagaj($rzeka, $this->db)->pula($this->db), 'Anchois');

        $this->assertFloat(5.0, (float) $rzekaAnchovis['waga'], 'Jawna waga z CSV ma pierwszeństwo przed domyślną');
    }

    /**
     * Regresja na porzucony branch: używał poziomów "Zwykła/Rzadka/Epicka/
     * Legendarna". Aktualny README.md ma ich sześć z innymi kodami, więc stary
     * seed musi zostać odrzucony z czytelnym ostrzeżeniem, a nie wysypać bazy.
     */
    public function testOdrzucaPrzedmiotZNieznanaRzadkoscia(): void
    {
        $this->zapiszCsv('wedki.csv', "nazwa,opis,koszt_uzycia,ikona,kolejnosc\nJezioro,opis,10,,1");
        $this->zapiszCsv('przedmioty.csv', "nazwa,rzadkosc_kod,wartosc,ikona,opis\nDobra Ryba,legendarna,100,anchovy.png,opis");

        $ostrzezenia = [];
        $wynik = SeedLoader::load($this->db, $this->katalog, $ostrzezenia);

        $this->assertSame(0, $wynik['wczytane']['przedmioty.csv'], 'Ryba z kodem "legendarna" nie może się wczytać');
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM przedmioty')->fetchColumn(), 'Ryba nie może trafić do bazy');
        $this->assertCount(1, $this->zawierajace($ostrzezenia, 'legendarna'), 'Ostrzeżenie musi wymieniać odrzucony kod rzadkości');
        $this->assertCount(1, $this->zawierajace($ostrzezenia, 'pospolita'), 'Ostrzeżenie musi podpowiadać dozwolone kody');
    }

    /** CI sprawdza równość kolumn w CSV — loader musi zrobić to samo, ale per wiersz. */
    public function testPomijaWierszZNierownaLiczbaKolumn(): void
    {
        $this->zapiszCsv('wedki.csv', "nazwa,opis,koszt_uzycia,ikona,kolejnosc\nPoprawne,opis,10,,1\nZepsute,opis,10");

        $ostrzezenia = [];
        $wynik = SeedLoader::load($this->db, $this->katalog, $ostrzezenia);

        $this->assertSame(1, $wynik['wczytane']['wedki.csv'], 'Tylko poprawny wiersz może się wczytać');
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM wedki')->fetchColumn(), 'Zepsuty wiersz nie może trafić do bazy');
        $this->assertCount(1, $this->zawierajace($ostrzezenia, 'oczekiwano 5 kolumn'), 'Ostrzeżenie musi mówić o liczbie kolumn');
    }

    public function testOstrzegaOIkonieKtorejNieMa(): void
    {
        $this->zapiszCsv('przedmioty.csv', "nazwa,rzadkosc_kod,wartosc,ikona,opis\nZla Ikona,pospolita,5,brak_takiego_pliku.png,opis");

        $ostrzezenia = [];
        SeedLoader::load($this->db, $this->katalog, $ostrzezenia);

        $this->assertCount(1, $this->zawierajace($ostrzezenia, 'brak_takiego_pliku.png'), 'Literówka w nazwie ikony musi zostać zgłoszona');
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM przedmioty')->fetchColumn(), 'Brak ikony nie może blokować wczytania przedmioty');
    }

    public function testPoprawnaIkonaNieDajeOstrzezenia(): void
    {
        // Ten test jako jedyny zależy od zawartości assets/icons/ w checkout.
        if (!Config::iconExists('anchovy.png')) {
            $this->assertTrue(true, 'Pominięte: brak assets/icons/anchovy.png w kopii roboczej');

            return;
        }

        $this->zapiszCsv('przedmioty.csv', "nazwa,rzadkosc_kod,wartosc,ikona,opis\nAnchois,pospolita,5,anchovy.png,opis");

        $ostrzezenia = [];
        SeedLoader::load($this->db, $this->katalog, $ostrzezenia);

        $this->assertCount(0, $this->zawierajace($ostrzezenia, 'anchovy.png'), 'Istniejąca ikona nie może generować ostrzeżenia');
        $this->assertCount(0, $this->zawierajace($ostrzezenia, 'nie ma w'), 'Poprawna ikona nie może być zgłoszona jako brakująca');
    }

    /** Brak seedu to normalny stan — gra ma się uruchamiać, dopóki ktoś nie doda treści. */
    public function testBrakKataloguSeedNieJestBledem(): void
    {
        $ostrzezenia = [];
        $wynik = SeedLoader::load($this->db, $this->katalog . '/nie_istnieje', $ostrzezenia);

        $this->assertSame([], $wynik['wczytane'], 'Brak katalogu = brak wczytanych plików');
        $this->assertCount(1, $this->zawierajace($ostrzezenia, 'Brak katalogu seed'), 'Brak katalogu powinien zostawić jedno ostrzeżenie');
    }

    /** migrate.php można odpalać wielokrotnie — INSERT OR IGNORE musi wystarczyć. */
    public function testLadowanieJestIdempotentne(): void
    {
        $this->zapiszPodstawoweCsv();

        SeedLoader::load($this->db, $this->katalog);
        SeedLoader::load($this->db, $this->katalog);

        $this->assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM wedki')->fetchColumn(), 'Podwójne ładowanie nie może zduplikować łowisk');
        $this->assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM przedmioty')->fetchColumn(), 'Podwójne ładowanie nie może zduplikować przedmiotów');
        $this->assertSame(3, (int) $this->db->query('SELECT COUNT(*) FROM wedka_przedmioty')->fetchColumn(), 'Podwójne ładowanie nie może zduplikować połączeń');
    }

    /** CSV zapisane z polskiego Excela mają przecinek jako separator dziesiętny. */
    public function testAkceptujePolskiSeparatorDziesietny(): void
    {
        $this->zapiszCsv('wedki.csv', "nazwa,opis,koszt_uzycia,ikona,kolejnosc\nJezioro,opis,\"12,50\",,1");

        SeedLoader::load($this->db, $this->katalog);

        $this->assertFloat(12.5, (float) $this->db->query('SELECT koszt_uzycia FROM wedki')->fetchColumn(), 'Koszt "12,50" musi zostać wczytany jako 12.5');
    }

    public function testOdrzucaUjemnyKosztUzycia(): void
    {
        $this->zapiszCsv('wedki.csv', "nazwa,opis,koszt_uzycia,ikona,kolejnosc\nMinusowe,opis,-5,,1");

        $ostrzezenia = [];
        $wynik = SeedLoader::load($this->db, $this->katalog, $ostrzezenia);

        $this->assertSame(0, $wynik['wczytane']['wedki.csv'], 'Wędka z ujemnym kosztem nie może się wczytać');
        $this->assertCount(1, $this->zawierajace($ostrzezenia, 'ujemny koszt'), 'Ostrzeżenie o ujemnym koszcie');
    }

    private function zapiszPodstawoweCsv(): void
    {
        $this->zapiszCsv('wedki.csv', implode("\n", [
            'nazwa,opis,koszt_uzycia,ikona,kolejnosc',
            'Jezioro Ciche,Spokojna woda,10,,1',
            'Rzeka Bystra,Szybki nurt,25,,2',
        ]));

        $this->zapiszCsv('przedmioty.csv', implode("\n", [
            'nazwa,rzadkosc_kod,wartosc,ikona,opis',
            'Anchois,pospolita,2,anchovy.png,Mała ryba',
            'Jesiotr,niesamowita,400,sturgeon.png,Duża ryba',
        ]));

        // Pusta waga przy obu rybach Jeziora = dziedziczenie wag z README.
        $this->zapiszCsv('wedka_przedmioty.csv', implode("\n", [
            'wedka_nazwa,przedmiot_nazwa,waga',
            'Jezioro Ciche,Anchois,',
            'Jezioro Ciche,Jesiotr,',
            'Rzeka Bystra,Anchois,5',
        ]));
    }

    private function zapiszCsv(string $nazwa, string $zawartosc): void
    {
        file_put_contents($this->katalog . '/' . $nazwa, $zawartosc . "\n");
    }

    private function idLowiska(string $nazwa): int
    {
        $stmt = $this->db->prepare('SELECT id FROM wedki WHERE nazwa = ?');
        $stmt->execute([$nazwa]);

        $id = $stmt->fetchColumn();

        if ($id === false) {
            throw new AssertionFailed('Nie znaleziono łowiska "' . $nazwa . '" w bazie testowej');
        }

        return (int) $id;
    }

    /** @param list<array<string, mixed>> $pula */
    private function znajdzWPuli(array $pula, string $nazwa): array
    {
        foreach ($pula as $element) {
            if ((string) $element['nazwa'] === $nazwa) {
                return $element;
            }
        }

        throw new AssertionFailed('Nie znaleziono "' . $nazwa . '" w puli łowiska');
    }

    /**
     * Filtruje ostrzeżenia zawierające dany fragment.
     *
     * @param list<string> $ostrzezenia
     * @return list<string>
     */
    private function zawierajace(array $ostrzezenia, string $fragment): array
    {
        return array_values(array_filter(
            $ostrzezenia,
            static fn (string $ostrzezenie): bool => str_contains($ostrzezenie, $fragment)
        ));
    }
}
