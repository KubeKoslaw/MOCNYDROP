-- ============================================================================
-- MOCNY DROP — schemat bazy SQLite
-- Właściciel: @KubeKoslaw (Core Backend)
--
-- Import ręczny:    sqlite3 database/mocnydrop.sqlite < database/schema.sql
-- Import skryptem:  php database/migrate.php
-- CI sprawdza:      sqlite3 :memory: < database/schema.sql
--
-- Konwencja: identyfikatory tabel i kolumn ASCII, dane i komentarze po polsku.
-- Plik bazy (*.sqlite) jest w .gitignore — schema.sql jest jedynym źródłem.
--
-- STRUKTURA ODZWIERCIEDLA DIAGRAM KLAS (MOCNYDROP_diagram_klas.drawio z main):
-- jedna tabela na klasę domenową, nazwy kolumn jak atrybuty z diagramu.
--
--   klasa (diagram)   tabela             atrybuty z diagramu
--   ----------------  -----------------  --------------------------------------
--   Rzadkosc          rzadkosci          nazwa, mnoznik
--   Uzytkownik        uzytkownicy        login, email, haslo_hash, zablokowany, rola
--   Portfel           portfele           saldo, waluta
--   Token             tokeny             token, expires_at
--   Przedmiot         przedmioty         nazwa, wartosc, rzadkosc
--   Wedka             wedki              nazwa, kosztUzycia
--   Ekwipunek         ekwipunek          data_nabycia, sprzedany
--   Skrzynka          skrzynki           nazwa, cena, ikona, aktywna
--   SkrzynkaPrzedmiot skrzynka_przedmioty waga
--   Losowanie         historia_losowan   czas, typ, wynik
--   LogAudytowy       log_audytowy       akcja, czas
--
-- Asocjacje z diagramu wymuszają klucze obce:
--   Uzytkownik ◆— Portfel (1:1)       portfele.user_id UNIQUE
--   Uzytkownik ◆— Token (1:0..*)      tokeny.user_id
--   Uzytkownik ◆— Ekwipunek (1:1..*)  ekwipunek.user_id
--   Uzytkownik — Losowanie (historia) historia_losowan.user_id
--   Losowanie —wynik→ Przedmiot (*:1) historia_losowan.wynik_id
--   Przedmiot — Rzadkosc (*:1)        przedmioty.rzadkosc_kod
--   Administrator —|> Uzytkownik      uzytkownicy.rola = 'admin'
--   Skrzynka — Losowanie (*:1)        historia_losowan.skrzynka_id
--   Skrzynka — SkrzynkaPrzedmiot (*)  skrzynka_przedmioty.skrzynka_id
--   SkrzynkaPrzedmiot — Przedmiot     skrzynka_przedmioty.przedmiot_id
-- ============================================================================

PRAGMA foreign_keys = ON;

-- ----------------------------------------------------------------------------
-- 1. rzadkosci — klasa Rzadkosc. Sześć poziomów rzadkości z README.md
--
--    `mnoznik` (atrybut z diagramu) skaluje wartość przedmiotu:
--        Przedmiot::obliczWartosc() = wartosc * rzadkosc.mnoznik
--    Domyślnie 1.0, więc ekonomia nie zmienia się, dopóki ktoś go nie przestawi.
--
--    `waga_domyslna` to waga w losowaniu P(i) = w_i / suma(w). Wagi są
--    UŁAMKOWE (najrzadszy poziom to 0.001), dlatego kolumna musi być REAL.
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- 2. uzytkownicy — klasa Uzytkownik. Administrator to ten sam wiersz z
--    rola = 'admin' (generalizacja Administrator —|> Uzytkownik z diagramu).
--
--    UWAGA: saldo NIE leży tutaj. Diagram ma Portfel jako osobną klasę w
--    kompozycji 1:1 z Uzytkownik, więc pieniądze są w tabeli `portfele`.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS uzytkownicy (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    login           TEXT    NOT NULL UNIQUE,
    email           TEXT    NOT NULL UNIQUE,
    haslo_hash      TEXT    NOT NULL,
    zablokowany     INTEGER NOT NULL DEFAULT 0 CHECK (zablokowany IN (0, 1)),
    rola            TEXT    NOT NULL DEFAULT 'user' CHECK (rola IN ('user', 'admin')),
    created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- ----------------------------------------------------------------------------
-- 3. portfele — klasa Portfel (id, saldo, waluta).
--    user_id UNIQUE wymusza kompozycję 1:1 z diagramu: jeden gracz = jeden portfel.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS portfele (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL UNIQUE,
    saldo           REAL    NOT NULL DEFAULT 0 CHECK (saldo >= 0),
    waluta          TEXT    NOT NULL DEFAULT 'monety',
    created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES uzytkownicy (id) ON DELETE CASCADE
);

-- ----------------------------------------------------------------------------
-- 4. tokeny — klasa Token (token, expires_at).
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
-- 5. przedmioty — klasa Przedmiot (nazwa, wartosc, rzadkosc).
--    W grze przedmiotami są ryby; ikona = nazwa pliku w assets/icons/.
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- 6. wedki — klasa Wedka (nazwa, kosztUzycia -> koszt_uzycia).
--    Każda wędka ma swój koszt użycia i własną pulę przedmiotów z wagami.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wedki (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    nazwa           TEXT    NOT NULL UNIQUE,
    opis            TEXT    NOT NULL DEFAULT '',
    koszt_uzycia    REAL    NOT NULL CHECK (koszt_uzycia >= 0),
    czy_startowa    INTEGER NOT NULL DEFAULT 0 CHECK (czy_startowa IN (0, 1)),
    rzadkosc_kod    TEXT NOT NULL DEFAULT 'pospolita' REFERENCES rzadkosci(kod),
    rekompensata    REAL NOT NULL DEFAULT 0 CHECK (rekompensata >= 0),
    ikona           TEXT    NOT NULL DEFAULT '',
    czy_aktywna     INTEGER NOT NULL DEFAULT 1 CHECK (czy_aktywna IN (0, 1)),
    kolejnosc       INTEGER NOT NULL DEFAULT 0
);

-- ----------------------------------------------------------------------------
-- 7. wedka_przedmioty — pula wędki: który przedmiot i z jaką wagą w_i.
--    waga NULL = użyj waga_domyslna z tabeli rzadkosci.
--    (Diagram nie rysuje tej asocjacji — to detal implementacji puli.)
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- 7b. skrzynki — klasa Skrzynka (nazwa, cena, ikona, aktywna).
--     Tryb «Otwarcie skrzynki» z diagramu use case (UC_CASE) i ze strony 2
--     diagramu PROJEKT (POST /api/open_case.php).
--
--     Kolumna nazywa się `aktywna` (nie `czy_aktywna` jak w wedki), bo tak
--     brzmi atrybut na diagramie klas: `- aktywna : bool`.
--     `cena` to koszt otwarcia — pobierany przez Portfel::pobierzOplate().
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS skrzynki (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    nazwa       TEXT    NOT NULL UNIQUE,
    opis        TEXT    NOT NULL DEFAULT '',
    cena        REAL    NOT NULL CHECK (cena >= 0),
    dzienna     INTEGER NOT NULL DEFAULT 0 CHECK (dzienna IN (0, 1)),
    ikona       TEXT    NOT NULL DEFAULT '',
    aktywna     INTEGER NOT NULL DEFAULT 1 CHECK (aktywna IN (0, 1)),
    kolejnosc   INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- ----------------------------------------------------------------------------
-- 7c. skrzynka_przedmioty — klasa SkrzynkaPrzedmiot (id, waga).
--     Nazwa tabeli wprost ze strony 2 diagramu PROJEKT: „Pobranie puli dropu
--     i wag z tabeli skrzynka_przedmioty".
--     waga NULL = dziedziczenie waga_domyslna z tabeli rzadkosci (jak w wędkach).
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- 8. ekwipunek — klasa Ekwipunek (data_nabycia, sprzedany).
--    Jeden wiersz = jedna złowiona sztuka (asocjacja Uzytkownik 1 — 1..* Ekwipunek).
--    `wartosc` zamraża wartość przedmiotu w chwili połowu, żeby późniejsza
--    zmiana balansu nie przeceniła tego, co gracz już ma.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ekwipunek (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL,
    przedmiot_id    INTEGER NOT NULL,
    wartosc         REAL    NOT NULL CHECK (wartosc >= 0),
    data_nabycia    TEXT    NOT NULL DEFAULT (datetime('now')),
    sprzedany       INTEGER NOT NULL DEFAULT 0 CHECK (sprzedany IN (0, 1)),
    sprzedano_kiedy TEXT,
    FOREIGN KEY (user_id)      REFERENCES uzytkownicy (id) ON DELETE CASCADE,
    FOREIGN KEY (przedmiot_id) REFERENCES przedmioty (id)  ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_ekwipunek_user ON ekwipunek (user_id, sprzedany);

-- ----------------------------------------------------------------------------
-- 9. historia_losowan — klasa Losowanie (czas, typ, wynik).
--    `typ` to TypLosowania z diagramu: cztery tryby gry ze strony 2 diagramu
--    PROJEKT (skrzynka, wedka, upgrader, trader). Wszystkie cztery są
--    zaimplementowane i zapisują tutaj swoje zdarzenia.
--    `wynik_id` to asocjacja Losowanie —wynik→ Przedmiot (*:1).
--    waga_wylosowana i suma_wag pozwalają odtworzyć P(i) dokładnie tak, jak
--    zostało wyliczone w momencie losowania (audyt + test rozkładu).
--
--    Kolumny poza diagramem (detal implementacji, jak wedka_id):
--      skrzynka_id — asocjacja Skrzynka — Losowanie (*:1) z diagramu klas,
--      udane       — 1 = wygrana, 0 = porażka. Potrzebne TYLKO upgraderowi,
--                    gdzie «Porażka: bezpowrotna utrata przedmiotu wkładu»
--                    jest pełnoprawnym wynikiem losowania (diagram PROJEKT).
--                    Przy porażce wynik_id wskazuje cel próby, a
--                    wygrana_wartosc = 0, więc RTP nadal liczy się poprawnie.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS historia_losowan (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id           INTEGER NOT NULL,
    typ               TEXT    NOT NULL CHECK (typ IN ('skrzynka', 'wedka', 'upgrader', 'trader')),
    wynik_id          INTEGER,
    wynik_wedka_id    INTEGER,
    duplikat          INTEGER NOT NULL DEFAULT 0 CHECK (duplikat IN (0, 1)),
    wedka_id          INTEGER,
    skrzynka_id       INTEGER,
    udane             INTEGER NOT NULL DEFAULT 1 CHECK (udane IN (0, 1)),
    koszt             REAL    NOT NULL,
    wygrana_wartosc   REAL    NOT NULL,
    waga_wylosowana   REAL    NOT NULL,
    suma_wag          REAL    NOT NULL,
    czas              TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id)  REFERENCES uzytkownicy (id) ON DELETE CASCADE,
    CHECK ((wynik_id IS NOT NULL) != (wynik_wedka_id IS NOT NULL)),
    CHECK (wynik_wedka_id IS NULL OR typ = 'skrzynka'),
    FOREIGN KEY (wynik_id) REFERENCES przedmioty (id) ON DELETE RESTRICT,
    FOREIGN KEY (wynik_wedka_id) REFERENCES wedki (id) ON DELETE RESTRICT,
    FOREIGN KEY (wedka_id) REFERENCES wedki (id)       ON DELETE CASCADE,
    FOREIGN KEY (skrzynka_id) REFERENCES skrzynki (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_historia_user ON historia_losowan (user_id, czas);
CREATE INDEX IF NOT EXISTS idx_historia_typ  ON historia_losowan (typ);

-- ----------------------------------------------------------------------------
-- 10. log_audytowy — klasa LogAudytowy (akcja, czas).
--     Zapisywany przez Administratora (asocjacja «zapisuje» z diagramu).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS log_audytowy (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER,
    akcja       TEXT    NOT NULL,
    szczegoly   TEXT    NOT NULL DEFAULT '',
    czas        TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES uzytkownicy (id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_log_audytowy_czas ON log_audytowy (czas);

-- ----------------------------------------------------------------------------
-- 11. ustawienia — parametry mechaniki czytane przez backend (zmiana bez redeployu)
-- ----------------------------------------------------------------------------
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
    ('token_waznosc_godz',   '72',     'Ważność tokena Bearer w godzinach'),
    -- Upgrader (UC_UPGRADE): szansa = (W_wklad / W_cel) * (1 - marza).
    ('upgrader_marza',       '0.05',   'Marża platformy w upgraderze (0.05 = 5%)'),
    -- Trader (UC_TRADE): kontrakt 3-10 przedmiotów o łącznej wartości >= progu.
    ('trader_min_przedmiotow', '3',    'Minimalna liczba przedmiotów w kontrakcie'),
    ('trader_max_przedmiotow', '10',   'Maksymalna liczba przedmiotów w kontrakcie'),
    ('trader_min_wartosc',   '10.0',   'Minimalna łączna wartość wkładu (próg kontraktu)'),
    -- Monitoring aktywności (UC_ADM3): alert «podejrzana seria wygranych».
    ('alert_seria_dlugosc',  '3',      'Długość serii trafień, która uruchamia alert'),
    ('alert_rzadkosc_kolejnosc', '4',  'Minimalna kolejność rzadkości liczonej do serii (4 = Mityczna)'),
    ('alert_rtp_prog',       '3.0',    'RTP gracza powyżej tego progu jest oznaczany jako anomalia'),
    ('alert_min_losowan',    '5',      'Minimalna liczba losowań gracza, żeby liczyć jego RTP');

-- Wędki są trwałymi odblokowaniami. Duplikat daje rekompensatę, nie drugi wpis.
CREATE TABLE IF NOT EXISTS uzytkownik_wedki (
    user_id INTEGER NOT NULL REFERENCES uzytkownicy(id) ON DELETE CASCADE,
    wedka_id INTEGER NOT NULL REFERENCES wedki(id) ON DELETE RESTRICT,
    data_nabycia TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (user_id, wedka_id)
);
CREATE TABLE IF NOT EXISTS skrzynka_wedki (
    skrzynka_id INTEGER NOT NULL REFERENCES skrzynki(id) ON DELETE CASCADE,
    wedka_id INTEGER NOT NULL REFERENCES wedki(id) ON DELETE RESTRICT,
    waga REAL NOT NULL CHECK (waga > 0),
    PRIMARY KEY (skrzynka_id, wedka_id)
);
-- Jeden wspólny zegar odbioru dziennego na konto, również przy wielu skrzynkach.
CREATE TABLE IF NOT EXISTS odbiory_dzienne (
    user_id INTEGER PRIMARY KEY REFERENCES uzytkownicy(id) ON DELETE CASCADE,
    odebrano INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS otwarcia_skrzynek (
    user_id INTEGER NOT NULL REFERENCES uzytkownicy(id) ON DELETE CASCADE,
    request_id TEXT NOT NULL,
    skrzynka_id INTEGER NOT NULL REFERENCES skrzynki(id) ON DELETE RESTRICT,
    odpowiedz TEXT NOT NULL,
    PRIMARY KEY (user_id, request_id)
);
