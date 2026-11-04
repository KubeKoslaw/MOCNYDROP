<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\ApiException;
use MocnyDrop\DarmowaSkrzynka;
use MocnyDrop\Ekwipunek;
use MocnyDrop\Portfel;
use MocnyDrop\Uzytkownik;

final class DarmowaSkrzynkaTest extends TestCase
{
    private const T0 = 1_800_000_000;

    private function gracz(float $saldo = 0.0): Uzytkownik
    {
        $konto = Fixtures::user($this->db, 'gracz', $saldo);

        return Uzytkownik::znajdz($konto['id'], $this->db);
    }

    private function skrzynki(): void
    {
        $droga = Fixtures::skrzynka($this->db, 'Droga', 500.0, true, 1);
        Fixtures::przedmiotSkrzynki($this->db, $droga, 'Rekin', 'mityczna', 900.0);

        $tania = Fixtures::skrzynka($this->db, 'Tania', 10.0, true, 2);
        Fixtures::przedmiotSkrzynki($this->db, $tania, 'Anchois', 'pospolita', 3.0);
    }

    public function testNowyGraczMaDostepnaNajtanszaSkrzynke(): void
    {
        $this->skrzynki();
        $gracz = $this->gracz();

        $status = DarmowaSkrzynka::status($gracz->id, self::T0, $this->db);

        $this->assertTrue($status['dostepna'], 'Nowy gracz może od razu odebrać skrzynkę');
        $this->assertSame(0, $status['za_sekund'], 'Brak odliczania dla nowego gracza');
        $this->assertSame('Tania', $status['skrzynka']['nazwa'], 'Darmowa jest najtańsza aktywna skrzynka');
    }

    public function testOdbiorNiePobieraMonetIDodajePrzedmiot(): void
    {
        $this->skrzynki();
        $gracz = $this->gracz(0.0);

        $odbior = DarmowaSkrzynka::odbierz($gracz, self::T0, null, $this->db);

        $this->assertSame('Anchois', $odbior['wynik']['przedmiot']->nazwa, 'Drop z najtańszej skrzynki');
        $this->assertFloat(0.0, (float) $odbior['wynik']['cena'], 'Darmowa skrzynka kosztuje 0');
        $this->assertFloat(0.0, Portfel::dlaUzytkownika($gracz->id, $this->db)->saldo, 'Saldo bez zmian');
        $this->assertCount(1, (new Ekwipunek($gracz->id, $this->db))->lista(), 'Przedmiot trafia do ekwipunku');
    }

    public function testDrugiOdbiorPrzed24hJestOdrzucany(): void
    {
        $this->skrzynki();
        $gracz = $this->gracz();

        DarmowaSkrzynka::odbierz($gracz, self::T0, null, $this->db);

        $this->assertThrows(
            ApiException::class,
            fn () => DarmowaSkrzynka::odbierz($gracz, self::T0 + 3600, null, $this->db),
            'Druga skrzynka w ciągu doby jest blokowana',
            'free_case_cooldown'
        );

        $status = DarmowaSkrzynka::status($gracz->id, self::T0 + 3600, $this->db);

        $this->assertFalse($status['dostepna'], 'Po odbiorze skrzynka jest niedostępna');
        $this->assertSame(23 * 3600, $status['za_sekund'], 'Odliczanie do kolejnej skrzynki');
    }

    public function testPo24hSkrzynkaZnowuDostepna(): void
    {
        $this->skrzynki();
        $gracz = $this->gracz();

        DarmowaSkrzynka::odbierz($gracz, self::T0, null, $this->db);
        DarmowaSkrzynka::odbierz($gracz, self::T0 + DarmowaSkrzynka::COOLDOWN_SEKUND, null, $this->db);

        $this->assertCount(2, (new Ekwipunek($gracz->id, $this->db))->lista(), 'Po dobie można odebrać kolejną');
    }

    public function testDwaOdbioryWTymSamymCzasieNieDajaDwochDropow(): void
    {
        $this->skrzynki();
        $gracz = $this->gracz();
        DarmowaSkrzynka::odbierz($gracz, self::T0, null, $this->db);

        $this->assertThrows(
            ApiException::class,
            fn () => DarmowaSkrzynka::odbierz($gracz, self::T0, null, $this->db),
            'Identyczna data w UPSERT nie oznacza kolejnego odbioru',
            'free_case_cooldown'
        );
        $this->assertCount(1, (new Ekwipunek($gracz->id, $this->db))->lista(), 'Tylko jeden drop');
    }

    public function testBladLosowaniaPrzywracaPoprzedniOdbior(): void
    {
        $this->skrzynki();
        $gracz = $this->gracz();
        DarmowaSkrzynka::odbierz($gracz, self::T0, null, $this->db);
        $pozniej = self::T0 + DarmowaSkrzynka::COOLDOWN_SEKUND;

        $this->assertThrows(\RuntimeException::class, static function () use ($gracz, $pozniej): void {
            DarmowaSkrzynka::odbierz($gracz, $pozniej, static function (): float {
                throw new \RuntimeException('Symulowany błąd losowania');
            });
        }, 'Błąd po zajęciu odbioru jest propagowany');

        $this->assertTrue(DarmowaSkrzynka::status($gracz->id, $pozniej, $this->db)['dostepna'], 'Można ponowić odbiór');
        $this->assertCount(1, (new Ekwipunek($gracz->id, $this->db))->lista(), 'Nieudana transakcja bez dodatkowego dropu');
    }

    public function testNieudaneOtwarcieNieZabieraOdbioru(): void
    {
        $gracz = $this->gracz();
        $pusta = Fixtures::skrzynka($this->db, 'Pusta', 5.0);

        $this->assertThrows(
            ApiException::class,
            fn () => DarmowaSkrzynka::odbierz($gracz, self::T0, null, $this->db),
            'Bez otwieralnej skrzynki nie ma czego odebrać',
            'free_case_unavailable'
        );

        Fixtures::przedmiotSkrzynki($this->db, $pusta, 'Anchois', 'pospolita', 3.0);

        $this->assertTrue(
            DarmowaSkrzynka::status($gracz->id, self::T0, $this->db)['dostepna'],
            'Odbiór dalej czeka na gracza'
        );
    }
}
