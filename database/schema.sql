-- ============================================================================
-- MOCNY DROP — schemat bazy SQLite
-- Właściciel: @KubeKoslaw (Core Backend)
--
-- Import ręczny:    sqlite3 database/mocnydrop.sqlite < database/schema.sql
-- Import skryptem:  php database/migrate.php
-- CI sprawdza, że plik importuje się do pustej bazy:
--                   sqlite3 :memory: < database/schema.sql
--
-- Konwencja: identyfikatory tabel i kolumn ASCII, dane i komentarze po polsku.
-- Plik bazy (*.sqlite) jest w .gitignore — schema.sql jest jedynym źródłem.
-- ============================================================================

PRAGMA foreign_keys = ON;

-- ----------------------------------------------------------------------------
-- 1. rzadkosci — 6 poziomów rzadkości z README.md
--
--    Wagi są UŁAMKOWE (najrzadszy poziom to 0.001), dlatego kolumna musi być
--    REAL, a nie INTEGER. Prawdopodobieństwo wylosowania ryby i:
--
--        P(i) = w_i / suma(w)
--
--    Tabela jest źródłem prawdy w runtime; backend/Rarity.php tylko ją czyta.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rzadkosci (
    kod             TEXT    PRIMARY KEY,
    nazwa           TEXT    NOT NULL UNIQUE,
    waga_domyslna   REAL    NOT NULL CHECK (waga_domyslna > 0),
    kolor           TEXT    NOT NULL,
    kolejnosc       INTEGER NOT NULL UNIQUE
);

INSERT OR IGNORE INTO rzadkosci (kod, nazwa, waga_domyslna, kolor, kolejnosc) VALUES
    ('pospolita',   'Pospolita',                50.0,   '#94a3b8', 1),
    ('przecietna',  'Przeciętna',               30.0,   '#22c55e', 2),
    ('niezla',      'Niezła',                   15.0,   '#3b82f6', 3),
    ('mityczna',    'Mityczna',                  4.0,   '#a855f7', 4),
    ('niesamowita', 'Niesamowita',               1.0,   '#eab308', 5),
    ('ultra',       'ULTRA MEGA NIESAMOWITA',    0.001, '#22d3ee', 6);

-- ----------------------------------------------------------------------------
-- 2. uzytkownicy — konta graczy i stan portfela
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS uzytkownicy (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    login           TEXT    NOT NULL UNIQUE,
    email           TEXT    NOT NULL UNIQUE,
    haslo_hash      TEXT    NOT NULL,
    rola            TEXT    NOT NULL DEFAULT 'user' CHECK (rola IN ('user', 'admin')),
    saldo           REAL    NOT NULL DEFAULT 50.0 CHECK (saldo >= 0),
    czy_zablokowany INTEGER NOT NULL DEFAULT 0 CHECK (czy_zablokowany IN (0, 1)),
    created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- ----------------------------------------------------------------------------
-- 3. tokeny — tokeny Bearer do autoryzacji zapytań z frontendu
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tokeny (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL,
    token           TEXT    NOT NULL UNIQUE,
    expires_at      TEXT    NOT NULL,
    created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES uzytkownicy (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_tokeny_user ON tokeny (user_id);

-- ----------------------------------------------------------------------------
-- 4. lowiska — miejsca łowienia. To jest odpowiednik "skrzynki":
--    każde łowisko ma swój koszt zarzutu i własną pulę ryb z wagami.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lowiska (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    nazwa           TEXT    NOT NULL UNIQUE,
    opis            TEXT    NOT NULL DEFAULT '',
    koszt_zarzutu   REAL    NOT NULL CHECK (koszt_zarzutu >= 0),
    ikona           TEXT    NOT NULL DEFAULT '',
    czy_aktywne     INTEGER NOT NULL DEFAULT 1 CHECK (czy_aktywne IN (0, 1)),
    kolejnosc       INTEGER NOT NULL DEFAULT 0
);

-- ----------------------------------------------------------------------------
-- 5. ryby — katalog wszystkich ryb (nagród). To odpowiednik "przedmiotu".
--    ikona = nazwa pliku w assets/icons/, np. 'blue_marlin.png'.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ryby (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    nazwa           TEXT    NOT NULL UNIQUE,
    rzadkosc_kod    TEXT    NOT NULL,
    wartosc         REAL    NOT NULL CHECK (wartosc >= 0),
    ikona           TEXT    NOT NULL DEFAULT '',
    opis            TEXT    NOT NULL DEFAULT '',
    czy_aktywna     INTEGER NOT NULL DEFAULT 1 CHECK (czy_aktywna IN (0, 1)),
    FOREIGN KEY (rzadkosc_kod) REFERENCES rzadkosci (kod) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_ryby_rzadkosc ON ryby (rzadkosc_kod);

-- ----------------------------------------------------------------------------
-- 6. lowisko_ryby — która ryba występuje na którym łowisku i z jaką wagą w_i.
--    waga NULL = użyj waga_domyslna z tabeli rzadkosci.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lowisko_ryby (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    lowisko_id      INTEGER NOT NULL,
    ryba_id         INTEGER NOT NULL,
    waga            REAL    CHECK (waga IS NULL OR waga > 0),
    UNIQUE (lowisko_id, ryba_id),
    FOREIGN KEY (lowisko_id) REFERENCES lowiska (id) ON DELETE CASCADE,
    FOREIGN KEY (ryba_id)   REFERENCES ryby (id)     ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_lowisko_ryby_lowisko ON lowisko_ryby (lowisko_id);

-- ----------------------------------------------------------------------------
-- 7. ekwipunek — ryby złowione przez gracza.
--    cena_sprzedazy zapamiętuje wartość ryby w chwili połowu, żeby późniejsza
--    zmiana balansu nie zmieniała wartości tego, co gracz już złowił.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ekwipunek (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL,
    ryba_id         INTEGER NOT NULL,
    cena_sprzedazy  REAL    NOT NULL CHECK (cena_sprzedazy >= 0),
    czy_sprzedana   INTEGER NOT NULL DEFAULT 0 CHECK (czy_sprzedana IN (0, 1)),
    zdobyto_kiedy   TEXT    NOT NULL DEFAULT (datetime('now')),
    sprzedano_kiedy TEXT,
    FOREIGN KEY (user_id) REFERENCES uzytkownicy (id) ON DELETE CASCADE,
    FOREIGN KEY (ryba_id) REFERENCES ryby (id)        ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_ekwipunek_user ON ekwipunek (user_id, czy_sprzedana);

-- ----------------------------------------------------------------------------
-- 8. historia_polowow — pełny rejestr zarzutów (audyt + test rozkładu losowań).
--    waga_wylosowana i suma_wag pozwalają odtworzyć P(i) dokładnie tak,
--    jak zostało wyliczone w momencie rzutu.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS historia_polowow (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id           INTEGER NOT NULL,
    lowisko_id        INTEGER NOT NULL,
    ryba_id           INTEGER NOT NULL,
    koszt             REAL    NOT NULL,
    wygrana_wartosc   REAL    NOT NULL,
    waga_wylosowana   REAL    NOT NULL,
    suma_wag          REAL    NOT NULL,
    data              TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id)    REFERENCES uzytkownicy (id) ON DELETE CASCADE,
    FOREIGN KEY (lowisko_id) REFERENCES lowiska (id)     ON DELETE CASCADE,
    FOREIGN KEY (ryba_id)    REFERENCES ryby (id)        ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_historia_user ON historia_polowow (user_id, data);

-- ----------------------------------------------------------------------------
-- 9. ustawienia — parametry mechaniki czytane przez backend (zmiana bez redeployu)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ustawienia (
    klucz           TEXT    PRIMARY KEY,
    wartosc         TEXT    NOT NULL,
    opis            TEXT    NOT NULL DEFAULT ''
);

INSERT OR IGNORE INTO ustawienia (klucz, wartosc, opis) VALUES
    ('saldo_startowe',       '50.0', 'Monety przyznawane przy rejestracji'),
    ('reel_dlugosc',         '60',   'Liczba pozycji paska ruletki dla animacji'),
    ('reel_indeks_wygranej', '54',   'Indeks (0-based), na którym staje wygrana ryba'),
    ('token_waznosc_godz',   '72',   'Ważność tokena Bearer w godzinach');
