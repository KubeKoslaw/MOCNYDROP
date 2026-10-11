<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use InvalidArgumentException;
use MocnyDrop\WeightedRoller;

/**
 * tests/WeightedRollerTest.php
 *
 * Testy najważniejszego elementu mechaniki: P(i) = w_i / suma(w).
 *
 * Generator losowy jest wstrzykiwany, więc testy są deterministyczne —
 * sprawdzają sam algorytm, a nie szczęście w danym uruchomieniu.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class WeightedRollerTest extends TestCase
{
    /** Ręczne granice przedziałów: przy wagach 50/30/20 progi to 0.5 i 0.8. */
    public function testLosujePoprawnePrzedzialy(): void
    {
        $pool = [
            ['przedmiot_id' => 1, 'nazwa' => 'A', 'waga' => 50.0],
            ['przedmiot_id' => 2, 'nazwa' => 'B', 'waga' => 30.0],
            ['przedmiot_id' => 3, 'nazwa' => 'C', 'waga' => 20.0],
        ];

        $this->assertSame('A', WeightedRoller::roll($pool, Fixtures::scriptedRandom([0.0]))['nazwa'], 'random=0.0 powinien trafić w pierwszy przedział');
        $this->assertSame('A', WeightedRoller::roll($pool, Fixtures::scriptedRandom([0.4999]))['nazwa'], 'random tuż pod 0.5 to nadal pierwsza ryba');
        $this->assertSame('B', WeightedRoller::roll($pool, Fixtures::scriptedRandom([0.5]))['nazwa'], 'random=0.5 to dokładnie początek drugiego przedziału');
        $this->assertSame('B', WeightedRoller::roll($pool, Fixtures::scriptedRandom([0.7999]))['nazwa'], 'random tuż pod 0.8 to nadal druga ryba');
        $this->assertSame('C', WeightedRoller::roll($pool, Fixtures::scriptedRandom([0.8]))['nazwa'], 'random=0.8 to początek trzeciego przedziału');
        $this->assertSame('C', WeightedRoller::roll($pool, Fixtures::scriptedRandom([0.999999]))['nazwa'], 'random bliski 1 musi trafić w ostatnią rybę');
    }

    /**
     * Waga 0.001 przy sumie 100.001 — czyli dokładnie przypadek poziomu
     * ULTRA MEGA NIESAMOWITA. Bez zabezpieczeń arytmetyki zmiennoprzecinkowej
     * ten element mógłby przepaść przy losowaniu bliskim górnej granicy.
     */
    public function testUltraRzadkiPrzedmiotJestOsiagalny(): void
    {
        $pool = [];

        foreach (Fixtures::PULA_README as $index => $fish) {
            $pool[] = [
                'przedmiot_id' => $index + 1,
                'nazwa' => $fish['nazwa'],
                'kod' => $fish['kod'],
                'waga' => self::wagaReadme($fish['kod']),
            ];
        }

        $wynik = WeightedRoller::roll($pool, Fixtures::scriptedRandom([0.999995]));

        $this->assertSame('ultra', $wynik['kod'], 'random=0.999995 powinien wylosować poziom ULTRA');
        $this->assertFloat(100.001, (float) $wynik['suma_wag'], 'Suma wag puli z README.md');
        $this->assertTrue((float) $wynik['szansa_procent'] > 0.0, 'Szansa ULTRA nie może wynosić 0, bo frontend pokazałby rybę jako nieosiągalną');
    }

    public function testSzansaProcentNieZnikaDlaUltraRzadkich(): void
    {
        $this->assertSame(50.0, WeightedRoller::chancePercent(50.0, 100.0), 'Prosty przypadek 50/100');
        $this->assertSame(0.0, WeightedRoller::chancePercent(0.0, 100.0), 'Waga 0 to szansa 0');
        $this->assertSame(0.0, WeightedRoller::chancePercent(10.0, 0.0), 'Dzielenie przez zero musi dać 0, a nie NAN');

        $ultra = WeightedRoller::chancePercent(0.001, 100.001);

        $this->assertTrue($ultra > 0.0, 'Szansa poziomu ULTRA musi być dodatnia');
        $this->assertTrue($ultra < 0.01, 'Szansa poziomu ULTRA musi być poniżej progu zaokrąglenia do 2 miejsc');
    }

    /**
     * Test statystyczny: 60 000 losowań musi odtworzyć wagi z README.md.
     * To jest główna ochrona przed zepsuciem balansu gry.
     */
    public function testRozkladZgadzaSieZWagami(): void
    {
        $pool = [
            ['przedmiot_id' => 1, 'kod' => 'pospolita',   'waga' => 50.0],
            ['przedmiot_id' => 2, 'kod' => 'przecietna',  'waga' => 30.0],
            ['przedmiot_id' => 3, 'kod' => 'niezla',      'waga' => 15.0],
            ['przedmiot_id' => 4, 'kod' => 'mityczna',    'waga' => 4.0],
            ['przedmiot_id' => 5, 'kod' => 'niesamowita', 'waga' => 1.0],
        ];

        $oczekiwane = [
            'pospolita' => 50.0,
            'przecietna' => 30.0,
            'niezla' => 15.0,
            'mityczna' => 4.0,
            'niesamowita' => 1.0,
        ];

        $licznik = array_fill_keys(array_keys($oczekiwane), 0);
        $proby = 60000;

        for ($i = 0; $i < $proby; $i++) {
            $licznik[WeightedRoller::roll($pool)['kod']]++;
        }

        $this->assertSame($proby, array_sum($licznik), 'Każde losowanie musi zwrócić dokładnie jeden element');

        foreach ($oczekiwane as $kod => $procent) {
            $oczekiwanaLiczba = $proby * ($procent / 100.0);

            // Tolerancja 20% to przy tych liczebnościach kilka odchyleń
            // standardowych: test nie będzie losowo czerwony, ale zły
            // algorytm (np. losowanie jednostajne) przejdzie tylko cudem.
            $this->assertBetween(
                $oczekiwanaLiczba * 0.8,
                $oczekiwanaLiczba * 1.2,
                (float) $licznik[$kod],
                sprintf('Rozkład dla "%s" (%.1f%% z %d prób)', $kod, $procent, $proby)
            );
        }
    }

    public function testOdrzucaNiepoprawnaPule(): void
    {
        $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): array => WeightedRoller::roll([]),
            'Pusta pula powinna być odrzucona'
        );

        $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): array => WeightedRoller::roll([['nazwa' => 'bez wagi']]),
            'Element bez klucza "waga" powinien być odrzucony'
        );

        $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): array => WeightedRoller::roll([['nazwa' => 'zero', 'waga' => 0.0]]),
            'Waga 0 powinna być odrzucona'
        );

        $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): array => WeightedRoller::roll([['nazwa' => 'minus', 'waga' => -5.0]]),
            'Waga ujemna powinna być odrzucona'
        );

        $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): array => WeightedRoller::roll([['nazwa' => 'nan', 'waga' => NAN]]),
            'Waga NAN powinna być odrzucona'
        );
    }

    public function testZwracaWynikWzbogaconyOMetadane(): void
    {
        $pool = [
            ['przedmiot_id' => 7, 'nazwa' => 'Anchois', 'wartosc' => 2.0, 'waga' => 50.0],
            ['przedmiot_id' => 8, 'nazwa' => 'Jesiotr', 'wartosc' => 400.0, 'waga' => 50.0],
        ];

        $wynik = WeightedRoller::roll($pool, Fixtures::scriptedRandom([0.0]));

        $this->assertSame(7, $wynik['przedmiot_id'], 'Powinien zwrócić pierwszy element puli');
        $this->assertArrayHasKey('suma_wag', $wynik, 'Wynik musi zawierać sumę wag — idzie do historii połowów');
        $this->assertArrayHasKey('szansa_procent', $wynik, 'Wynik musi zawierać szansę — idzie do frontendu');
        $this->assertFloat(100.0, (float) $wynik['suma_wag'], 'Suma wag');
        $this->assertFloat(50.0, (float) $wynik['szansa_procent'], 'Szansa przy równych wagach');
    }

    /** Wagi domyślne poziomów rzadkości — przepisane z README.md. */
    private static function wagaReadme(string $kod): float
    {
        return match ($kod) {
            'pospolita' => 50.0,
            'przecietna' => 30.0,
            'niezla' => 15.0,
            'mityczna' => 4.0,
            'niesamowita' => 1.0,
            'ultra' => 0.001,
            default => 1.0,
        };
    }
}
