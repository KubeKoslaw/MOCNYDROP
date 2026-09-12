-- ============================================================
-- BAZA DANYCH: MOCNY DROP (SQLite)
-- Prosty i czytelny schemat dla projektu szkolnego
-- ============================================================

-- 1. Użytkownicy: przechowuje konta graczy i ich stan portfela
CREATE TABLE IF NOT EXISTS uzytkownicy (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    login TEXT NOT NULL UNIQUE,
    email TEXT NOT NULL UNIQUE,
    haslo_hash TEXT NOT NULL,
    rola TEXT NOT NULL DEFAULT 'user',      -- 'user' (gracz) lub 'admin' (administrator)
    saldo REAL NOT NULL DEFAULT 50.00,       -- Wirtualna waluta na start (np. 50 monet)
    czy_zablokowany INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 2. Tokeny logowania: proste tokeny Bearer do autoryzacji zapytań z frontendu
CREATE TABLE IF NOT EXISTS tokeny (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token TEXT NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES uzytkownicy(id) ON DELETE CASCADE
);

-- 3. Przedmioty: katalog wszystkich możliwych nagród w grze
CREATE TABLE IF NOT EXISTS przedmioty (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nazwa TEXT NOT NULL,
    rzadkosc TEXT NOT NULL,                  -- 'Zwykła', 'Rzadka', 'Epicka', 'Legendarna'
    wartosc REAL NOT NULL,                  -- Wartość w wirtualnych monetach
    ikona TEXT DEFAULT ''                   -- Nazwa pliku lub ikona emoji
);

-- 4. Skrzynki: rodzaje skrzynek dostępnych do kupienia
CREATE TABLE IF NOT EXISTS skrzynki (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nazwa TEXT NOT NULL,
    opis TEXT DEFAULT '',
    koszt REAL NOT NULL,                    -- Koszt jednego otwarcia (c ze wzoru)
    ikona TEXT DEFAULT ''
);

-- 5. Zawartość skrzynek i wagi: która skrzynka zawiera jakie przedmioty i jaka jest ich waga w_i
CREATE TABLE IF NOT EXISTS skrzynka_przedmioty (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    skrzynka_id INTEGER NOT NULL,
    przedmiot_id INTEGER NOT NULL,
    waga INTEGER NOT NULL,                  -- Waga w_i ze wzoru P(i) = w_i / suma_wag
    FOREIGN KEY (skrzynka_id) REFERENCES skrzynki(id) ON DELETE CASCADE,
    FOREIGN KEY (przedmiot_id) REFERENCES przedmioty(id) ON DELETE CASCADE,
    UNIQUE(skrzynka_id, przedmiot_id)
);

-- 6. Ekwipunek: przedmioty posiadane przez gracza
CREATE TABLE IF NOT EXISTS ekwipunek (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    przedmiot_id INTEGER NOT NULL,
    zdobyto_kiedy DATETIME DEFAULT CURRENT_TIMESTAMP,
    czy_sprzedany INTEGER NOT NULL DEFAULT 0, -- 0 = w ekwipunku, 1 = sprzedany za walutę
    FOREIGN KEY (user_id) REFERENCES uzytkownicy(id) ON DELETE CASCADE,
    FOREIGN KEY (przedmiot_id) REFERENCES przedmioty(id) ON DELETE CASCADE
);

-- 7. Historia losowań: pełny rejestr otwartych skrzynek (audyt)
CREATE TABLE IF NOT EXISTS historia_losowan (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    skrzynka_id INTEGER,                   -- Może być NULL przy łowieniu lub upgrade
    przedmiot_id INTEGER NOT NULL,
    koszt REAL NOT NULL,
    wygrana_wartosc REAL NOT NULL,
    data DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES uzytkownicy(id) ON DELETE CASCADE,
    FOREIGN KEY (skrzynka_id) REFERENCES skrzynki(id) ON DELETE CASCADE,
    FOREIGN KEY (przedmiot_id) REFERENCES przedmioty(id) ON DELETE CASCADE
);
