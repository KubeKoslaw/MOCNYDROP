<?php

declare(strict_types=1);

namespace MocnyDrop;

use InvalidArgumentException;

final class WeightedRoller
{

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

        if ($chosenKey === null) {
            $keys = array_keys($weights);
            $chosenKey = end($keys);
        }

        $result = $items[$chosenKey];

        $result['waga'] = $weights[$chosenKey];
        $result['suma_wag'] = $total;
        $result['szansa_procent'] = self::chancePercent($weights[$chosenKey], $total);

        return $result;
    }

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

    public static function randomFloat(): float
    {
        return mt_rand() / mt_getrandmax();
    }
}
