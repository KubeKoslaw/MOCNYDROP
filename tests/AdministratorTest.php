<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\Administrator;
use MocnyDrop\ApiException;
use MocnyDrop\FishingService;
use MocnyDrop\LogAudytowy;
use MocnyDrop\Portfel;
use MocnyDrop\Skrzynka;
use MocnyDrop\Token;
use MocnyDrop\Uzytkownik;

/**
 * tests/AdministratorTest.php
 *
 * Testy klasy Administrator z diagramu klas (generalizacja Administrator —|>
 * Uzytkownik) oraz asocjacji «zapisuje» do LogAudytowy:
 *
 *   + zbanuj(u : Uzytkownik)
 *   + korygujSaldo(u : Uzytkownik, kwota : float)
 *   + edytujSkrzynke(s : Skrzynka)
 *   + eksportujRaport() : string
 *
 * Przypadki z diagramu use case: „Zablokowanie konta", „Próba zablokowania
 * głównego administratora", „Zmiana salda gracza", „Eksport raportu".
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class AdministratorTest extends TestCase
{
    /** Administrator to Uzytkownik z rola = 'admin' (bez osobnej tabeli). */
    private function admin(string $login = 'szef'): Administrator
    {
        $konto = Fixtures::user($this->db, $login, 0.0, 'admin');

        return Administrator::zUzytkownika(Uzytkownik::znajdz($konto['id'], $this->db), $this->db);
    }

    private function gracz(string $login = 'gracz', float $saldo = 50.0): Uzytkownik
    {
        $konto = Fixtures::user($this->db, $login, $saldo);

        return Uzytkownik::znajdz($konto['id'], $this->db);
    }

    public function testZbanujUstawiaBlokadeIUniewazniaTokeny(): void
    {
        $admin = $this->admin();
        $gracz = $this->gracz();

        $token = Token::wystaw($gracz, $this->db);

        $this->assertNotNull(Token::uwierzytelnij($token->token, $this->db), 'Przed banem token działa');

        $admin->zbanuj($gracz);

        $this->assertTrue($gracz->czyZablokowany(), 'czyZablokowany() musi być true po banie');
        $this->assertSame(
            1,
            (int) $this->db->query('SELECT zablokowany FROM uzytkownicy WHERE id = ' . $gracz->id)->fetchColumn(),
            'Blokada musi być trwała w bazie'
        );
        $this->assertSame(
            0,
            (int) $this->db->query('SELECT COUNT(*) FROM tokeny')->fetchColumn(),
            'Ban musi unieważnić tokeny (strona 3 diagramu PROJEKT)'
        );
        $this->assertNull(Token::uwierzytelnij($token->token, $this->db), 'Po banie token nie może działać');
        $this->assertFalse($gracz->zaloguj(Fixtures::HASLO), 'Zablokowane konto nie może się zalogować');
    }

    /** Przypadek rozszerzający z diagramu use case. */
    public function testNieMoznaZbanowacAdministratora(): void
    {
        $admin = $this->admin();
        $drugiAdmin = $this->admin('drugi_szef');

        $this->assertThrows(
            ApiException::class,
            fn () => $admin->zbanuj($drugiAdmin),
            'Próba zablokowania innego administratora musi być odrzucona',
            'admin_cannot_be_banned'
        );

        $this->assertThrows(
            ApiException::class,
            fn () => $admin->zbanuj($admin),
            'Próba zablokowania samego siebie musi być odrzucona',
            'admin_cannot_be_banned'
        );

        $this->assertSame(
            0,
            (int) $this->db->query('SELECT COUNT(*) FROM uzytkownicy WHERE zablokowany = 1')->fetchColumn(),
            'Żadne konto nie może zostać zablokowane przez odrzuconą operację'
        );
    }

    public function testKorygujSaldoDodajeIPobieraSrodki(): void
    {
        $admin = $this->admin();
        $gracz = $this->gracz('gracz', 50.0);

        $admin->korygujSaldo($gracz, 100.0);

        $this->assertFloat(
            150.0,
            Portfel::dlaUzytkownika($gracz->id, $this->db)->saldo,
            'Dodatnia korekta = doładowanie portfela'
        );

        $admin->korygujSaldo($gracz, -30.0);

        $this->assertFloat(
            120.0,
            Portfel::dlaUzytkownika($gracz->id, $this->db)->saldo,
            'Ujemna korekta = potrącenie z portfela'
        );
    }

    public function testKorygujSaldoNieSchodziPonizejZera(): void
    {
        $admin = $this->admin();
        $gracz = $this->gracz('gracz', 10.0);

        $this->assertThrows(
            ApiException::class,
            fn () => $admin->korygujSaldo($gracz, -50.0),
            'Potrącenie więcej niż jest na koncie musi być odrzucone',
            'insufficient_funds'
        );

        $this->assertFloat(
            10.0,
            Portfel::dlaUzytkownika($gracz->id, $this->db)->saldo,
            'Odrzucona korekta nie może ruszyć salda'
        );
    }

    public function testKorygujSaldoOdrzucaZeroINieSkonczonosc(): void
    {
        $admin = $this->admin();
        $gracz = $this->gracz();

        foreach ([0.0, 0.001, NAN, INF] as $kwota) {
            $this->assertThrows(
                ApiException::class,
                fn () => $admin->korygujSaldo($gracz, (float) $kwota),
                'Korekta o ' . var_export($kwota, true) . ' musi być odrzucona',
                'invalid_amount'
            );
        }
    }

    /**
     * «Kreator i Edytor Skrzynek» — UC_ADM1: ta sama metoda tworzy i aktualizuje.
     * Zapis skrzynki i jej puli (dropy + wagi) jest jedną transakcją.
     */
    public function testEdytujSkrzynkeZapisujeNowaSkrzynkeZPula(): void
    {
        $admin = $this->admin();

        $anchovis = Fixtures::wedkaZRyba($this->db, 'Bambus');
        $jesiotr = Fixtures::wedkaZRyba($this->db, 'Morska');

        $skrzynka = Skrzynka::nowa('Skrzynka Testowa', 25.0, '', 'Opis skrzynki');
        $skrzynka->pulaDoZapisu = [
            ['wedka_id' => $anchovis, 'waga' => 50.0],
            ['wedka_id' => $jesiotr, 'waga' => null],
        ];

        $this->assertSame(0, $skrzynka->id, 'Przed zapisem skrzynka nie ma id');

        $admin->edytujSkrzynke($skrzynka);

        $this->assertTrue($skrzynka->id > 0, 'edytujSkrzynke() musi nadać id nowej skrzynce');

        $zBazy = Skrzynka::wymagaj($skrzynka->id, $this->db);

        $this->assertSame('Skrzynka Testowa', $zBazy->nazwa, 'Nazwa skrzynki w bazie');
        $this->assertFloat(25.0, $zBazy->cena, 'Cena skrzynki w bazie');
        $this->assertTrue($zBazy->czyAktywna(), 'Nowa skrzynka jest domyślnie aktywna');
        $this->assertCount(2, $zBazy->pula($this->db), 'Pula skrzynki ma dwa dropy');

        // Waga NULL = dziedziczenie waga_domyslna poziomu rzadkości (Niesamowita = 1).
        // pula() jest sortowana po kolejności rzadkości: [0] Anchois, [1] Jesiotr.
        $pula = $zBazy->pula($this->db);

        $this->assertFloat(50.0, (float) $pula[0]['waga'], 'Jawna waga z panelu admina');
        $this->assertFloat(1.0, (float) $pula[1]['waga'], 'Pusta waga dziedziczy wagę domyślną poziomu');

        $akcje = array_map(
            static fn (array $wpis): string => $wpis['akcja'],
            LogAudytowy::ostatnie(5, $this->db)
        );

        $this->assertSame(['skrzynka_utworzona'], $akcje, 'Zapis skrzynki zostawia ślad w logu audytowym');
    }

    public function testEdytujSkrzynkeAktualizujeIstniejaca(): void
    {
        $admin = $this->admin();
        $id = Fixtures::skrzynka($this->db, 'Stara Nazwa', 10.0);

        $zmieniona = new Skrzynka('Nowa Nazwa', 15.5, '', false, '', 0, $id, $this->db);

        $admin->edytujSkrzynke($zmieniona);

        $zBazy = Skrzynka::wymagaj($id, $this->db);

        $this->assertSame('Nowa Nazwa', $zBazy->nazwa, 'Nazwa po edycji');
        $this->assertFloat(15.5, $zBazy->cena, 'Cena po edycji');
        $this->assertFalse($zBazy->czyAktywna(), 'Wyłączenie skrzynki (aktywna = 0) z diagramu PROJEKT');
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM skrzynki')->fetchColumn(), 'Edycja nie może zduplikować skrzynki');
    }

    /** Przypadek rozszerzający ADM1_ERR: «Błędne wagi lub ujemna cena». */
    public function testEdytujSkrzynkeOdrzucaUjemnaCeneIBledneWagi(): void
    {
        $admin = $this->admin();
        $anchovis = Fixtures::wedkaZRyba($this->db, 'Bambus');

        $ujemna = Skrzynka::nowa('Zła Skrzynka', -5.0);

        $this->assertThrows(
            ApiException::class,
            fn () => $admin->edytujSkrzynke($ujemna),
            'Ujemna cena musi być odrzucona (ADM1_ERR)',
            'invalid_price'
        );

        $zlaWaga = Skrzynka::nowa('Zła Waga', 10.0);
        $zlaWaga->pulaDoZapisu = [['wedka_id' => $anchovis, 'waga' => -1.0]];

        $this->assertThrows(
            ApiException::class,
            fn () => $admin->edytujSkrzynke($zlaWaga),
            'Ujemna waga dropu musi być odrzucona (ADM1_ERR)',
            'invalid_weight'
        );

        $pusta = Skrzynka::nowa('', 10.0);

        $this->assertThrows(
            ApiException::class,
            fn () => $admin->edytujSkrzynke($pusta),
            'Skrzynka bez nazwy musi być odrzucona',
            'invalid_case_name'
        );

        $this->assertSame(
            0,
            (int) $this->db->query('SELECT COUNT(*) FROM skrzynki')->fetchColumn(),
            'Odrzucone zapisy nie mogą zostawić skrzynki w bazie'
        );
        $this->assertSame(
            0,
            (int) $this->db->query('SELECT COUNT(*) FROM skrzynka_wedki')->fetchColumn(),
            'Odrzucony zapis nie może zostawić połowy puli (transakcyjność)'
        );
    }

    public function testEdytujSkrzynkeOdrzucaZduplikowanaNazwe(): void
    {
        $admin = $this->admin();

        Fixtures::skrzynka($this->db, 'Taka Sama', 10.0);

        $this->assertThrows(
            ApiException::class,
            fn () => $admin->edytujSkrzynke(Skrzynka::nowa('Taka Sama', 20.0)),
            'Nazwa skrzynki jest UNIQUE — duplikat musi być odrzucony',
            'case_name_taken'
        );
    }

    public function testOdbanujZdejmujeBlokade(): void
    {
        $admin = $this->admin();
        $gracz = $this->gracz();

        $admin->zbanuj($gracz);

        $this->assertTrue($gracz->czyZablokowany(), 'Konto jest zablokowane po banie');

        $admin->odbanuj($gracz);

        $this->assertFalse($gracz->czyZablokowany(), 'odbanuj() musi zdjąć blokadę');
        $this->assertTrue($gracz->zaloguj(Fixtures::HASLO), 'Odblokowane konto może się zalogować');
        $this->assertSame(
            'odblokowanie',
            LogAudytowy::ostatnie(1, $this->db)[0]['akcja'],
            'Odblokowanie zostawia wpis w logu audytowym'
        );
    }

    public function testEksportujRaportZwracaCsv(): void
    {
        $admin = $this->admin();
        $konto = Fixtures::user($this->db, 'gracz', 100.0);
        $wedkaId = Fixtures::readmeWedka($this->db, 10.0);

        Fixtures::zarzucPosiadanaWedka($this->db, $konto['id'], $wedkaId, Fixtures::scriptedRandom([0.1]), $this->db);

        $csv = $admin->eksportujRaport();

        $linie = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertSame('id,czas,typ,login,koszt,wygrana_wartosc,bilans', $linie[0], 'Nagłówek raportu CSV');
        $this->assertSame(3, count($linie), 'Nagłówek + 1 losowanie + wiersz SUMA');
        $this->assertTrue(str_contains($linie[1], ',wedka,gracz,'), 'Wiersz losowania z typem i loginem');
        $this->assertTrue(str_starts_with($linie[2], 'SUMA,'), 'Ostatni wiersz to podsumowanie');
    }

    /** Asocjacja «zapisuje» z diagramu: każda operacja admina zostawia ślad. */
    public function testOperacjeAdminaZapisujaLogAudytowy(): void
    {
        $admin = $this->admin();
        $gracz = $this->gracz();

        $admin->korygujSaldo($gracz, 25.0);
        $admin->zbanuj($gracz);
        $admin->eksportujRaport();

        $wpisy = LogAudytowy::ostatnie(10, $this->db);

        $akcje = array_map(static fn (array $w): string => $w['akcja'], $wpisy);

        $this->assertSame(
            ['eksport_raportu', 'ban', 'korekta_saldo'],
            $akcje,
            'Log audytowy od najnowszego: eksport, ban, korekta salda'
        );

        $this->assertSame($admin->id, (int) $wpisy[1]['user_id'], 'Wpis musi wskazywać administratora');
        $this->assertTrue(str_contains((string) $wpisy[1]['szczegoly'], 'login=gracz'), 'Szczegóły bana');
    }
}
