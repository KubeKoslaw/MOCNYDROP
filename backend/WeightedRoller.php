<?php

declare(strict_types=1);

namespace MocnyDrop;

use InvalidArgumentException;

/**
 * backend/WeightedRoller.php
 *
 * Sedno mechaniki — ważne losowanie z README.md:
 *
 *     P(i) = w_i / suma(w_j)
 *
 * Łowienie ryb działa tu dokładnie tak samo jak otwieranie skrzynek: na
 * wejściu jest pula kandydatów z wagami, na wyjściu dokładnie jeden z nich.
 * Nic nie jest specyficzne dla ryb, więc tryb skrzynek i upgrader podepną
 * się pod tę samą klasę bez zmian.
 *
 * Wagi są zmiennoprzecinkowe, bo najrzadszy poziom ma wagę 0.001.
 * Generator liczb losowych można wstrzyknąć — dzięki temu testy są
 * powtarzalne i sprawdzają sam algorytm, a nie szczęście.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class WeightedRoller
{
    /**
     * Losuje jeden element z puli zgodnie z wagami.
     *
     * @param list<array<string, mixed>> $items  każdy element musi mieć klucz 'waga' > 0
     * @param (callable(): float)|null   $random generator liczby z przedziału [0, 1)
     * @return array<string, mixed> wylosowany element + 'suma_wag' i 'szansa_procent'
     */
    public static function roll(array $items, ?callable $random = null): array
    {
        if ($items === []) {
            throw new InvalidArgumentException('Pula losowania jest pusta.');
        }

        $weights = [];
        $total = 0.0;

        foreach ($items as $key => $item) {
            if (!is_array($item) || !array_key_exists('waga', $item)) {
                throw new InvalidArgumentException('Element puli nie ma klucza "waga".');
            }

            $weight = (float) $item['waga'];

            if (!is_finite($weight) || $weight <= 0) {
                throw new InvalidArgumentException('Waga musi być dodatnią, skończoną liczbą.');
            }

            $weights[$key] = $weight;
            $total += $weight;
        }

        if (!is_finite($total) || $total <= 0) {
            throw new InvalidArgumentException('Suma wag musi być dodatnią, skończoną liczbą.');
        }

        $random ??= [self::class, 'randomFloat'];

        $pick = (float) $random() * $total;

        // Zabezpieczenia przed arytmetyką zmiennoprzecinkową: przy wadze 0.001
        // i sumie 100.001 błąd zaokrąglenia potrafi wypchnąć $pick poza zakres.
        if ($pick < 0.0) {
            $pick = 0.0;
        } elseif ($pick >= $total) {
            $pick = $total * (1 - PHP_FLOAT_EPSILON);
        }

        $running = 0.0;
        $chosenKey = null;

        foreach ($weights as $key => $weight) {
            $running += $weight;

            if ($pick < $running) {
                $chosenKey = $key;
                break;
            }
        }

        // Gdyby suma narastająca nie dogoniła $pick przez zaokrąglenia,
        // bierzemy ostatni element — wygrana nie może przepaść.
        if ($chosenKey === null) {
            $keys = array_keys($weights);
            $chosenKey = end($keys);
        }

        /** @var array<string, mixed> $result */
        $result = $items[$chosenKey];

        $result['waga'] = $weights[$chosenKey];
        $result['suma_wag'] = $total;
        $result['szansa_procent'] = self::chancePercent($weights[$chosenKey], $total);

        return $result;
    }

    /**
     * Szansa w procentach.
     *
     * Dla poziomu ULTRA MEGA NIESAMOWITA wynik to 0.000999...%, więc zwykłe
     * round(x, 2) pokazałoby graczowi 0%. Poniżej progu 0.01% zaokrąglamy
     * do 4 miejsc, żeby najrzadsza ryba nigdy nie wyglądała na niemożliwą.
     */
    public static function chancePercent(float $weight, float $total): float
    {
        if (!is_finite($total) || $total <= 0 || !is_finite($weight) || $weight < 0) {
            return 0.0;
        }

        $percent = ($weight / $total) * 100;

        if ($percent > 0 && $percent < 0.01) {
            return round($percent, 4);
        }

        return round($percent, 3);
    }

    /**
     * Szanse wszystkich elementów puli — frontend pokazuje je przy rybach,
     * żeby gracz wiedział, na co poluje.
     *
     * @param list<array<string, mixed>> $items
     * @return list<float>
     */
    public static function chances(array $items): array
    {
        $total = 0.0;

        foreach ($items as $item) {
            $total += (float) ($item['waga'] ?? 0.0);
        }

        $out = [];

        foreach ($items as $item) {
            $out[] = self::chancePercent((float) ($item['waga'] ?? 0.0), $total);
        }

        return $out;
    }

    /** Domyślny generator: liczba z [0, 1). Wydzielony, żeby dało się go wstrzyknąć w testach. */
    public static function randomFloat(): float
    {
        return mt_rand() / mt_getrandmax();
    }
}
