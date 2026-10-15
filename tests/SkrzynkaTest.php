<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\{ApiException, FishingService, Losowanie, PosiadaneWedki, Portfel, Skrzynka, SkrzynkaWedka, TypLosowania, Uzytkownik, Wedka};

final class SkrzynkaTest extends TestCase
{
    private function zestaw(bool $daily = false, float $saldo = 100): array
    {
        $u = Fixtures::user($this->db, 'gracz', $saldo);
        $rod = Fixtures::wedkaZRyba($this->db);
        $this->db->exec("UPDATE wedki SET rekompensata = 3 WHERE id = $rod");
        $caseId = Fixtures::skrzynka($this->db, 'Skrzynka', $daily ? 0 : 20);
        if ($daily) $this->db->exec("UPDATE skrzynki SET dzienna = 1 WHERE id = $caseId");
        SkrzynkaWedka::ustawPule($caseId, [['wedka_id' => $rod, 'waga' => 1]], $this->db);
        return [Uzytkownik::znajdz($u['id']), $rod, Skrzynka::wymagaj($caseId)];
    }

    public function testOtwarcieOdblokowujeWedkeBezPrzyznawaniaRyby(): void
    {
        [$u, $rod, $case] = $this->zestaw();
        $result = $case->otworzSzczegolowo($u);
        $this->assertTrue(PosiadaneWedki::ma($u->id, $rod, $this->db), 'Wygrana staje się własnością gracza');
        $this->assertFloat(80, $result['saldo_po'], 'Opłata pobrana raz');
        $this->assertFalse($result['duplikat'], 'Pierwsza nagroda to nowe odblokowanie');
        $this->assertSame('wedka', $result['nagroda']['typ'], 'Jawny typ nagrody');
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM ekwipunek')->fetchColumn(), 'Skrzynka nie przyznaje ryb');
        $this->assertFloat(0, $result['rekompensata'], 'Odblokowanie nie jest wypłatą monet');
    }

    public function testDuplikatDajeRekompensateBezDrugiejWedki(): void
    {
        [$u, $rod, $case] = $this->zestaw();
        $case->otworzSzczegolowo($u);
        $result = $case->otworzSzczegolowo($u);
        $this->assertTrue($result['duplikat'], 'Ponowne trafienie jest duplikatem');
        $this->assertFloat(3, $result['rekompensata'], 'Rekompensata z katalogu');
        $this->assertFloat(63, Portfel::dlaUzytkownika($u->id)->saldo, '100 - 20 - 20 + 3');
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM uzytkownik_wedki')->fetchColumn(), 'Brak powielonego modelu');
        $this->assertFloat(.075, Losowanie::rtpDlaTypu(TypLosowania::Skrzynka), 'Tylko 3 monety wypłaty za 40 opłat');
    }

    public function testBrakSrodkowNiePrzyznajeNagrody(): void
    {
        [$u, $rod, $case] = $this->zestaw(false, 1);
        $this->assertThrows(ApiException::class, fn () => $case->otworzSzczegolowo($u), 'Brak środków', 'insufficient_funds');
        $this->assertFalse(PosiadaneWedki::ma($u->id, $rod, $this->db), 'Brak darmowego odblokowania');
        $this->assertFloat(1, Portfel::dlaUzytkownika($u->id)->saldo, 'Saldo zachowane');
    }

    public function testPotwierdzenieChroniPrzedPonownymPobraniemOplaty(): void
    {
        [$u, $rod, $case] = $this->zestaw();
        $a = $case->otworzSzczegolowo($u, requestId: 'request-123');
        $b = $case->otworzSzczegolowo($u, requestId: 'request-123');
        $this->assertSame(json_encode($a), json_encode($b), 'Ponowienie zwraca identyczny wynik');
        $this->assertFloat(80, Portfel::dlaUzytkownika($u->id)->saldo, 'Tylko jedna opłata');
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM historia_losowan')->fetchColumn(), 'Tylko jedno otwarcie');
    }

    public function testRequestIdNieMozeOznaczacDwochSkrzynek(): void
    {
        [$u, $rod, $case] = $this->zestaw();
        $case->otworzSzczegolowo($u, requestId: 'request-123');
        $other = Skrzynka::wymagaj(Fixtures::skrzynka($this->db, 'Inna'));
        $this->assertThrows(ApiException::class, fn () => $other->otworzSzczegolowo($u, requestId: 'request-123'), 'Klucz wiąże się ze skrzynką', 'request_id_conflict');
    }

    public function testDziennaOdlicza24GodzinyOdOdbioru(): void
    {
        [$u, $rod, $case] = $this->zestaw(true, 0);
        $a = $case->otworzSzczegolowo($u, requestId: 'daily-123');
        $this->assertFloat(0, $a['saldo_po'], 'Dzienna działa bez monet');
        $this->assertBetween(86399, 86400, $a['nastepny_odbior'] - time(), 'Ruchome 24 h');
        $this->assertThrows(ApiException::class, fn () => $case->otworzSzczegolowo($u), 'Drugi odbiór zabroniony', 'daily_case_cooldown');
        $repeat = $case->otworzSzczegolowo($u, requestId: 'daily-123');
        $this->assertSame($a['otwarcie']['id'], $repeat['otwarcie']['id'], 'Ponowienie z tym samym ID odtwarza odbiór');
        $last = time() - 86400;
        $this->db->exec("UPDATE odbiory_dzienne SET odebrano = $last");
        $b = $case->otworzSzczegolowo($u);
        $this->assertTrue($b['duplikat'], 'Po 24 h można odebrać ponownie');
    }

    public function testLimitDziennyJestWspolnyDlaSkrzynekIOsobnyDlaKont(): void
    {
        [$u, $rod, $case] = $this->zestaw(true);
        $case->otworzSzczegolowo($u);
        $id = Fixtures::skrzynka($this->db, 'Druga dzienna', 0);
        $this->db->exec("UPDATE skrzynki SET dzienna = 1 WHERE id = $id");
        SkrzynkaWedka::ustawPule($id, [['wedka_id' => $rod, 'waga' => 1]], $this->db);
        $other = Skrzynka::wymagaj($id);
        $this->assertThrows(ApiException::class, fn () => $other->otworzSzczegolowo($u), 'Nie można obejść zegara inną skrzynką', 'daily_case_cooldown');
        $new = Fixtures::user($this->db, 'drugi');
        $result = $other->otworzSzczegolowo(Uzytkownik::znajdz($new['id']));
        $this->assertFalse($result['duplikat'], 'Inne konto ma własny odbiór');
    }

    public function testAwariaZapisuWycofujeOplateWlasnoscIZegar(): void
    {
        [$u, $rod, $case] = $this->zestaw(true);
        $this->db->exec("CREATE TRIGGER fail_history BEFORE INSERT ON historia_losowan BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        $this->assertThrows(\PDOException::class, fn () => $case->otworzSzczegolowo($u, requestId: 'failure-123'), 'Awaria historii powoduje rollback');
        $this->assertFalse(PosiadaneWedki::ma($u->id, $rod, $this->db), 'Własność wycofana');
        foreach (['odbiory_dzienne', 'otwarcia_skrzynek', 'historia_losowan'] as $table) {
            $this->assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM $table")->fetchColumn(), 'Brak częściowego zapisu: '.$table);
        }
        $this->assertFloat(100, Portfel::dlaUzytkownika($u->id)->saldo, 'Saldo zachowane');
    }

    public function testHistoriaRozpoznajeWedke(): void
    {
        [$u, $rod, $case] = $this->zestaw();
        $case->otworzSzczegolowo($u);
        $history = Losowanie::historia($u->id)[0];
        $this->assertSame('wedka', $history['nagroda_typ'], 'Rodzaj nagrody w historii');
        $this->assertSame($rod, $history['wynik_wedka_id'], 'ID zdobytej wędki');
        $this->assertNull($history['przedmiot_id'], 'Wędka nie udaje ryby');
        $this->assertSame('Sprzęt', $history['nazwa'], 'Nazwa wędki w historii');
    }

    public function testNieMoznaLowicNieposiadanaWedka(): void
    {
        [$u, $rod, $case] = $this->zestaw();
        $this->assertThrows(ApiException::class, fn () => FishingService::cast($u->id, $rod), 'Ręczne ID nie omija własności', 'rod_not_owned');
        $case->otworzSzczegolowo($u);
        $result = FishingService::cast($u->id, $rod);
        $this->assertTrue($result['success'], 'Zdobyta wędka pozwala łowić');
        $other = Fixtures::user($this->db, 'inny');
        $this->assertThrows(ApiException::class, fn () => FishingService::cast($other['id'], $rod), 'Cudza wędka nie uprawnia do połowu', 'rod_not_owned');
    }

    public function testStartowaJestDostepnaDlaIstniejacegoKontaBezMonet(): void
    {
        [$u, $rod] = $this->zestaw(false, 0);
        $this->db->exec("UPDATE wedki SET czy_startowa = 1, koszt_uzycia = 0 WHERE id = $rod");
        $result = FishingService::cast($u->id, $rod);
        $this->assertTrue($result['success'], 'Startowa przyznana automatycznie');
        $this->assertFloat(0, $result['saldo_po'], 'Można kontynuować bez monet');
    }

    public function testWagiOrazWalidacjaPuli(): void
    {
        [$u, $rod, $case] = $this->zestaw();
        $second = Fixtures::wedkaZRyba($this->db, 'Druga');
        SkrzynkaWedka::ustawPule($case->id, [['wedka_id' => $rod, 'waga' => 9], ['wedka_id' => $second, 'waga' => 1]], $this->db);
        $this->assertFloat(90, $case->pula()[0]['szansa_procent'], 'Szansa pochodzi z wag');
        $r = $case->otworzSzczegolowo($u, fn () => .95);
        $this->assertSame($second, $r['nagroda']['wedka_id'], 'Losowanie respektuje przedziały');
        $this->assertThrows(ApiException::class, fn () => SkrzynkaWedka::ustawPule($case->id, [['wedka_id' => $rod, 'waga' => 0]], $this->db), 'Waga zero zabroniona', 'invalid_weight');
        $this->assertCount(2, $case->pula(), 'Błąd nie usuwa starej puli');
    }

    public function testPustaINieaktywnaNiePobierajaOplat(): void
    {
        [$u, $rod, $case] = $this->zestaw();
        $empty = Skrzynka::wymagaj(Fixtures::skrzynka($this->db, 'Pusta'));
        $this->assertThrows(ApiException::class, fn () => $empty->otworzSzczegolowo($u), 'Pusta pula', 'case_empty');
        $this->db->exec('UPDATE skrzynki SET aktywna = 0');
        $this->assertThrows(ApiException::class, fn () => Skrzynka::wymagaj($case->id)->otworzSzczegolowo($u), 'Nieaktywna', 'case_inactive');
        $this->assertFloat(100, Portfel::dlaUzytkownika($u->id)->saldo, 'Brak obciążeń');
    }
    public function testDarmoweWyplatyNieZawyzajaRtpPlatnychSkrzynek(): void
    {
        [$u, $rod, $case] = $this->zestaw();
        $case->otworzSzczegolowo($u);
        $case->otworzSzczegolowo($u);
        $id = Fixtures::skrzynka($this->db, 'Dzienna', 0);
        $this->db->exec("UPDATE skrzynki SET dzienna = 1 WHERE id = $id");
        SkrzynkaWedka::ustawPule($id, [['wedka_id' => $rod, 'waga' => 1]], $this->db);
        Skrzynka::wymagaj($id)->otworzSzczegolowo($u);
        $this->assertFloat(.075, Losowanie::rtpDlaTypu(TypLosowania::Skrzynka), '3 monety za 40 opłat, darmowy bonus osobno');
        $stats = Losowanie::statystyki();
        $this->assertFloat(3, $stats['lacznie']['darmowe_wyplaty'], 'Raport pokazuje darmowe wypłaty');
        $this->assertFloat(.075, $stats['lacznie']['rtp'], 'Globalne RTP również wyklucza bonusy');
        $this->assertSame(1, $stats['lacznie']['odblokowania_wedek'], 'Odblokowania liczone oddzielnie');
    }

    public function testAwariaPlatnegoOtwarciaZwracaOplate(): void
    {
        [$u, $rod, $case] = $this->zestaw();
        $this->db->exec("CREATE TRIGGER fail_history BEFORE INSERT ON historia_losowan BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        $this->assertThrows(\PDOException::class, fn () => $case->otworzSzczegolowo($u), 'Awaria historii');
        $this->assertFloat(100, Portfel::dlaUzytkownika($u->id)->saldo, 'Opłata zwrócona');
        $this->assertFalse(PosiadaneWedki::ma($u->id, $rod, $this->db), 'Brak nagrody po rollbacku');
    }

}
