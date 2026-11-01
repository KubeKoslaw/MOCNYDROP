PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS rzadkosci (
    kod             TEXT    PRIMARY KEY,
    nazwa           TEXT    NOT NULL UNIQUE,
    mnoznik         REAL    NOT NULL DEFAULT 1.0 CHECK (mnoznik > 0),
    waga_domyslna   REAL    NOT NULL CHECK (waga_domyslna > 0),
    kolor           TEXT    NOT NULL,
    kolejnosc       INTEGER NOT NULL UNIQUE
);

INSERT OR IGNORE INTO rzadkosci (kod, nazwa, mnoznik, waga_domyslna, kolor, kolejnosc) VALUES
    ('pospolita',   'Pospolita',                1.0, 50.0,   '#94a3b8', 1),
    ('przecietna',  'Przeciętna',               1.0, 30.0,   '#22c55e', 2),
    ('niezla',      'Niezła',                   1.0, 15.0,   '#3b82f6', 3),
    ('mityczna',    'Mityczna',                 1.0,  4.0,   '#a855f7', 4),
    ('niesamowita', 'Niesamowita',              1.0,  1.0,   '#eab308', 5),
    ('ultra',       'ULTRA MEGA NIESAMOWITA',   1.0,  0.001, '#22d3ee', 6);

CREATE TABLE IF NOT EXISTS uzytkownicy (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    login           TEXT    NOT NULL UNIQUE,
    email           TEXT    NOT NULL UNIQUE,
    haslo_hash      TEXT    NOT NULL,
    zablokowany     INTEGER NOT NULL DEFAULT 0 CHECK (zablokowany IN (0, 1)),
    rola            TEXT    NOT NULL DEFAULT 'user' CHECK (rola IN ('user', 'admin')),
    created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS portfele (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL UNIQUE,
    saldo           REAL    NOT NULL DEFAULT 0 CHECK (saldo >= 0),
    waluta          TEXT    NOT NULL DEFAULT 'monety',
    created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES uzytkownicy (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS oauth_kody (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    kod_hash        TEXT    NOT NULL UNIQUE,
    user_id         INTEGER NOT NULL,
    expires_at      TEXT    NOT NULL,
    czy_zuzyty      INTEGER NOT NULL DEFAULT 0 CHECK (czy_zuzyty IN (0, 1)),
    created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES uzytkownicy (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_oauth_kody_user ON oauth_kody (user_id);

CREATE TABLE IF NOT EXISTS oauth_tokeny (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id             INTEGER NOT NULL,
    token               TEXT    NOT NULL UNIQUE,
    refresh_token       TEXT    NOT NULL UNIQUE,
    expires_at          TEXT    NOT NULL,
    refresh_expires_at  TEXT    NOT NULL,
    created_at          TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES uzytkownicy (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_oauth_tokeny_user ON oauth_tokeny (user_id);

CREATE TABLE IF NOT EXISTS przedmioty (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    nazwa           TEXT    NOT NULL UNIQUE,
    rzadkosc_kod    TEXT    NOT NULL,
    wartosc         REAL    NOT NULL CHECK (wartosc >= 0),
    ikona           TEXT    NOT NULL DEFAULT '',
    opis            TEXT    NOT NULL DEFAULT '',
    czy_aktywny     INTEGER NOT NULL DEFAULT 1 CHECK (czy_aktywny IN (0, 1)),
    FOREIGN KEY (rzadkosc_kod) REFERENCES rzadkosci (kod) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_przedmioty_rzadkosc ON przedmioty (rzadkosc_kod);

CREATE TABLE IF NOT EXISTS wedki (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    nazwa           TEXT    NOT NULL UNIQUE,
    opis            TEXT    NOT NULL DEFAULT '',
    koszt_uzycia    REAL    NOT NULL CHECK (koszt_uzycia >= 0),
    ikona           TEXT    NOT NULL DEFAULT '',
    czy_aktywna     INTEGER NOT NULL DEFAULT 1 CHECK (czy_aktywna IN (0, 1)),
    kolejnosc       INTEGER NOT NULL DEFAULT 0,
    limit_zarzutow  INTEGER NOT NULL DEFAULT 10 CHECK (limit_zarzutow > 0),
    przedmiot_id    INTEGER REFERENCES przedmioty (id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_wedki_przedmiot ON wedki (przedmiot_id);

CREATE TABLE IF NOT EXISTS wedka_przedmioty (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    wedka_id        INTEGER NOT NULL,
    przedmiot_id    INTEGER NOT NULL,
    waga            REAL    CHECK (waga IS NULL OR waga > 0),
    UNIQUE (wedka_id, przedmiot_id),
    FOREIGN KEY (wedka_id)     REFERENCES wedki (id)      ON DELETE CASCADE,
    FOREIGN KEY (przedmiot_id) REFERENCES przedmioty (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_wedka_przedmioty_wedka ON wedka_przedmioty (wedka_id);

CREATE TABLE IF NOT EXISTS skrzynki (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    nazwa       TEXT    NOT NULL UNIQUE,
    opis        TEXT    NOT NULL DEFAULT '',
    cena        REAL    NOT NULL CHECK (cena >= 0),
    ikona       TEXT    NOT NULL DEFAULT '',
    aktywna     INTEGER NOT NULL DEFAULT 1 CHECK (aktywna IN (0, 1)),
    kolejnosc   INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS skrzynka_przedmioty (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    skrzynka_id     INTEGER NOT NULL,
    przedmiot_id    INTEGER NOT NULL,
    waga            REAL    CHECK (waga IS NULL OR waga > 0),
    UNIQUE (skrzynka_id, przedmiot_id),
    FOREIGN KEY (skrzynka_id)  REFERENCES skrzynki (id)    ON DELETE CASCADE,
    FOREIGN KEY (przedmiot_id) REFERENCES przedmioty (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_skrzynka_przedmioty_skrzynka ON skrzynka_przedmioty (skrzynka_id);

CREATE TABLE IF NOT EXISTS ekwipunek (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL,
    przedmiot_id    INTEGER NOT NULL,
    wartosc         REAL    NOT NULL CHECK (wartosc >= 0),
    data_nabycia    TEXT    NOT NULL DEFAULT (datetime('now')),
    sprzedany       INTEGER NOT NULL DEFAULT 0 CHECK (sprzedany IN (0, 1)),
    sprzedano_kiedy TEXT,
    zarzuty_pozostale INTEGER CHECK (zarzuty_pozostale IS NULL OR zarzuty_pozostale >= 0),
    FOREIGN KEY (user_id)      REFERENCES uzytkownicy (id) ON DELETE CASCADE,
    FOREIGN KEY (przedmiot_id) REFERENCES przedmioty (id)  ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_ekwipunek_user ON ekwipunek (user_id, sprzedany);

CREATE TABLE IF NOT EXISTS historia_losowan (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id           INTEGER NOT NULL,
    typ               TEXT    NOT NULL CHECK (typ IN ('skrzynka', 'wedka')),
    wynik_id          INTEGER NOT NULL,
    wedka_id          INTEGER,
    skrzynka_id       INTEGER,
    udane             INTEGER NOT NULL DEFAULT 1 CHECK (udane IN (0, 1)),
    koszt             REAL    NOT NULL,
    wygrana_wartosc   REAL    NOT NULL,
    waga_wylosowana   REAL    NOT NULL,
    suma_wag          REAL    NOT NULL,
    czas              TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id)  REFERENCES uzytkownicy (id) ON DELETE CASCADE,
    FOREIGN KEY (wynik_id) REFERENCES przedmioty (id)  ON DELETE RESTRICT,
    FOREIGN KEY (wedka_id) REFERENCES wedki (id)       ON DELETE CASCADE,
    FOREIGN KEY (skrzynka_id) REFERENCES skrzynki (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_historia_user ON historia_losowan (user_id, czas);
CREATE INDEX IF NOT EXISTS idx_historia_typ  ON historia_losowan (typ);

CREATE TABLE IF NOT EXISTS log_audytowy (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER,
    akcja       TEXT    NOT NULL,
    szczegoly   TEXT    NOT NULL DEFAULT '',
    czas        TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES uzytkownicy (id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_log_audytowy_czas ON log_audytowy (czas);

CREATE TABLE IF NOT EXISTS darmowe_skrzynki (
    user_id     INTEGER PRIMARY KEY,
    odebrano    TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES uzytkownicy (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ustawienia (
    klucz           TEXT    PRIMARY KEY,
    wartosc         TEXT    NOT NULL,
    opis            TEXT    NOT NULL DEFAULT ''
);

INSERT OR IGNORE INTO ustawienia (klucz, wartosc, opis) VALUES
    ('saldo_startowe',       '50.0',   'Monety przyznawane przy rejestracji (Portfel::utworz)'),
    ('waluta',               'monety', 'Nazwa waluty trzymanej w portfelu'),
    ('reel_dlugosc',         '60',     'Liczba pozycji paska ruletki dla animacji'),
    ('reel_indeks_wygranej', '54',     'Indeks (0-based), na którym staje wygrany przedmiot'),
    ('oauth_kod_waznosc_sekund',    '300',  'Ważność kodu autoryzacyjnego OAuth 2.0 w sekundach'),
    ('oauth_access_waznosc_sekund', '3600', 'Ważność access tokena OAuth 2.0 w sekundach'),
    ('oauth_refresh_waznosc_dni',   '30',   'Ważność refresh tokena OAuth 2.0 w dniach'),

    ('alert_seria_dlugosc',  '3',      'Długość serii trafień, która uruchamia alert'),
    ('alert_rzadkosc_kolejnosc', '4',  'Minimalna kolejność rzadkości liczonej do serii (4 = Mityczna)'),
    ('alert_rtp_prog',       '3.0',    'RTP gracza powyżej tego progu jest oznaczany jako anomalia'),
    ('alert_min_losowan',    '5',      'Minimalna liczba losowań gracza, żeby liczyć jego RTP');
