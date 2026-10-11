<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\Administrator;
use MocnyDrop\ApiException;
use MocnyDrop\FishingService;
use MocnyDrop\LogAudytowy;
use MocnyDrop\Portfel;
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

    /** Tryb skrzynek nie jest zaimplementowany — 501 zamiast udawania działania. */
    public function testEdytujSkrzynkeZglaszaBrakImplementacji(): void
    {
        $admin = $this->admin();

        $this->assertThrows(
            ApiException::class,
            fn () => $admin->edytujSkrzynke(null),
            'edytujSkrzynke() musi zgłosić, że tryb skrzynek nie istnieje',
            'cases_not_implemented'
        );

        try {
            $admin->edytujSkrzynke(null);
        } catch (ApiException $e) {
            $this->assertSame(501, $e->httpStatus(), 'Brak implementacji = HTTP 501');
        }
    }

    public function testEksportujRaportZwracaCsv(): void
    {
        $admin = $this->admin();
        $konto = Fixtures::user($this->db, 'gracz', 100.0);
        $wedkaId = Fixtures::readmeWedka($this->db, 10.0);

        FishingService::cast($konto['id'], $wedkaId, Fixtures::scriptedRandom([0.1]), $this->db);

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
