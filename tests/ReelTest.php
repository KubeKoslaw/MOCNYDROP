<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use InvalidArgumentException;
use MocnyDrop\Reel;

/**
 * tests/ReelTest.php
 *
 * Testy paska ruletki — kontraktu między backendem a animacją @jgpostrach.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class ReelTest extends TestCase
{
    public function testBudujePasekOZadanejDlugosci(): void
    {
        $reel = Reel::build($this->pula(), $this->wygrana(), 60, 54);

        $this->assertCount(60, $reel, 'Pasek musi mieć dokładnie tyle pozycji, ile zamówiono');
    }

    public function testWygranaLezyNaWskazanymIndeksie(): void
    {
        $reel = Reel::build($this->pula(), $this->wygrana(), 60, 54);

        $this->assertSame('Marlin Błękitny', $reel[54]['nazwa'], 'Pod indeksem wygranej musi być wygrana ryba');
        $this->assertSame(4, $reel[54]['przedmiot_id'], 'Pod indeksem wygranej musi być poprawne id przedmioty');
    }

    public function testWygranaZawszeJestNaSwoimMiejscuNiezaleznieOdGeneratora(): void
    {
        // Generator zwracający skrajne wartości — sprawdza zabezpieczenie
        // przed offset == count(puli), które wyszłoby poza zakres tablicy.
        foreach ([[0.0], [0.999999], [1.0]] as $sequence) {
            $reel = Reel::build($this->pula(), $this->wygrana(), 20, 7, Fixtures::scriptedRandom($sequence));

            $this->assertCount(20, $reel, 'Długość paska przy skrajnym generatorze');
            $this->assertSame('Marlin Błękitny', $reel[7]['nazwa'], 'Pozycja wygranej przy skrajnym generatorze');
        }
    }

    /**
     * Pasek leci do przeglądarki w całości (60 pozycji na jeden rzut), więc
     * nie ma powodu dokładać do każdego elementu wag i sum — to dane
     * wewnętrzne losowania, a nie coś, czego potrzebuje animacja.
     */
    public function testPasekNieWyciekaDanychWewnetrznychLosowania(): void
    {
        $reel = Reel::build($this->pula(), $this->wygrana(), 10, 5);

        foreach ($reel as $index => $pozycja) {
            $this->assertFalse(isset($pozycja['waga']), 'Pozycja ' . $index . ' nie powinna zawierać wagi');
            $this->assertFalse(isset($pozycja['suma_wag']), 'Pozycja ' . $index . ' nie powinna zawierać sumy wag');
            $this->assertFalse(isset($pozycja['szansa_procent']), 'Pozycja ' . $index . ' nie powinna zawierać szansy');
        }
    }

    public function testKazdaPozycjaMaPolaPotrzebneDoAnimacji(): void
    {
        $reel = Reel::build($this->pula(), $this->wygrana(), 15, 9);

        foreach ($reel as $index => $pozycja) {
            foreach (['nazwa', 'ikona', 'rzadkosc', 'rzadkosc_kod', 'kolor', 'wartosc'] as $pole) {
                $this->assertArrayHasKey($pole, $pozycja, 'Pozycja ' . $index . ' musi mieć pole ' . $pole);
            }

            $this->assertTrue($pozycja['ikona'] !== '', 'Pozycja ' . $index . ' musi mieć ścieżkę ikony — inaczej animacja pokaże pusty kwadrat');
            $this->assertTrue($pozycja['kolor'] !== '', 'Pozycja ' . $index . ' musi mieć kolor rzadkości');
        }
    }

    public function testOdrzucaNiepoprawneParametry(): void
    {
        $pula = $this->pula();
        $wygrana = $this->wygrana();

        $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): array => Reel::build([], $wygrana, 10, 5),
            'Pusta pula powinna być odrzucona'
        );

        $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): array => Reel::build($pula, $wygrana, 0, 0),
            'Długość 0 powinna być odrzucona'
        );

        $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): array => Reel::build($pula, $wygrana, 10, 10),
            'Indeks równy długości jest poza zakresem'
        );

        $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): array => Reel::build($pula, $wygrana, 10, -1),
            'Indeks ujemny powinien być odrzucony'
        );
    }

    public function testDzialaDlaPaskaJednoelementowego(): void
    {
        $reel = Reel::build($this->pula(), $this->wygrana(), 1, 0);

        $this->assertCount(1, $reel, 'Minimalny sensowny pasek');
        $this->assertSame('Marlin Błękitny', $reel[0]['nazwa'], 'Jedyna pozycja to wygrana');
    }

    /** @return list<array<string, mixed>> */
    private function pula(): array
    {
        return [
            $this->element(1, 'Anchois', 'pospolita', 'Pospolita', '#94a3b8', 2.0, 'anchovy.png'),
            $this->element(2, 'Pstrąg Potokowy', 'przecietna', 'Przeciętna', '#22c55e', 8.0, 'brown_trout.png'),
            $this->element(3, 'Bass Małogębowy', 'niezla', 'Niezła', '#3b82f6', 25.0, 'smallmouth_bass.png'),
            $this->element(4, 'Marlin Błękitny', 'mityczna', 'Mityczna', '#a855f7', 120.0, 'blue_marlin.png'),
        ];
    }

    /** @return array<string, mixed> */
    private function wygrana(): array
    {
        // Zwycięzca taki, jakiego zwraca WeightedRoller::roll() — z wagami.
        return array_merge($this->element(4, 'Marlin Błękitny', 'mityczna', 'Mityczna', '#a855f7', 120.0, 'blue_marlin.png'), [
            'waga' => 4.0,
            'suma_wag' => 100.0,
            'szansa_procent' => 4.0,
        ]);
    }

    /** @return array<string, mixed> */
    private function element(int $id, string $nazwa, string $kod, string $rzadkosc, string $kolor, float $wartosc, string $ikona): array
    {
        return [
            'przedmiot_id' => $id,
            'nazwa' => $nazwa,
            'rzadkosc_kod' => $kod,
            'rzadkosc_nazwa' => $rzadkosc,
            'kolor' => $kolor,
            'wartosc' => $wartosc,
            'ikona_url' => 'assets/icons/' . $ikona,
        ];
    }
}
