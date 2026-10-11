<?php

declare(strict_types=1);

namespace MocnyDrop;

use InvalidArgumentException;

/**
 * backend/Reel.php
 *
 * Pasek ruletki dla animacji łowienia.
 *
 * Łowienie ryb ma działać tak samo jak otwieranie skrzynek, więc backend
 * losuje wynik JEDNYM ważonym losowaniem, a dopiero potem dokłada pasek ryb
 * do przewinięcia. Animacja jest wyłącznie prezentacją gotowego wyniku —
 * nic nie jest losowane w przeglądarce, bo tam gracz mógłby to podmienić.
 *
 * Umowa z @jgpostrach:
 *   - `reel` ma dokładnie tyle pozycji, ile mówi długość,
 *   - wygrana ryba leży pod `reel_indeks_wygranej`,
 *   - pozostałe pozycje to losowe ryby z tej samej puli (szum wizualny),
 *   - frontend animuje przesunięcie paska tak, by środek wskaźnika stanął
 *     na `reel_indeks_wygranej`, i dopiero potem pokazuje komunikat o wygranej.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class Reel
{
    /**
     * @param list<array<string, mixed>> $pool        pula przedmiotów wędki
     * @param array<string, mixed>       $winner      wynik WeightedRoller::roll()
     * @param int                        $length      długość paska (>= 1)
     * @param int                        $winnerIndex indeks wygranej (0-based)
     * @param (callable(): float)|null   $random      generator liczby z [0, 1)
     * @return list<array<string, mixed>>
     */
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

            // floor(random() * n) daje 0..n-1, ale pilnujemy granicy na wypadek
            // wstrzykniętego generatora zwracającego dokładnie 1.0.
            if ($offset >= $poolSize) {
                $offset = $poolSize - 1;
            }

            $reel[] = self::strip($pool[$offset]);
        }

        return $reel;
    }

    /**
     * Pasek przewija się przez dziesiątki przedmiotów, więc frontend potrzebuje
     * tylko pól wizualnych. Celowo NIE wysyłamy wag i sum — to dane wewnętrzne
     * losowania i nie mają powodu wychodzić do przeglądarki w każdym elemencie.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
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
