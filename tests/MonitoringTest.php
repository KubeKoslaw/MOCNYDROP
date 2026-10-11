<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\Administrator;
use MocnyDrop\FishingService;
use MocnyDrop\LogAudytowy;
use MocnyDrop\Losowanie;
use MocnyDrop\Settings;
use MocnyDrop\Uzytkownik;

/**
 * tests/MonitoringTest.php
 *
 * Przypadki użycia administratora z diagramu use case:
 *
 *   UC_ADM2 «Zarządzanie użytkownikami»
 *     - wyszukiwanie konta po ID, loginie lub e-mailu (strona 3 diagramu PROJEKT)
 *   UC_ADM3 «Monitoring aktywności»
 *     ADM3_S1  «Przegląd statystyk wygranych»      -> Losowanie::statystyki()
 *     ADM3_S3  «Eksport raportu»                   -> AdministratorTest
 *     ADM3_EXT «Alert: podejrzane serie wygranych» -> Losowanie::podejrzaneSerie()
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class MonitoringTest extends TestCase
{
    /** Łowisko README jest w teście jedno — nazwy wędek i ryb są UNIQUE. */
    private int $wedkaId = 0;

    private float $wedkaKoszt = -1.0;

    public function setUp(): void
    {
        parent::setUp();

        // run.php używa TEJ SAMEJ instancji klasy dla wszystkich testów,
        // a każdy test ma świeżą bazę — cache łowiska trzeba wyzerować.
        $this->wedkaId = 0;
        $this->wedkaKoszt = -1.0;
    }

    /** Łowisko z pulą README (tworzone raz na test, współdzielone przez graczy). */
    private function lowisko(float $koszt = 10.0): int
    {
        if ($this->wedkaId === 0 || $this->wedkaKoszt !== $koszt) {
            $this->wedkaId = Fixtures::readmeWedka($this->db, $koszt);
            $this->wedkaKoszt = $koszt;
        }

        return $this->wedkaId;
    }

    /**
     * Gracz z $ile połowami na łowisku README.
     * $los steruje wynikiem: 0.1 = Anchois (Pospolita), 0.99 = Jesiotr (Niesamowita).
     */
    private function graczZPolowami(string $login, int $ile, float $los, float $koszt = 10.0): int
    {
        $konto = Fixtures::user($this->db, $login, 100000.0);
        $wedka = $this->lowisko($koszt);

        for ($i = 0; $i < $ile; $i++) {
            FishingService::cast($konto['id'], $wedka, Fixtures::scriptedRandom([$los]), $this->db);
        }

        return $konto['id'];
    }

    /** ADM3_S1 «Przegląd statystyk wygranych» — RTP z tabela historia_losowan. */
    public function testStatystykiLiczaRtpIRozbicie(): void
    {
        $this->graczZPolowami('wedkarz_a', 2, 0.1, 10.0);   // 2 × Anchois (2.0)
        $this->graczZPolowami('wedkarz_b', 1, 0.99, 10.0);  // 1 × Jesiotr (400.0)

        $statystyki = Losowanie::statystyki($this->db);

        $this->assertSame(3, $statystyki['lacznie']['liczba_losowan'], 'Łączna liczba losowań');
        $this->assertSame(2, $statystyki['lacznie']['liczba_graczy'], 'Liczba graczy w statystykach');
        $this->assertFloat(30.0, $statystyki['lacznie']['suma_kosztow'], 'Suma pobranych opłat');
        $this->assertFloat(404.0, $statystyki['lacznie']['suma_wyplat'], 'Suma wypłaconych wartości');
        $this->assertFloat(round(404.0 / 30.0, 4), $statystyki['lacznie']['rtp'], 'RTP platformy');
        $this->assertFloat(30.0 - 404.0, $statystyki['lacznie']['bilans_platformy'], 'Bilans platformy (ujemny = gracze wygrywają)');

        $this->assertCount(4, $statystyki['wedlug_typu'], 'Statystyki obejmują cztery tryby gry z TypLosowania');

        $wedka = null;
        $upgrader = null;

        foreach ($statystyki['wedlug_typu'] as $typ) {
            if ($typ['typ'] === 'wedka') {
                $wedka = $typ;
            }

            if ($typ['typ'] === 'upgrader') {
                $upgrader = $typ;
            }
        }

        $this->assertNotNull($wedka, 'Statystyki zawierają tryb łowiska');
        $this->assertSame(3, $wedka['liczba_losowan'], 'Liczba losowań łowiska');
        $this->assertSame('Rzut wędką', $wedka['typ_nazwa'], 'Czytelna nazwa trybu dla panelu');
        $this->assertSame(0, $upgrader['liczba_losowan'], 'Tryb bez zdarzeń ma zero losowań');
        $this->assertFloat(0.0, $upgrader['rtp'], 'RTP pustego trybu = 0 (nie dzielimy przez zero)');
    }

    public function testStatystykiPokazujaRzadkosciINajwiekszeWygrane(): void
    {
        $this->graczZPolowami('kolekcjoner', 1, 0.99, 10.0);
        $this->graczZPolowami('nowicjusz', 1, 0.1, 10.0);

        $statystyki = Losowanie::statystyki($this->db);

        $kody = array_map(static fn (array $row): string => $row['kod'], $statystyki['rozbicie_rzadkosci']);

        $this->assertSame(['pospolita', 'niesamowita'], $kody, 'Rozbicie rzadkości posortowane od najczęstszej');
        $this->assertSame(1, $statystyki['rozbicie_rzadkosci'][1]['liczba'], 'Liczba trafień danego poziomu');

        $najwieksza = $statystyki['najwieksze_wygrane'][0];

        $this->assertSame('Jesiotr', $najwieksza['nazwa'], 'Największa wygrana na pierwszym miejscu');
        $this->assertSame('kolekcjoner', $najwieksza['login'], 'Największa wygrana wskazuje gracza');
        $this->assertFloat(400.0, $najwieksza['wygrana_wartosc'], 'Wartość największej wygranej');
        $this->assertArrayHasKey('ikona', $najwieksza, 'Największe wygrane mają ikonę dla panelu');
    }

    /** ADM3_EXT «Alert: podejrzane serie wygranych». */
    public function testAlertWykrywaSerieRzadkichWygranych(): void
    {
        // Trzy Niesamowite z rzędu (kolejnosc 5 >= próg 4) -> alert serii.
        $this->graczZPolowami('podejrzany', 3, 0.99, 10.0);
        // Zwykły gracz: same Pospolite, niskie RTP.
        $this->graczZPolowami('uczciwy', 3, 0.1, 10.0);

        $wynik = Losowanie::podejrzaneSerie($this->db);

        $serie = array_values(array_filter(
            $wynik['alerty'],
            static fn (array $alert): bool => $alert['typ_alertu'] === 'seria_wygranych'
        ));

        $this->assertSame(1, count($serie), 'Tylko gracz z serią rzadkich trafień dostaje alert');
        $this->assertSame('podejrzany', $serie[0]['login'], 'Alert wskazuje gracza');
        $this->assertSame(3, $serie[0]['wartosc'], 'Długość wykrytej serii');
        $this->assertSame(['Jesiotr', 'Jesiotr', 'Jesiotr'], $serie[0]['przedmioty'], 'Alert wymienia przedmioty serii');
        $this->assertNotNull($serie[0]['czas_od'], 'Alert podaje początek serii');
    }

    public function testAlertWykrywaAnomalnieWysokieRtp(): void
    {
        // 5 połowów po 10 monet, każdy warty 400 -> RTP = 2000 / 50 = 40.
        $this->graczZPolowami('rekordzista', 5, 0.99, 10.0);

        $wynik = Losowanie::podejrzaneSerie($this->db);

        $rtp = array_values(array_filter(
            $wynik['alerty'],
            static fn (array $alert): bool => $alert['typ_alertu'] === 'wysokie_rtp'
        ));

        $this->assertSame(1, count($rtp), 'Gracz z RTP powyżej progu dostaje alert');
        $this->assertSame('rekordzista', $rtp[0]['login'], 'Alert RTP wskazuje gracza');
        $this->assertFloat(40.0, (float) $rtp[0]['wartosc'], 'Wartość RTP w alercie');
        $this->assertFloat(50.0, (float) $rtp[0]['suma_kosztow'], 'Suma opłat gracza w alercie');
        $this->assertFloat(2000.0, (float) $rtp[0]['suma_wyplat'], 'Suma wypłat gracza w alercie');
        $this->assertSame(5, $rtp[0]['liczba_losowan'], 'Liczba losowań w alercie');
        $this->assertSame('seria_wygranych', $wynik['alerty'][0]['typ_alertu'], 'Serie są raportowane przed RTP');
    }

    public function testZwyklyGraczNieDostajeAlertow(): void
    {
        // Same Pospolite (kolejnosc 1) i RTP 0.2 — nic podejrzanego.
        $this->graczZPolowami('spokojny', 5, 0.1, 10.0);

        $wynik = Losowanie::podejrzaneSerie($this->db);

        $this->assertSame([], $wynik['alerty'], 'Uczciwy gracz nie może być oznaczany jako anomalia');
        $this->assertSame(3, $wynik['progi']['dlugosc_serii'], 'Panel dostaje progi alertów');
        $this->assertSame(4, $wynik['progi']['min_rzadkosc_kolejnosc'], 'Próg rzadkości w odpowiedzi');
    }

    public function testProgiAlertowPochodzaZUstawien(): void
    {
        $this->graczZPolowami('gracz', 2, 0.99, 10.0);

        $this->assertSame([], Losowanie::podejrzaneSerie($this->db)['alerty'], 'Dwie wygrane to za mało na serię (próg 3)');

        Fixtures::ustawienie($this->db, Settings::KEY_ALERT_STREAK, '2');

        $poZmianie = Losowanie::podejrzaneSerie($this->db);

        $serie = array_values(array_filter(
            $poZmianie['alerty'],
            static fn (array $alert): bool => $alert['typ_alertu'] === 'seria_wygranych'
        ));

        $this->assertSame(1, count($serie), 'Obniżenie progu bez zmiany kodu wykrywa krótszą serię');
        $this->assertSame(2, $poZmianie['progi']['dlugosc_serii'], 'Nowy próg jest widoczny w odpowiedzi');

        // Seria liczy tylko poziomy od progu rzadkości w górę.
        Fixtures::ustawienie($this->db, Settings::KEY_ALERT_RARITY_ORDER, '6');

        $poProgu = array_values(array_filter(
            Losowanie::podejrzaneSerie($this->db)['alerty'],
            static fn (array $alert): bool => $alert['typ_alertu'] === 'seria_wygranych'
        ));

        $this->assertSame([], $poProgu, 'Niesamowita (5) jest poniżej progu ULTRA (6)');
    }

    /** Pospolita w środku przerywa serię rzadkich trafień. */
    public function testPorazkiPrzerywajaSerie(): void
    {
        $konto = Fixtures::user($this->db, 'mieszany', 100000.0);
        $wedka = $this->lowisko(10.0);

        foreach ([0.99, 0.1, 0.99, 0.99] as $los) {
            FishingService::cast($konto['id'], $wedka, Fixtures::scriptedRandom([$los]), $this->db);
        }

        $serie = array_values(array_filter(
            Losowanie::podejrzaneSerie($this->db)['alerty'],
            static fn (array $alert): bool => $alert['typ_alertu'] === 'seria_wygranych'
        ));

        $this->assertSame([], $serie, 'Najdłuższa seria to 2 (poniżej progu 3) — Pospolita ją przerwała');
    }

    /** UC_ADM2: «Wyszukiwanie konta w SQLite po ID, loginie lub emailu». */
    public function testSzukajDlaAdminaZnajdujePoLoginieEmailuIId(): void
    {
        $konto = Fixtures::user($this->db, 'jan_kowalski', 123.45);
        Fixtures::user($this->db, 'anna_nowak', 10.0);

        $poLoginie = Uzytkownik::szukajDlaAdmina('jan', 50, $this->db);

        $this->assertCount(1, $poLoginie, 'Szukanie po fragmencie loginu');
        $this->assertSame('jan_kowalski', $poLoginie[0]['login'], 'Znaleziony login');
        $this->assertFloat(123.45, $poLoginie[0]['saldo'], 'Saldo gracza w wyniku wyszukiwania');
        $this->assertArrayHasKey('rtp', $poLoginie[0], 'Panel pokazuje RTP gracza');
        $this->assertFalse($poLoginie[0]['zablokowany'], 'Stan blokady w wyniku wyszukiwania');

        $this->assertCount(1, Uzytkownik::szukajDlaAdmina('anna_nowak@test.local', 50, $this->db), 'Szukanie po e-mailu');
        $this->assertCount(1, Uzytkownik::szukajDlaAdmina((string) $konto['id'], 50, $this->db), 'Szukanie po ID');
        $this->assertCount(2, Uzytkownik::szukajDlaAdmina('', 50, $this->db), 'Pusta fraza zwraca wszystkich graczy');
        $this->assertCount(1, Uzytkownik::szukajDlaAdmina('', 1, $this->db), 'Limit jest respektowany');
        $this->assertCount(0, Uzytkownik::szukajDlaAdmina('nie_ma_takiego', 50, $this->db), 'Brak trafień = pusta lista');
    }

    public function testSzukajDlaAdminaPokazujeAktywnoscGracza(): void
    {
        $userId = $this->graczZPolowami('aktywny', 2, 0.1, 10.0);

        $wynik = Uzytkownik::szukajDlaAdmina('aktywny', 50, $this->db)[0];

        $this->assertSame($userId, $wynik['id'], 'ID gracza');
        $this->assertSame(2, $wynik['liczba_losowan'], 'Liczba losowań gracza w panelu');
        $this->assertNotNull($wynik['ostatnia_aktywnosc'], 'Ostatnia aktywność gracza');
        $this->assertFloat(0.2, $wynik['rtp'], 'RTP gracza = 4 / 20');
    }

    /** «Przegląd pełnego dziennika audytowego w SQLite». */
    public function testDziennikAudytowyJestDostepny(): void
    {
        $konto = Fixtures::user($this->db, 'admin', 0.0, 'admin');
        $gracz = Fixtures::user($this->db, 'gracz', 50.0);

        $admin = Administrator::zUzytkownika(Uzytkownik::znajdz($konto['id'], $this->db), $this->db);

        $admin->korygujSaldo(Uzytkownik::znajdz($gracz['id'], $this->db), 10.0);

        $dziennik = LogAudytowy::ostatnie(10, $this->db);

        $this->assertCount(1, $dziennik, 'Dziennik zawiera jedną operację admina');
        $this->assertSame('korekta_saldo', $dziennik[0]['akcja'], 'Akcja z diagramu (LogAudytowy.akcja)');
        $this->assertNotNull($dziennik[0]['czas'], 'Czas wpisu (LogAudytowy.czas)');
        $this->assertSame('admin', $dziennik[0]['login'], 'Wpis wskazuje administratora');
    }
}
