<?php

declare(strict_types=1);
namespace MocnyDrop\Tests;

use MocnyDrop\{Config, Database, Losowanie, SeedLoader, Skrzynka, TypLosowania, Wedka};
use PDO;

final class MigracjeTest extends TestCase
{
    public function testMigracjaZachowujeKontoEkwipunekIStaraHistorie(): void
    {
        $old = new PDO('sqlite::memory:');
        $old->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $old->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $old->exec(file_get_contents(__DIR__.'/fixtures/schema-v1.sql'));
        $u = Fixtures::user($old);
        $rod = Fixtures::readmeWedka($old);
        $fish = (int) $old->query('SELECT id FROM przedmioty ORDER BY id LIMIT 1')->fetchColumn();
        $old->exec("INSERT INTO ekwipunek (user_id, przedmiot_id, wartosc) VALUES ({$u['id']}, $fish, 123)");
        $case = Fixtures::skrzynka($old);
        $old->exec("INSERT INTO historia_losowan (id, user_id, typ, wynik_id, skrzynka_id, koszt, wygrana_wartosc, waga_wylosowana, suma_wag) VALUES (71, {$u['id']}, 'skrzynka', $fish, $case, 10, 123, 1, 100)");
        Database::applySchema($old);
        Database::applySchema($old);
        $this->assertSame(1, (int) $old->query('SELECT COUNT(*) FROM uzytkownicy')->fetchColumn(), 'Konto zachowane');
        $this->assertFloat(123, (float) $old->query('SELECT wartosc FROM ekwipunek')->fetchColumn(), 'Zamrożona wartość zachowana');
        $history = Losowanie::historia($u['id'], 20, $old)[0];
        $this->assertSame(71, $history['id'], 'ID historii zachowane');
        $this->assertSame('przedmiot', $history['nagroda_typ'], 'Stare skrzynki nadal pokazują rybę');
        $this->assertSame('Anchois', $history['nazwa'], 'Stara nagroda dostępna');
        $this->assertSame(1, (int) $old->query('PRAGMA foreign_keys')->fetchColumn(), 'Kontrola kluczy ponownie włączona');
        $this->assertCount(0, $old->query('PRAGMA foreign_key_check')->fetchAll(), 'Brak zerwanych relacji');
    }

    public function testRzeczywisteSeedyMajaZbalansowanePuleIDzialajaPoPonownymImporcie(): void
    {
        SeedLoader::load($this->db);
        SeedLoader::load($this->db);
        foreach (Wedka::aktywne($this->db) as $rod) {
            $pool = $rod->pula();
            $total = array_sum(array_column($pool, 'waga'));
            $ev = 0;
            foreach ($pool as $item) {
                $value = $item['wartosc'] * $item['mnoznik'];
                $probability = $item['waga'] / $total;
                $ev += $value * $probability;
                if ($value >= 5000) {
                    $this->assertTrue($probability < .0001, 'Drop za tysiące monet rzadziej niż raz na 10 tys.: '.$item['nazwa']);
                }
            }
            if ($rod->kosztUzycia > 0) {
                $this->assertBetween(.899, .901, $ev / $rod->kosztUzycia, 'Średni zwrot płatnej wędki około 90%: '.$rod->nazwa);
            }
        }
        $this->assertCount(4, Skrzynka::aktywne(), 'Import nie duplikuje skrzynek');
        $daily = array_values(array_filter(Skrzynka::aktywne(), fn ($s) => $s->dzienna));
        $this->assertCount(1, $daily, 'Jedna dzienna skrzynka');
        foreach (Skrzynka::aktywne() as $case) {
            $pool = $case->pula();
            $this->assertTrue(count($pool) > 0, 'Każda skrzynka ma używalne wędki');
            $ev = array_sum(array_map(fn ($r) => $r['waga'] * $r['rekompensata'], $pool)) / array_sum(array_column($pool, 'waga'));
            if (!$case->dzienna) $this->assertTrue($ev < $case->cena, 'Duplikaty nie tworzą zyskownej pętli');
        }
    }
}
