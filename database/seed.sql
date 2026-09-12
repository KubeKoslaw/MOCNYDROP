-- ============================================================
-- PRZYKŁADOWE DANE POCZĄTKOWE (SEED) DLA MOCNY DROP
-- Prawdziwe gatunki ryb z polskich i europejskich wód!
-- ============================================================

-- 1. Użytkownicy testowi
-- Hasło dla obu kont: 'haslo123'
-- Hash wygenerowany przez password_hash('haslo123', PASSWORD_BCRYPT)
INSERT OR IGNORE INTO uzytkownicy (id, login, email, haslo_hash, rola, saldo) VALUES
(1, 'gracz', 'gracz@mocnydrop.pl', '$2y$10$KWpnETUC.osZXo/9KeHNGuhY/Qy2NhC8fUEXR6MothsYgxbZtREi6', 'user', 100.00),
(2, 'admin', 'admin@mocnydrop.pl', '$2y$10$KWpnETUC.osZXo/9KeHNGuhY/Qy2NhC8fUEXR6MothsYgxbZtREi6', 'admin', 500.00);

-- 2. Przedmioty (Prawdziwe gatunki ryb)
-- Rzadkości i wartości zgodne ze specyfikacją projektu
INSERT OR IGNORE INTO przedmioty (id, nazwa, rzadkosc, wartosc, ikona) VALUES
-- Zwykłe (pospolite ryby słodkowodne, niska wartość)
(1, 'Płotka Europejska', 'Zwykła', 2.50, '🐟'),
(2, 'Karaś Pospolity', 'Zwykła', 4.00, '🐟'),
(3, 'Okoń Garbus', 'Zwykła', 6.00, '🐟'),

-- Rzadkie (sprytne drapieżniki i większe ryby)
(4, 'Szczupak Pospolity', 'Rzadka', 18.00, '🎣'),
(5, 'Sandacz Europejski', 'Rzadka', 26.00, '🐟'),
(6, 'Pstrąg Potokowy', 'Rzadka', 32.00, '🐟'),

-- Epickie (wielkie okazy trofeowe i ryby wędrowne)
(7, 'Węgorz Europejski', 'Epicka', 75.00, '✨'),
(8, 'Łosoś Szlachetny', 'Epicka', 95.00, '🍣'),
(9, 'Sum Europejski', 'Epicka', 130.00, '🐋'),

-- Legendarne (najrzadsze i najcenniejsze ryby giganty)
(10, 'Jesiotr Ostronosy', 'Legendarna', 350.00, '🦈'),
(11, 'Bieługa Królewska', 'Legendarna', 500.00, '👑');

-- 3. Skrzynki (Tematyczne łowiska)
INSERT OR IGNORE INTO skrzynki (id, nazwa, opis, koszt, ikona) VALUES
(1, 'Skrzynia Jeziorna', 'Klasyczne ryby ze spokojnych wód jezior i stawów.', 10.00, '🎣'),
(2, 'Skrzynia Rzeczne Bystrza', 'Szybkie i waleczne ryby z nurtu polskich rzek.', 20.00, '🌊'),
(3, 'Skrzynia Morskie Głębiny', 'Najcenniejsze i najrzadsze okazy trofeowe.', 50.00, '👑');

-- 4. Przypisanie ryb do skrzynek wraz z wagami (w_i)
-- Skrzynia 1 (Jeziorna) - wagi: Zwykła (50), Rzadka (30), Epicka (15), Legendarna (5) -> Suma = 100
INSERT OR IGNORE INTO skrzynka_przedmioty (skrzynka_id, przedmiot_id, waga) VALUES
(1, 2, 50),   -- Karaś Pospolity (Zwykła): 50%
(1, 4, 30),   -- Szczupak Pospolity (Rzadka): 30%
(1, 7, 15),   -- Węgorz Europejski (Epicka): 15%
(1, 11, 5);   -- Bieługa Królewska (Legendarna): 5%

-- Skrzynia 2 (Rzeczne Bystrza)
INSERT OR IGNORE INTO skrzynka_przedmioty (skrzynka_id, przedmiot_id, waga) VALUES
(2, 1, 50),   -- Płotka Europejska (Zwykła): 50%
(2, 5, 25),   -- Sandacz Europejski (Rzadka): 25%
(2, 6, 15),   -- Pstrąg Potokowy (Rzadka): 15%
(2, 9, 8),    -- Sum Europejski (Epicka): 8%
(2, 10, 2);   -- Jesiotr Ostronosy (Legendarna): 2%

-- Skrzynia 3 (Morskie Głębiny)
INSERT OR IGNORE INTO skrzynka_przedmioty (skrzynka_id, przedmiot_id, waga) VALUES
(3, 3, 40),   -- Okoń Garbus (Zwykła): 40%
(3, 6, 35),   -- Pstrąg Potokowy (Rzadka): 35%
(3, 8, 18),   -- Łosoś Szlachetny (Epicka): 18%
(3, 11, 7);   -- Bieługa Królewska (Legendarna): 7%
