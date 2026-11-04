<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\Ekwipunek;
use MocnyDrop\FishingService;
use MocnyDrop\Przedmiot;
use MocnyDrop\Skrzynka;
use MocnyDrop\Uzytkownik;
use MocnyDrop\Wedka;

final class WedkiZeSkrzynekTest extends TestCase
{

    private function wedka(string $nazwa = 'Wędka Testowa', int $limit = 5, int $kolejnosc = 0): Wedka
    {
        $id = Fixtures::wedka($this->db, $nazwa, 10.0, $kolejnosc);
        $this->db->prepare('UPDATE wedki SET limit_zarzutow = ? WHERE id = ?')->execute([$limit, $id]);
        Fixtures::przedmiot($this->db, $id, 'Ryba ' . $nazwa, 'pospolita', 4.0, null, 'anchovy.png');

        Wedka::wymagaj($id, $this->db)->utworzPrzedmiot('niezla', $this->db);

        return Wedka::wymagaj($id, $this->db);
    }

    public function testPrzedmiotWedkiMaWartoscZarzutowRazyLimit(): void
    {
        $wedka = $this->wedka('Wędka Testowa', 5);

        $this->assertNotNull($wedka->przedmiotId, 'Wędka ma wpis w katalogu');
        $this->assertFloat(20.0, Przedmiot::wymagaj($wedka->przedmiotId, $this->db)->obliczWartosc(), 'Wartość = 4 × 5 zarzutów');
    }

    public function testSkrzynkaDajeWedkeZPelnymLimitemZarzutow(): void
    {
        $wedka = $this->wedka('Wędka Testowa', 5);
        $gracz = Uzytkownik::znajdz(Fixtures::user($this->db, 'gracz', 100.0)['id'], $this->db);

        $skrzynkaId = Fixtures::skrzynka($this->db, 'Skrzynka z wędką', 30.0);
        $this->db->prepare('INSERT INTO skrzynka_przedmioty (skrzynka_id, przedmiot_id, waga) VALUES (?, ?, 1)')
            ->execute([$skrzynkaId, $wedka->przedmiotId]);

        Skrzynka::wymagaj($skrzynkaId, $this->db)->otworzSzczegolowo($gracz, false, null, $this->db);

        $lista = (new Ekwipunek($gracz->id, $this->db))->lista();

        $this->assertCount(1, $lista, 'Wędka trafia do ekwipunku');
        $this->assertSame($wedka->id, $lista[0]['wedka_id'], 'Wpis wskazuje wędkę');
        $this->assertSame(5, $lista[0]['zarzuty_pozostale'], 'Pełny limit zarzutów');
        $this->assertSame(5, $wedka->zarzutyGracza($gracz->id, $this->db), 'Gracz ma 5 zarzutów tą wędką');

        FishingService::cast($gracz->id, $wedka->id, null, $this->db);

        $this->assertSame(4, $wedka->zarzutyGracza($gracz->id, $this->db), 'Zdobytą wędką można łowić');
    }

    public function testRejestracjaDajeWedkeStartowa(): void
    {
        $this->wedka('Wędka Druga', 5, 2);
        $startowa = $this->wedka('Wędka Startowa', 7, 1);

        $gracz = Uzytkownik::utworz('nowy_gracz', 'nowy@test.local', 'haslo12345', $this->db);
        $gracz->zarejestruj();

        $lista = (new Ekwipunek($gracz->id, $this->db))->lista();

        $this->assertCount(1, $lista, 'Nowy gracz ma jedną wędkę');
        $this->assertSame($startowa->id, $lista[0]['wedka_id'], 'To pierwsza wędka z katalogu');
        $this->assertSame(7, $lista[0]['zarzuty_pozostale'], 'Z pełnym limitem zarzutów');
    }

    public function testWedkiNieTrafiajaDoKataloguPrzedmiotowZeSkrzynek(): void
    {
        $wedka = $this->wedka('Wędka Testowa', 5);

        $katalog = array_column(Przedmiot::katalog($this->db), 'nazwa');

        $this->assertNotNull($wedka->przedmiotId, 'Wędka ma wpis w tabeli przedmiotów (wymagany przez ekwipunek)');
        $this->assertFalse(
            in_array('Wędka Testowa', $katalog, true),
            'Wędka nie może trafiać do katalogu przedmiotów losowanych ze skrzynek'
        );
    }

    public function testSprzedazWedkiDajeWartoscPozostalychZarzutow(): void
    {
        $wedka = $this->wedka('Wędka Testowa', 5);
        $gracz = Fixtures::user($this->db, 'gracz', 0.0);
        $ekwipunek = new Ekwipunek($gracz['id'], $this->db);

        $wpis = $ekwipunek->dodaj(Przedmiot::wymagaj((int) $wedka->przedmiotId, $this->db));

        FishingService::cast($gracz['id'], $wedka->id, null, $this->db);
        FishingService::cast($gracz['id'], $wedka->id, null, $this->db);

        $this->assertFloat(12.0, (float) $ekwipunek->sprzedajWpis($wpis)['otrzymane_monety'], 'Sprzedaż za 3 pozostałe zarzuty × 4');
    }
}
