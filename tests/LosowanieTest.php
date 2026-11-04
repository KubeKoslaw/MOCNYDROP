<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\ApiException;
use MocnyDrop\FishingService;
use MocnyDrop\Losowanie;
use MocnyDrop\Przedmiot;
use MocnyDrop\TypLosowania;
use MocnyDrop\Wedka;

final class LosowanieTest extends TestCase
{
    public function testZapiszUzupelniaIdITworzyWiersz(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $wedkaId = Fixtures::readmeWedka($this->db, 10.0);

        $pula = Wedka::wymagaj($wedkaId, $this->db)->pula($this->db);
        $przedmiot = Przedmiot::zPuli($pula[0]);

        $losowanie = new Losowanie(
            $gracz['id'],
            TypLosowania::Wedka,
            $przedmiot,
            date('Y-m-d H:i:s'),
            10.0,
            2.0,
            50.0,
            100.001,
            $wedkaId,
            0,
            $this->db
        );

        $this->assertSame(0, $losowanie->id, 'Przed zapisem id musi być zerem');

        $losowanie->zapisz();

        $this->assertTrue($losowanie->id > 0, 'zapisz() musi nadać id z bazy');

        $stmt = $this->db->prepare('SELECT typ, wynik_id, czas FROM historia_losowan WHERE id = ?');
        $stmt->execute([$losowanie->id]);
        $wpis = $stmt->fetch();

        $this->assertSame('wedka', (string) $wpis['typ'], 'Atrybut `typ` zapisany jako TypLosowania::Wedka');
        $this->assertSame($przedmiot->id, (int) $wpis['wynik_id'], 'Asocjacja Losowanie —wynik→ Przedmiot');
        $this->assertTrue((string) $wpis['czas'] !== '', 'Atrybut `czas` musi być zapisany');
    }

    public function testZapiszBezWynikuJestOdrzucany(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);

        $losowanie = new Losowanie($gracz['id'], TypLosowania::Wedka, null, '', 0.0, 0.0, 0.0, 0.0, 0, 0, $this->db);

        $this->assertThrows(
            ApiException::class,
            fn () => $losowanie->zapisz(),
            'Losowanie bez wyniku nie może trafić do historii',
            'no_result'
        );

        $this->assertSame(
            0,
            (int) $this->db->query('SELECT COUNT(*) FROM historia_losowan')->fetchColumn(),
            'Odrzucony zapis nie może zostawić wiersza'
        );
    }

    public function testWyliczRTPDzieliWygranePrzezKoszty(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $przedmiot = Przedmiot::znajdz(
            Fixtures::przedmiot($this->db, Fixtures::wedka($this->db, 'Wędka', 5.0), 'Anchois', 'pospolita', 2.0),
            $this->db
        );

        foreach ([[5.0, 2.0], [5.0, 3.0]] as [$koszt, $wygrana]) {
            (new Losowanie($gracz['id'], TypLosowania::Wedka, $przedmiot, '', $koszt, $wygrana, 50.0, 100.0, 0, 0, $this->db))
                ->zapisz();
        }

        $losowanie = new Losowanie($gracz['id'], TypLosowania::Wedka, $przedmiot, '', 0.0, 0.0, 0.0, 0.0, 0, 0, $this->db);

        $this->assertFloat(0.5, $losowanie->wyliczRTP(), 'RTP = (2+3) / (5+5)');
    }

    public function testRtpBezKosztowZwracaZero(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $losowanie = new Losowanie($gracz['id'], TypLosowania::Wedka, null, '', 0.0, 0.0, 0.0, 0.0, 0, 0, $this->db);

        $this->assertFloat(0.0, $losowanie->wyliczRTP(), 'Pusta historia = RTP 0.0');
        $this->assertFloat(
            0.0,
            Losowanie::rtpDlaTypu(TypLosowania::Skrzynka, $this->db),
            'Tryb bez losowań musi zwrócić 0.0, nie błąd'
        );
    }

    public function testRtpLiczySieOsobnoDlaKazdegoTypu(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 100.0);
        $przedmiot = Przedmiot::znajdz(
            Fixtures::przedmiot($this->db, Fixtures::wedka($this->db, 'Wędka', 5.0), 'Anchois', 'pospolita', 2.0),
            $this->db
        );

        (new Losowanie($gracz['id'], TypLosowania::Wedka, $przedmiot, '', 10.0, 5.0, 1.0, 1.0, 0, 0, $this->db))->zapisz();
        (new Losowanie($gracz['id'], TypLosowania::Skrzynka, $przedmiot, '', 10.0, 20.0, 1.0, 1.0, 0, 0, $this->db))->zapisz();

        $this->assertFloat(0.5, Losowanie::rtpDlaTypu(TypLosowania::Wedka, $this->db), 'RTP wędkowania');
        $this->assertFloat(2.0, Losowanie::rtpDlaTypu(TypLosowania::Skrzynka, $this->db), 'RTP skrzynek');
    }

    public function testHistoriaZwracaWpisyTegoGraczaZNowymiPolami(): void
    {
        $pierwszy = Fixtures::user($this->db, 'pierwszy', 100.0);
        $drugi = Fixtures::user($this->db, 'drugi', 100.0);

        $wedkaId = Fixtures::readmeWedka($this->db, 10.0);

        Fixtures::dajWedke($this->db, $pierwszy['id'], $wedkaId);
        Fixtures::dajWedke($this->db, $drugi['id'], $wedkaId);
        FishingService::cast($pierwszy['id'], $wedkaId, Fixtures::scriptedRandom([0.1]), $this->db);
        FishingService::cast($drugi['id'], $wedkaId, Fixtures::scriptedRandom([0.1]), $this->db);

        $historia = Losowanie::historia($pierwszy['id'], 10, $this->db);

        $this->assertCount(1, $historia, 'Historia nie może pokazywać losowań innych graczy');
        $this->assertSame('wedka', $historia[0]['typ'], 'Typ losowania (atrybut z diagramu)');
        $this->assertSame('Rzut wędką', $historia[0]['typ_nazwa'], 'Czytelna nazwa trybu');
        $this->assertTrue((string) $historia[0]['czas'] !== '', 'Czas losowania (atrybut z diagramu)');
        $this->assertTrue((int) $historia[0]['przedmiot_id'] > 0, 'Wynik jako Przedmiot (asocjacja z diagramu)');
        $this->assertTrue((string) $historia[0]['wedka'] !== '', 'Nazwa wędki w historii');
    }

    public function testRozbicieRzadkosciZwracaLiczniki(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 1000.0);
        $wedkaId = Fixtures::readmeWedka($this->db, 0.0);

        Fixtures::dajWedke($this->db, $gracz['id'], $wedkaId, 2);
        FishingService::cast($gracz['id'], $wedkaId, Fixtures::scriptedRandom([0.0]), $this->db);
        FishingService::cast($gracz['id'], $wedkaId, Fixtures::scriptedRandom([0.0]), $this->db);

        $rozbicie = Losowanie::rozbicieRzadkosci($gracz['id'], $this->db);

        $this->assertSame(2, $rozbicie['pospolita'], 'Dwa losowania poziomu Pospolita');
        $this->assertCount(1, $rozbicie, 'Tylko jeden poziom rzadkości został trafiony');
    }

    public function testHistoriaPrzycinaLimit(): void
    {
        $gracz = Fixtures::user($this->db, 'wedkarz', 1000.0);
        $wedkaId = Fixtures::readmeWedka($this->db, 0.0);

        Fixtures::dajWedke($this->db, $gracz['id'], $wedkaId, 5);

        for ($i = 0; $i < 5; $i++) {
            FishingService::cast($gracz['id'], $wedkaId, Fixtures::scriptedRandom([0.0]), $this->db);
        }

        $this->assertCount(2, Losowanie::historia($gracz['id'], 2, $this->db), 'limit=2 musi ciąć wynik');
        $this->assertCount(
            5,
            Losowanie::historia($gracz['id'], 999999, $this->db),
            'limit ponad 100 jest przycinany do 100, więc i tak mieszczą się wszystkie wpisy'
        );
    }
}
