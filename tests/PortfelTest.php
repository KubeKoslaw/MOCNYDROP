<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\ApiException;
use MocnyDrop\Portfel;
use MocnyDrop\Settings;
use MocnyDrop\Uzytkownik;

/**
 * tests/PortfelTest.php
 *
 * Testy klasy Portfel z diagramu klas:
 *   - id, - saldo, - waluta, + czyStac(), + pobierzOplate(), + dodajSrodki()
 * oraz kompozycji Uzytkownik ◆— Portfel (1:1).
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class PortfelTest extends TestCase
{
    /** Kompozycja 1:1 z diagramu: rejestracja konta zakłada też portfel. */
    public function testRejestracjaTworzyPortfel(): void
    {
        $uzytkownik = Uzytkownik::utworz('wedkarz', 'w@test.local', 'haslo1234', $this->db);
        $uzytkownik->zarejestruj();

        $liczba = (int) $this->db->query('SELECT COUNT(*) FROM portfele')->fetchColumn();

        $this->assertSame(1, $liczba, 'Konto musi mieć dokładnie jeden portfel (kompozycja 1:1)');

        $portfel = $uzytkownik->portfel($this->db);

        $this->assertFloat(50.0, $portfel->saldo, 'Saldo startowe z ustawienia saldo_startowe');
        $this->assertSame('monety', $portfel->waluta, 'Waluta z ustawienia `waluta`');
        $this->assertSame($uzytkownik->id, $portfel->uzytkownikId, 'Portfel musi wskazywać właściciela');
    }

    public function testCzyStacPorownujeSaldoZKosztem(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $portfel = Portfel::dlaUzytkownika($gracz['id'], $this->db);

        $this->assertTrue($portfel->czyStac(100.0), 'Dokładnie tyle ile na koncie = wystarczy');
        $this->assertTrue($portfel->czyStac(99.99), 'Mniej niż saldo = wystarczy');
        $this->assertFalse($portfel->czyStac(100.01), 'Więcej niż saldo = nie wystarczy');
        $this->assertTrue($portfel->czyStac(0.0), 'Darmowy zarzut zawsze przechodzi');
    }

    public function testPobierzOplateZwracaTrueIZmniejszaSaldo(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $portfel = Portfel::dlaUzytkownika($gracz['id'], $this->db);

        $this->assertTrue($portfel->pobierzOplate(25.0), 'pobierzOplate() zwraca bool zgodnie z diagramem');
        $this->assertFloat(75.0, $portfel->saldo, 'Saldo po opłacie');
        $this->assertFloat(
            75.0,
            (float) $this->db->query('SELECT saldo FROM portfele')->fetchColumn(),
            'Saldo musi być faktycznie zapisane w bazie'
        );
    }

    /** Kluczowa regresja: brak środków = false, a nie ujemne saldo. */
    public function testPobierzOplateZwracaFalseGdyBrakSrodkow(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 5.0);
        $portfel = Portfel::dlaUzytkownika($gracz['id'], $this->db);

        $this->assertFalse($portfel->pobierzOplate(10.0), 'Za małe saldo musi dać false');
        $this->assertFloat(5.0, $portfel->saldo, 'Nieudana opłata nie może ruszyć salda');
        $this->assertFloat(
            5.0,
            (float) $this->db->query('SELECT saldo FROM portfele')->fetchColumn(),
            'W bazie też nie może być śladu po nieudanej opłacie'
        );
    }

    public function testSaldoNigdyNieSchodziPonizejZera(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 10.0);
        $portfel = Portfel::dlaUzytkownika($gracz['id'], $this->db);

        $this->assertTrue($portfel->pobierzOplate(10.0), 'Wydanie całego salda jest dozwolone');
        $this->assertFloat(0.0, $portfel->saldo, 'Po wydaniu wszystkiego zostaje zero');
        $this->assertFalse($portfel->pobierzOplate(0.01), 'Poniżej zera nie da się zejść');
        $this->assertFloat(0.0, $portfel->saldo, 'Saldo musi zostać zerem, nie liczbą ujemną');
    }

    public function testDodajSrodkiZwiekszaSaldo(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 10.0);
        $portfel = Portfel::dlaUzytkownika($gracz['id'], $this->db);

        $portfel->dodajSrodki(15.5);

        $this->assertFloat(25.5, $portfel->saldo, 'Saldo po uznaniu');
        $this->assertFloat(
            25.5,
            (float) $this->db->query('SELECT saldo FROM portfele')->fetchColumn(),
            'Uznanie musi być trwałe'
        );
    }

    /** NaN/INF/ujemne kwoty nie mogą wejść do salda mimo CHECK w schemacie. */
    public function testOdrzucaNiepoprawneKwoty(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 10.0);
        $portfel = Portfel::dlaUzytkownika($gracz['id'], $this->db);

        $this->assertThrows(
            ApiException::class,
            fn (): bool => $portfel->pobierzOplate(-5.0),
            'Ujemna opłata musi być odrzucona'
        );

        $this->assertThrows(
            ApiException::class,
            fn () => $portfel->dodajSrodki(INF),
            'Nieskończona kwota musi być odrzucona'
        );

        $this->assertThrows(
            ApiException::class,
            fn () => $portfel->dodajSrodki(NAN),
            'NaN musi być odrzucony'
        );

        $this->assertFloat(10.0, $portfel->saldo, 'Odrzucone operacje nie mogą zmienić salda');
    }

    /** Opłata 0 jest dozwolona (darmowa wędka) i nie może się wywrócić. */
    public function testZerowaOplataJestDozwolona(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 10.0);
        $portfel = Portfel::dlaUzytkownika($gracz['id'], $this->db);

        $this->assertTrue($portfel->pobierzOplate(0.0), 'Zerowa opłata musi przejść');
        $this->assertFloat(10.0, $portfel->saldo, 'Zerowa opłata nie zmienia salda');
    }

    public function testWalutaJestKonfigurowalnaWUstawieniach(): void
    {
        Fixtures::setting($this->db, Settings::KEY_CURRENCY, 'zlote_rybki');

        $uzytkownik = Uzytkownik::utworz('wedkarz', 'w@test.local', 'haslo1234', $this->db);
        $uzytkownik->zarejestruj();

        $this->assertSame(
            'zlote_rybki',
            Portfel::dlaUzytkownika($uzytkownik->id, $this->db)->waluta,
            'Waluta musi być brana z tabeli ustawienia, nie z kodu'
        );
    }

    /** Portfele dwóch graczy muszą być niezależne (user_id UNIQUE). */
    public function testPortfeleSaOdseparowaneMiedzyGraczami(): void
    {
        $pierwszy = Fixtures::user($this->db, 'pierwszy', 100.0);
        $drugi = Fixtures::user($this->db, 'drugi', 7.0);

        Portfel::dlaUzytkownika($pierwszy['id'], $this->db)->pobierzOplate(40.0);

        $this->assertFloat(60.0, Portfel::dlaUzytkownika($pierwszy['id'], $this->db)->saldo, 'Portfel pierwszego');
        $this->assertFloat(
            7.0,
            Portfel::dlaUzytkownika($drugi['id'], $this->db)->saldo,
            'Portfel drugiego gracza nie może się zmienić'
        );
    }
}
