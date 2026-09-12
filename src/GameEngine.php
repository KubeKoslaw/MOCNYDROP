<?php
/**
 * src/GameEngine.php
 * Silnik losowania nagród oparty o ważony rozkład prawdopodobieństwa.
 * 
 * Zgodnie ze wzorem z README:
 * P(i) = w_i / suma(w_j)
 */

class GameEngine {
    /**
     * Wybiera jeden przedmiot ze skrzynki na podstawie wag losowania.
     * 
     * @param array $items Lista przedmiotów ze skrzynki, każdy musi mieć pole 'waga'
     * @return array Wylosowany przedmiot
     */
    public static function rollItem(array $items): array {
        if (empty($items)) {
            throw new Exception("Skrzynka jest pusta — brak przedmiotów do losowania.");
        }

        // 1. Obliczamy sumę wag wszystkich przedmiotów: suma(w_j)
        $totalWeight = 0;
        foreach ($items as $item) {
            $totalWeight += (int)$item['waga'];
        }

        if ($totalWeight <= 0) {
            throw new Exception("Suma wag przedmiotów musi być większa od zera.");
        }

        // 2. Losujemy bezpieczną liczbę całkowitą od 1 do totalWeight (CSPRNG)
        $roll = random_int(1, $totalWeight);

        // 3. Sprawdzamy, w który przedział dystrybuanty wpadła wylosowana liczba
        $currentSum = 0;
        foreach ($items as $item) {
            $currentSum += (int)$item['waga'];
            if ($roll <= $currentSum) {
                // Obliczamy szansę procentową dla informacji gracza
                $item['szansa_procent'] = round(($item['waga'] / $totalWeight) * 100, 2);
                $item['wylosowany_roll'] = $roll;
                $item['suma_wag'] = $totalWeight;
                return $item;
            }
        }

        // Zabezpieczenie (w praktyce pętla zawsze zwróci wynik)
        return $items[array_key_last($items)];
    }
}
