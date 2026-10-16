<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\ApiException;
use MocnyDrop\Wedka;

/**
 * tests/WedkaTest.php
 *
 * Testy klasy Wedka z diagramu klas:
 *   - id, - nazwa, - kosztUzycia, + zarzuc() : bool
 *
 * `zarzuc()` jest bramką zarzutu: wędka musi być aktywna i mieć niepustą pulę.
 * To właśnie ten warunek sprawdza FishingService przed pobraniem opłaty.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class WedkaTest extends TestCase
{
    public function testZarzucPrzechodziDlaAktywnejWedkiZPula(): void
    {
        $wedkaId = Fixtures::wedka($this->db, 'Wędka Testowa', 10.0);
        Fixtures::przedmiot($this->db, $wedkaId, 'Anchois', 'pospolita', 2.0);

        $wedka = Wedka::wymagaj($wedkaId, $this->db);

        $this->assertTrue($wedka->zarzuc(), 'Aktywna wędka z pulą musi pozwalać na zarzut');
        $this->assertSame('Wędka Testowa', $wedka->nazwa, 'Atrybut `nazwa` z diagramu');
        $this->assertFloat(10.0, $wedka->kosztUzycia, 'Atrybut `kosztUzycia` z diagramu');
        $this->assertSame($wedkaId, $wedka->id, 'Atrybut `id` z diagramu');
    }

    public function testZarzucOdrzucaWedkeBezPuli(): void
    {
        $wedkaId = Fixtures::wedka($this->db, 'Pusta Wędka', 10.0);

        $this->assertFalse(
            Wedka::wymagaj($wedkaId, $this->db)->zarzuc(),
            'Wędka bez przedmiotów nie może przyjąć zarzutu (kod rod_empty)'
        );
    }

    public function testZarzucOdrzucaNieaktywnaWedke(): void
    {
        $wedkaId = Fixtures::wedka($this->db, 'Wędka', 10.0);
        Fixtures::przedmiot($this->db, $wedkaId, 'Anchois', 'pospolita', 2.0);

        $this->db->prepare('UPDATE wedki SET czy_aktywna = 0 WHERE id = ?')->execute([$wedkaId]);

        $this->assertFalse(
            Wedka::wymagaj($wedkaId, $this->db)->zarzuc(),
            'Nieaktywna wędka musi być odrzucona nawet z pełną pulą'
        );
    }

    /** Waga NULL w seedzie = dziedziczenie waga_domyslna poziomu rzadkości. */
    public function testPulaDziedziczyWageDomyslna(): void
    {
        $wedkaId = Fixtures::wedka($this->db, 'Wędka', 10.0);
        Fixtures::przedmiot($this->db, $wedkaId, 'Anchois', 'pospolita', 2.0, null);

        $pula = Wedka::wymagaj($wedkaId, $this->db)->pula($this->db);

        $this->assertCount(1, $pula, 'Pula musi mieć jeden przedmiot');
        $this->assertFloat(50.0, (float) $pula[0]['waga'], 'Waga domyślna poziomu Pospolita (README.md)');
        $this->assertFloat(100.0, (float) $pula[0]['szansa_procent'], 'Jedyny element puli = 100% szansy');
    }

    /** Jawna waga w seedzie nadpisuje wagę domyślną. */
    public function testPulaUwzgledniaJawnaWage(): void
    {
        $wedkaId = Fixtures::wedka($this->db, 'Wędka', 10.0);
        Fixtures::przedmiot($this->db, $wedkaId, 'Anchois', 'pospolita', 2.0, 5.0);
        Fixtures::przedmiot($this->db, $wedkaId, 'Jesiotr', 'niesamowita', 400.0, 5.0);

        $pula = Wedka::wymagaj($wedkaId, $this->db)->pula($this->db);

        $this->assertFloat(5.0, (float) $pula[0]['waga'], 'Jawna waga musi wygrać z domyślną');
        $this->assertFloat(50.0, (float) $pula[0]['szansa_procent'], 'Równe wagi = 50/50');
        $this->assertFloat(50.0, (float) $pula[1]['szansa_procent'], 'Równe wagi = 50/50');
    }

    /** Przedmiot wyłączony z gry znika z puli (nie da się go wylosować). */
    public function testPulaPomijaNieaktywnePrzedmioty(): void
    {
        $wedkaId = Fixtures::wedka($this->db, 'Wędka', 10.0);
        $aktywny = Fixtures::przedmiot($this->db, $wedkaId, 'Anchois', 'pospolita', 2.0);
        $wylaczony = Fixtures::przedmiot($this->db, $wedkaId, 'Jesiotr', 'niesamowita', 400.0);

        $this->db->prepare('UPDATE przedmioty SET czy_aktywny = 0 WHERE id = ?')->execute([$wylaczony]);

        $pula = Wedka::wymagaj($wedkaId, $this->db)->pula($this->db);

        $this->assertCount(1, $pula, 'Wyłączony przedmiot musi zniknąć z puli');
        $this->assertSame($aktywny, (int) $pula[0]['przedmiot_id'], 'W puli zostaje tylko aktywny przedmiot');
    }

    public function testAktywneZwracajaTylkoAktywneWedki(): void
    {
        $pierwsza = Fixtures::wedka($this->db, 'Wędka A', 5.0, 1);
        Fixtures::przedmiot($this->db, $pierwsza, 'Anchois', 'pospolita', 2.0);

        $druga = Fixtures::wedka($this->db, 'Wędka B', 1.0, 2);
        Fixtures::przedmiot($this->db, $druga, 'Jesiotr', 'niesamowita', 400.0);

        $this->db->prepare('UPDATE wedki SET czy_aktywna = 0 WHERE id = ?')->execute([$druga]);

        $aktywne = Wedka::aktywne($this->db);

        $this->assertCount(1, $aktywne, 'Nieaktywna wędka nie może trafić na listę');
        $this->assertTrue($aktywne[0] instanceof Wedka, 'aktywne() zwraca encje Wedka, nie tablice');
        $this->assertSame('Wędka A', $aktywne[0]->nazwa, 'Nazwa aktywnej wędki');
    }

    public function testWymagajRzuca404DlaNieistniejacejWedki(): void
    {
        $this->assertNull(Wedka::znajdz(99999, $this->db), 'znajdz() zwraca null zamiast rzucać');

        $this->assertThrows(
            ApiException::class,
            fn (): Wedka => Wedka::wymagaj(99999, $this->db),
            'wymagaj() musi rzucić 404 dla nieistniejącej wędki',
            'rod_not_found'
        );
    }

    /** Kształt JSON-a, który frontend dostaje z GET api/wedki.php. */
    public function testDoApiMaKsztaltKontraktu(): void
    {
        $wedkaId = Fixtures::wedka($this->db, 'Wędka Testowa', 10.0);
        Fixtures::przedmiot($this->db, $wedkaId, 'Anchois', 'pospolita', 2.0, null, 'anchovy.png');

        $api = Wedka::wymagaj($wedkaId, $this->db)->doApi();

        $this->assertSame(
            ['czy_startowa', 'rekompensata', 'rzadkosc', 'kolor', 'id', 'nazwa', 'opis', 'koszt_uzycia', 'ikona', 'liczba_przedmiotow', 'przedmioty'],
            array_keys($api),
            'Dokładna lista pól wędki w API'
        );

        $this->assertSame(
            ['przedmiot_id', 'nazwa', 'rzadkosc', 'rzadkosc_kod', 'kolor', 'wartosc', 'ikona', 'waga', 'szansa_procent'],
            array_keys($api['przedmioty'][0]),
            'Dokładna lista pól przedmiotu w puli wędki'
        );

        $this->assertSame(
            'assets/icons/anchovy.png',
            $api['przedmioty'][0]['ikona'],
            'Ikona musi być gotowym URL-em, nie nazwą pliku'
        );
    }
}
