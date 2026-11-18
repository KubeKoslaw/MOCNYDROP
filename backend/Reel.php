<?php

declare(strict_types=1);

namespace MocnyDrop;

use InvalidArgumentException;

final class Reel
{

    public static function build(
        array $pool,
        array $winner,
        int $length,
        int $winnerIndex,
        ?callable $random = null
    ): array {
        if ($pool === []) {
            throw new InvalidArgumentException('Nie można zbudować paska ruletki z pustej puli.');
        }

        if ($length < 1) {
            throw new InvalidArgumentException('Długość paska ruletki musi być >= 1.');
        }

        if ($winnerIndex < 0 || $winnerIndex >= $length) {
            throw new InvalidArgumentException(
                sprintf('Indeks wygranej %d poza zakresem paska o długości %d.', $winnerIndex, $length)
            );
        }

        $random ??= [WeightedRoller::class, 'randomFloat'];

        $poolSize = count($pool);
        $reel = [];

        for ($i = 0; $i < $length; $i++) {
            if ($i === $winnerIndex) {
                $reel[] = self::strip($winner);
                continue;
            }

            $offset = (int) floor((float) $random() * $poolSize);

            if ($offset >= $poolSize) {
                $offset = $poolSize - 1;
            }

            $reel[] = self::strip($pool[$offset]);
        }

        return $reel;
    }

    private static function strip(array $item): array
    {
        return [
            'przedmiot_id' => isset($item['przedmiot_id']) ? (int) $item['przedmiot_id'] : null,
            'nazwa' => (string) ($item['nazwa'] ?? ''),
            'ikona' => (string) ($item['ikona_url'] ?? ''),
            'rzadkosc' => (string) ($item['rzadkosc_nazwa'] ?? ''),
            'rzadkosc_kod' => (string) ($item['rzadkosc_kod'] ?? ''),
            'kolor' => (string) ($item['kolor'] ?? ''),
            'wartosc' => (float) ($item['wartosc'] ?? 0.0),
        ];
    }
}
