<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\ApiException;
use MocnyDrop\FishingService;
use MocnyDrop\Przedmiot;
use MocnyDrop\Rzadkosc;
use MocnyDrop\Wedka;

/**
 * tests/PrzedmiotTest.php
 *
 * Testy klasy Przedmiot z diagramu klas:
 *   - id, - nazwa, - wartosc, - rzadkosc : Rzadkosc
 *   + obliczWartosc() : float
 *   + opis() : string
 *
 * oraz asocjacji Przedmiot — Rzadkosc (*:1).
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class PrzedmiotTest extends TestCase
{
    private function przedmiot(string $kod = 'pospolita', float $wartosc = 10.0): Przedmiot
    {
        $wedkaId = Fixtures::wedka($this->db, 'Wędka', 0.0);

        return Przedmiot::wymagaj(
            Fixtures::przedmiot($this->db, $wedkaId, 'Anchois', $kod, $wartosc, null, 'anchovy.png'),
            $this->db
        );
    }

    /** Atrybut `mnoznik` z klasy Rzadkosc skaluje wartość przedmiotu. */
    public function testObliczWartoscUzywaMnoznikaRzadkosci(): void
    {
        $przedmiot = $this->przedmiot('pospolita', 10.0);

        $this->assertFloat(1.0, $przedmiot->rzadkosc->mnoznik, 'Domyślny mnożnik w schemacie to 1.0');
        $this->assertFloat(10.0, $przedmiot->obliczWartosc(), 'Przy mnożniku 1.0 wartość się nie zmienia');

        // Zmiana balansu bez redeployu: UPDATE w bazie musi od razu działać.
        $this->db->exec("UPDATE rzadkosci SET mnoznik = 2.5 WHERE kod = 'pospolita'");
        Rzadkosc::clearCache();

        $poZmianie = Przedmiot::wymagaj($przedmiot->id, $this->db);

        $this->assertFloat(2.5, $poZmianie->rzadkosc->mnoznik, 'Mnożnik czytany z bazy');
        $this->assertFloat(25.0, $poZmianie->obliczWartosc(), 'obliczWartosc() = wartosc * mnoznik');
    }

    public function testOpisZwracaTekstZBazy(): void
    {
        $wedkaId = Fixtures::wedka($this->db, 'Wędka', 0.0);

        $this->db->prepare(
            'INSERT INTO przedmioty (nazwa, rzadkosc_kod, wartosc, ikona, opis, czy_aktywny)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute(['Anchois', 'pospolita', 3.0, 'anchovy.png', 'Mała srebrzysta rybka']);

        $id = (int) $this->db->lastInsertId();
        $this->db->prepare('INSERT INTO wedka_przedmioty (wedka_id, przedmiot_id, waga) VALUES (?, ?, ?)')
            ->execute([$wedkaId, $id, null]);

        $przedmiot = Przedmiot::wymagaj($id, $this->db);

        $this->assertSame('Mała srebrzysta rybka', $przedmiot->opis(), 'opis() z diagramu zwraca opis z bazy');
        $this->assertSame('Anchois', $przedmiot->nazwa, 'Atrybut `nazwa`');
        $this->assertFloat(3.0, $przedmiot->wartosc, 'Atrybut `wartosc`');
        $this->assertSame('Pospolita', $przedmiot->rzadkosc->nazwa, 'Asocjacja Przedmiot — Rzadkosc (*:1)');
    }

    public function testIkonaUrlJestGotowaSciezka(): void
    {
        $przedmiot = $this->przedmiot();

        $this->assertSame('anchovy.png', $przedmiot->ikona, 'W encji trzyma się nazwa pliku');
        $this->assertSame('assets/icons/anchovy.png', $przedmiot->ikonaUrl(), 'API dostaje gotowy URL');
    }

    public function testDoApiMaKluczeKontraktu(): void
    {
        $api = $this->przedmiot()->doApi();

        $this->assertSame(
            ['przedmiot_id', 'nazwa', 'rzadkosc', 'rzadkosc_kod', 'kolor', 'wartosc', 'ikona', 'opis'],
            array_keys($api),
            'Dokładna lista pól przedmiotu w API'
        );

        $this->assertFalse(isset($api['haslo_hash']), 'Do API nie mogą wyciekać pola wewnętrzne');
    }

    public function testWymagajRzuca404DlaNieistniejacegoPrzedmiotu(): void
    {
        $this->assertNull(Przedmiot::znajdz(99999, $this->db), 'znajdz() zwraca null zamiast rzucać');

        $this->assertThrows(
            ApiException::class,
            fn (): Przedmiot => Przedmiot::wymagaj(99999, $this->db),
            'wymagaj() musi rzucić 404',
            'item_not_found'
        );
    }

    /**
     * Encja powstaje też z wiersza puli (WeightedRoller działa na tablicach) —
     * to ta ścieżka, którą idzie wynik każdego zarzutu.
     */
    public function testZPuliBudujeEncjeZWynikuLosowania(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 1000.0);
        $wedkaId = Fixtures::readmeWedka($this->db, 0.0);

        $wynik = FishingService::cast($gracz['id'], $wedkaId, Fixtures::scriptedRandom([0.0]), $this->db);

        $pula = Wedka::wymagaj($wedkaId, $this->db)->pula($this->db);
        $przedmiot = Przedmiot::zPuli($pula[0]);

        $this->assertTrue($przedmiot instanceof Przedmiot, 'zPuli() musi zwracać encję Przedmiot');
        $this->assertSame($pula[0]['przedmiot_id'], $przedmiot->id, 'Id przedmiotu z puli');
        $this->assertSame($pula[0]['rzadkosc_kod'], $przedmiot->rzadkosc->kod, 'Rzadkosc z puli');
        $this->assertSame(
            $wynik['przedmiot']['przedmiot_id'],
            $przedmiot->id,
            'Pierwszy element puli przy random=0.0 to wygrana z API'
        );
    }
}
