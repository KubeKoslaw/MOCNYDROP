# Instrukcja dla zespołu — MOCNY DROP (Backend & API)

> Właściciel: **@KubeKoslaw** (Core Backend, Tech lead)
> Zakres tego pliku: backend, baza danych i kontrakt API.
> Nie opisuje wyglądu strony ani zachowania animacji — to domeny @THOPA174 i @jgpostrach.

## Spis treści

1. [Co jest gotowe](#1-co-jest-gotowe)
2. [Jak uruchomić lokalnie](#2-jak-uruchomić-lokalnie)
3. [Jak działa łowienie ryb](#3-jak-działa-łowienie-ryb)
4. [Ściągawka z endpointów API](#4-ściągawka-z-endpointów-api)
5. [Zadania dla zespołu](#5-zadania-dla-zespołu)
6. [Format plików seed](#6-format-plików-seed)
7. [Animacja łowienia — komponent `<fishing-scene>`](#7-animacja-łowienia--komponent-fishing-scene)

---

## 1. Co jest gotowe

| Katalog / plik | Za co odpowiada | Właściciel |
|---|---|---|
| `database/schema.sql` | Struktura 11 tabel SQLite + słownik rzadkości i ustawień | @KubeKoslaw |
| `database/migrate.php` | Skrypt CLI: tworzy bazę i ładuje seed (`--fresh` = reset) | @KubeKoslaw |
| `database/topup.php` | Skrypt CLI: doładowanie salda przy testach (tylko CLI) | @KubeKoslaw |
| `dev/playground.html` | Tymczasowa konsola do klikalnego testowania API | @KubeKoslaw |
| `backend/bootstrap.php` | Autoloader `MocnyDrop\` (bez Composera) | @KubeKoslaw |
| `backend/WeightedRoller.php` | **Algorytm losowania** `P(i) = w_i / suma(w)` | @KubeKoslaw |
| `backend/Reel.php` | Pasek ruletki dla animacji łowienia | @KubeKoslaw |
| `backend/FishingService.php` | **Orkiestrator zarzutu**: opłata → losowanie → ekwipunek → historia (warstwa serwisowa, nie ma jej na diagramie klas) | @KubeKoslaw |
| `backend/Wedka.php` | Klasa z diagramu: wędki i ich pule przedmiotów z wagami (`zarzuc()`) | @KubeKoslaw |
| `backend/Ekwipunek.php` | Klasa z diagramu: `dodaj/usun/sprzedaj/wartoscRynkowa` | @KubeKoslaw |
| `backend/Uzytkownik.php` | Klasa z diagramu: `zarejestruj/zaloguj/czyZablokowany` | @KubeKoslaw |
| `backend/Administrator.php` | Klasa z diagramu (dziedziczy z `Uzytkownik`): `zbanuj/korygujSaldo/edytujSkrzynke/eksportujRaport` | @KubeKoslaw |
| `backend/Token.php` | Klasa z diagramu: tokeny Bearer (`weryfikuj/uniewaznij`) + warstwa HTTP logowania | @KubeKoslaw |
| `backend/Portfel.php` | Klasa z diagramu: `czyStac/pobierzOplate/dodajSrodki` (odporna na podwójne wydanie) | @KubeKoslaw |
| `backend/Przedmiot.php` | Klasa z diagramu: wygrana ryba (`obliczWartosc/opis`) | @KubeKoslaw |
| `backend/Rzadkosc.php` | Klasa z diagramu: słownik 6 poziomów rzadkości z README (`nazwa`, `mnoznik`) | @KubeKoslaw |
| `backend/Losowanie.php` | Klasa z diagramu: historia losowań (`typ`, `wynik`, `zapisz`, `wyliczRTP`) | @KubeKoslaw |
| `backend/LogAudytowy.php` | Klasa z diagramu: dziennik operacji administratora (`zapisz`) | @KubeKoslaw |
| `backend/ILosujacy.php` | Interfejs z diagramu: `losuj(): Przedmiot` (dla trybu skrzynek — jeszcze niezaimplementowany) | @KubeKoslaw |
| `backend/Rola.php`, `backend/TypLosowania.php` | Enumy z diagramu: rola konta i typ losowania | @KubeKoslaw |
| `backend/Settings.php` | Parametry mechaniki z tabeli `ustawienia` | @KubeKoslaw |
| `backend/SeedLoader.php` | Ładowanie `database/seed/*.csv` | @KubeKoslaw |
| `backend/Json.php` | Odpowiedzi JSON, CORS, walidacja pól | @KubeKoslaw |
| `backend/ApiException.php` | Błędy z kodem HTTP i kodem błędu dla frontendu | @KubeKoslaw |
| `api/*.php` | 10 endpointów REST | @KubeKoslaw |
| `tests/*.php` | Testy (`php tests/run.php`) | @KubeKoslaw |
| `public/assets/js/fishing/` | **Komponent animacji `<fishing-scene>`** — łowienie zamiast skrzynki ([sekcja 7](#7-animacja-łowienia--komponent-fishing-scene)) | @KubeKoslaw |
| `public/assets/css/fishing-scene.css` | Warstwa wizualna sceny łowienia (sekcja 7) | @THOPA174 |
| `dev/fishing-demo.html` | Harness animacji: mock połowu bez bazy i bez logowania | @KubeKoslaw |
| `dev/check-fishing.mjs` | Weryfikacja logiki i kontraktu animacji w Node | @KubeKoslaw |
| `database/seed/*.csv` | **Treść gry: przedmioty (ryby), wędki, wagi** (obecnie zestaw demo) | **@moxmar26** |
| `assets/icons/*.png` | Grafiki ryb | @moxmar26 |

---

## 2. Jak uruchomić lokalnie

### Krok 0: PHP 8.2+ z rozszerzeniem `pdo_sqlite`

| System | Instalacja |
|---|---|
| Windows | XAMPP (PHP i SQLite są w zestawie) |
| Ubuntu / Debian | `sudo apt install php-cli php-sqlite3` |
| Fedora | `sudo dnf install php-cli php-pdo` |
| **NixOS / nix** | `nix profile install nixpkgs#php` (albo jednorazowo `nix shell nixpkgs#php`) |
| macOS | `brew install php` |

Sprawdzenie: `php -v` oraz `php -m | grep pdo_sqlite`.

### Krok 1: baza i dane startowe

```bash
php database/migrate.php --fresh
```

Na końcu skrypt wypisze stan bazy. Poprawny wynik dla aktualnego seeda:

```
Seed: wedki.csv -> 6 wierszy
Seed: przedmioty.csv -> 54 wierszy
Seed: wedka_przedmioty.csv -> 86 wierszy

Stan bazy:
  wędki                      6
  przedmioty                54
  połączenia wędka-przedmiot 86
```

> [!NOTE]
> Ten krok nie jest obowiązkowy — baza SQLite tworzy się **automatycznie** przy
> pierwszym żądaniu do API. Plik `database/mocnydrop.sqlite` (oraz `-wal`/`-shm`)
> jest w `.gitignore`, więc każdy ma swoją lokalną kopię. Nic nie trzeba
> importować w phpMyAdminie.

### Krok 2: serwer

```bash
php -S localhost:8000
```

### Krok 3: gra

Konsola developerska: <http://localhost:8000/dev/playground.html>

Jest to **tymczasowa** strona do klikalnego testowania API (rejestracja, wędki,
zarzut z animacją łowienia, ekwipunek, sprzedaż, historia). Nie jest to
docelowy layout — ten powstanie w `public/` (@THOPA174, @jgpostrach). Warto ją
otworzyć, żeby zobaczyć wzorcową kolejność obsługi zarzutu opisaną w
[sekcji 3](#3-jak-działa-łowienie-ryb).

Sama animacja (bez logowania i bez bazy): <http://localhost:8000/dev/fishing-demo.html>
— harness komponentu `<fishing-scene>`, w którym wybierasz wędkę, przedmiot i
rzadkość, żeby obejrzeć każdą fazę. Szczegóły w
[sekcji 7](#7-animacja-łowienia--komponent-fishing-scene).

Pozostałe adresy:

- API (publiczne): <http://localhost:8000/api/wedki.php>
- Katalog ikon: <http://localhost:8000/assets/icons/>

> [!TIP]
> `php -S localhost:8000` wiąże serwer z adresem, który zwróci `getaddrinfo`
> dla `localhost` — na części maszyn jest to IPv6 (`::1`). Gdyby
> <http://127.0.0.1:8000> nie odpowiadało, użyj `localhost` albo odpal
> `php -S 0.0.0.0:8000`.

### Alternatywnie: XAMPP

1. Skopiuj folder projektu do `xampp/htdocs/MOCNYDROP`.
2. Włącz moduł **Apache**.
3. Wejdź na <http://localhost/MOCNYDROP/dev/playground.html>.

### Testy

```bash
php tests/run.php                 # wszystkie
php tests/run.php WeightedRoller  # tylko jedna klasa
```

Aktualny stan: **74 testy / 400 asercji, wszystkie zielone** (PHP 8.4).

### Doładowanie salda przy testach

W grze nie ma endpointu doładowania — monety zdobywa się sprzedażą ryb.
Żeby nie kasować bazy po wyczerpaniu salda startowego (50 monet):

```bash
php database/topup.php --list              # konta i salda
php database/topup.php wedkarz 500         # jedno konto
php database/topup.php --all 500           # wszystkie konta
```

Skrypt działa wyłącznie z CLI (jak `migrate.php`), więc nie da się go wywołać
przez HTTP i nie dziurawi ekonomii gry.

> [!IMPORTANT]
> **Gra nie zadziała bez danych.** Backend celowo nie hardkoduje żadnego przedmiotu.
> W `database/seed/` leży teraz **zestaw demo** (6 wędek, 54 przedmioty, 86 powiązań)
> dorzucony przez @KubeKoslaw wyłącznie po to, żeby dało się uruchomić i testować
> mechanikę. Docelową treść i balans wprowadza @moxmar26 — może te pliki
> nadpisać w całości (patrz [sekcja 6](#6-format-plików-seed)).
> Gdy wędka nie ma przedmiotów, `POST /api/fish.php` zwraca `rod_empty`.


---

## 3. Jak działa łowienie ryb

Mechanika łowienia działa **dokładnie tak samo jak otwieranie skrzynek** —
różni się tylko oprawą. Zamiast skrzynki jest **wędka** (klasa `Wedka` z diagramu), zamiast przedmiotu
jest **ryba** (klasa `Przedmiot`), a zamiast otwarcia jest **zarzut wędki**.

```
Gracz klika "Zarzuć wędkę"
        |
        v
POST /api/fish.php { "wedka_id": 1 }
        |
        v
[backend, jedna transakcja]
  1. sprawdź, czy wędka istnieje, jest aktywna i ma przedmioty (Wedka::zarzuc)
  2. pobierz opłatę z portfela    S_po = S_przed - c   (Portfel::pobierzOplate)
  3. ważne losowanie             P(i) = w_i / suma(w)   (WeightedRoller::roll)
  4. włóż przedmiot do ekwipunku                        (Ekwipunek::dodaj)
  5. zapisz zdarzenie w historii                        (Losowanie::zapisz, typ = wedka)
        |
        v
Odpowiedź JSON: wynik + pasek ruletki (reel)
        |
        v
[frontend] animacja przewija pasek i zatrzymuje się na wygranej
```

### Kluczowa zasada

**Wynik jest ustalany na serwerze PRZED animacją.** Pasek ruletki to tylko
materiał do przewinięcia. Gracz nie może wpłynąć na wynik zatrzymując animację
w innym miejscu — wygrana i tak już leży w jego ekwipunku.

Dlatego kolejność po stronie frontendu musi być taka:

1. wyślij żądanie i **poczekaj** na odpowiedź (nie startuj animacji wcześniej),
2. przewiń `reel` tak, żeby wskaźnik stanął na `reel_indeks_wygranej`,
3. **dopiero po zatrzymaniu** pokaż `przedmiot` i zaktualizuj saldo z `saldo_po`.

### Poziomy rzadkości

Sześć poziomów z `README.md`, zapisanych w tabeli `rzadkosci`:

| Kod | Nazwa | Waga | Szansa | Kolor |
|---|---|---|---|---|
| `pospolita` | Pospolita | 50 | ~50% | `#94a3b8` |
| `przecietna` | Przeciętna | 30 | ~30% | `#22c55e` |
| `niezla` | Niezła | 15 | ~15% | `#3b82f6` |
| `mityczna` | Mityczna | 4 | ~4% | `#a855f7` |
| `niesamowita` | Niesamowita | 1 | ~1% | `#eab308` |
| `ultra` | ULTRA MEGA NIESAMOWITA | 0.001 | ~0.001% | `#22d3ee` |

> [!WARNING]
> Kolory powyżej to **propozycja backendu**, żeby API mogło je zwracać w jednym
> miejscu. @THOPA174 może je zmienić — ale wtedy w `database/schema.sql`,
> a nie hardcoded w CSS. Frontend ma brać kolor z odpowiedzi API.

> [!CAUTION]
> Waga poziomu `ultra` to **0.001**, czyli liczba ułamkowa. Kolumna `waga` musi
> być typu `REAL`. Przy `INTEGER` zapisałaby się jako `0` i najrzadsza ryba
> stałaby się nieosiągalna. Test `RzadkoscTest::testWagaUltraRzadkiejNieJestZaokraglanaDoZera`
> pilnuje dokładnie tego przypadku.

---

## 4. Ściągawka z endpointów API

Wszystkie endpointy przyjmują i zwracają JSON (`Content-Type: application/json; charset=utf-8`).

Każda odpowiedź ma pole `success`. Błędy dodatkowo mają `error` (komunikat po
polsku, do pokazania graczowi) i `kod` (stabilny identyfikator do logiki w JS):

```json
{ "success": false, "error": "Za mało monet na zarzut.", "kod": "insufficient_funds" }
```

Chronione endpointy wymagają nagłówka `Authorization: Bearer <token>`.

| Endpoint | Metoda | Auth | Opis |
|---|---|---|---|
| `/api/register.php` | POST | nie | Rejestracja |
| `/api/login.php` | POST | nie | Logowanie, zwraca token |
| `/api/logout.php` | POST | tak | Wylogowanie (kasuje token) |
| `/api/me.php` | GET | tak | Dane gracza, saldo, podsumowanie ekwipunku |
| `/api/wedki.php` | GET | nie | Wędki z pulami przedmiotów i szansami |
| `/api/fish.php` | POST | tak | **Zarzut wędki** |
| `/api/inventory.php` | GET | tak | Ekwipunek |
| `/api/sell.php` | POST | tak | Sprzedaż ryby |
| `/api/history.php` | GET | tak | Historia połowów |

### A. Rejestracja — `POST /api/register.php`

```json
{ "login": "nowy_gracz", "email": "gracz@poczta.pl", "password": "bezpiecznehaslo123" }
```

Zasady: login 3–32 znaki (`A-Z a-z 0-9 . _ -`), hasło min. 8 znaków.

Odpowiedź **201**:

```json
{
  "success": true,
  "user": { "id": 3, "login": "nowy_gracz", "email": "gracz@poczta.pl", "rola": "user", "saldo": 50.0, "waluta": "monety" },
  "message": "Rejestracja udana! Możesz się teraz zalogować."
}
```

Kody błędów: `login_length`, `login_charset`, `email_invalid`, `password_too_short`,
`password_too_long`, `login_taken` (409), `email_taken` (409).

### B. Logowanie — `POST /api/login.php`

Pole `login` przyjmuje też adres e-mail.

```json
{ "login": "nowy_gracz", "password": "bezpiecznehaslo123" }
```

Odpowiedź **200**:

```json
{
  "success": true,
  "token": "a78f69c0d3bf...",
  "token_type": "Bearer",
  "expires_at": "2026-10-13 12:00:00",
  "user": { "id": 3, "login": "nowy_gracz", "email": "gracz@poczta.pl", "rola": "user", "saldo": 50.0, "waluta": "monety" }
}
```

> [!NOTE]
> Złe hasło i nieistniejące konto zwracają **identyczny** błąd `invalid_credentials`.
> To celowe — API nie zdradza, które konta istnieją.

### C. Dane gracza — `GET /api/me.php`

```json
{
  "success": true,
  "user": { "id": 3, "login": "nowy_gracz", "email": "gracz@poczta.pl", "rola": "user", "saldo": 40.0, "waluta": "monety" },
  "saldo": 40.0,
  "waluta": "monety",
  "ekwipunek": { "liczba_przedmiotow": 7, "laczna_wartosc": 183.5 }
}
```

To jest endpoint do odświeżania paska stanu konta po każdej akcji.

### D. Wędki — `GET /api/wedki.php`

Publiczny — zawartość pul i tak widać podczas animacji.

```json
{
  "success": true,
  "wedki": [
    {
      "id": 1,
      "nazwa": "Wędka Spławikowa",
      "opis": "Spokojna woda, dobre ryby dla początkujących.",
      "koszt_uzycia": 10.0,
      "ikona": "",
      "liczba_przedmiotow": 4,
      "przedmioty": [
        {
          "przedmiot_id": 1,
          "nazwa": "Anchois",
          "rzadkosc": "Pospolita",
          "rzadkosc_kod": "pospolita",
          "kolor": "#94a3b8",
          "wartosc": 2.0,
          "ikona": "assets/icons/anchovy.png",
          "waga": 50.0,
          "szansa_procent": 49.9995
        }
      ]
    }
  ],
  "rzadkosci": [
    { "kod": "pospolita", "nazwa": "Pospolita", "mnoznik": 1.0, "waga_domyslna": 50.0, "kolor": "#94a3b8", "kolejnosc": 1 }
  ],
  "reel": { "dlugosc": 60, "indeks_wygranej": 54 }
}
```

### E. Zarzut wędki — `POST /api/fish.php`

```json
{ "wedka_id": 1 }
```

Odpowiedź **200**:

```json
{
  "success": true,
  "rzut": { "id": 123, "koszt": 10.0 },
  "wedka": { "id": 1, "nazwa": "Wędka Spławikowa", "koszt_uzycia": 10.0 },
  "przedmiot": {
    "ekwipunek_id": 456,
    "przedmiot_id": 3,
    "nazwa": "Bass Małogębowy",
    "rzadkosc": "Niezła",
    "rzadkosc_kod": "niezla",
    "kolor": "#3b82f6",
    "wartosc": 25.0,
    "ikona": "assets/icons/smallmouth_bass.png",
    "szansa_procent": 15.0
  },
  "saldo_przed": 100.0,
  "saldo_po": 90.0,
  "reel": [
    { "przedmiot_id": 1, "nazwa": "Anchois", "ikona": "assets/icons/anchovy.png", "rzadkosc": "Pospolita", "rzadkosc_kod": "pospolita", "kolor": "#94a3b8", "wartosc": 2.0 }
  ],
  "reel_dlugosc": 60,
  "reel_indeks_wygranej": 54,
  "message": "Złowiono: Bass Małogębowy (Niezła)!"
}
```

Kody błędów: `insufficient_funds`, `rod_not_found` (404), `rod_inactive`, `rod_empty`.

> [!IMPORTANT]
> `reel` ma dokładnie `reel_dlugosc` pozycji, a wygrana ryba leży pod
> `reel_indeks_wygranej`. Pozostałe pozycje to losowe ryby z tej samej puli —
> wyłącznie szum wizualny, nie mają znaczenia dla wyniku.
> Elementy `reel` celowo **nie zawierają** wag ani szans (te są tylko w
> `przedmiot` i w `wedki.php`).

### F. Ekwipunek — `GET /api/inventory.php`

Query opcjonalne: `?z_sprzedanymi=1` pokaże też ryby już sprzedane.

```json
{
  "success": true,
  "ekwipunek": [
    {
      "ekwipunek_id": 456,
      "przedmiot_id": 3,
      "nazwa": "Bass Małogębowy",
      "rzadkosc": "Niezła",
      "rzadkosc_kod": "niezla",
      "kolor": "#3b82f6",
      "wartosc": 25.0,
      "ikona": "assets/icons/smallmouth_bass.png",
      "sprzedany": false,
      "data_nabycia": "2026-10-10 14:32:11",
      "sprzedano_kiedy": null
    }
  ],
  "podsumowanie": { "liczba_przedmiotow": 7, "laczna_wartosc": 183.5 }
}
```

Lista jest sortowana od najrzadszych przedmiotów — pasuje do siatki ekwipunku.

> [!NOTE]
> `ekwipunek_id` to id **wpisu** w ekwipunku, nie id ryby z katalogu.
> Gracz może mieć trzy takie same ryby i każda to osobny wpis. Do sprzedaży
> używamy `ekwipunek_id`.

### G. Sprzedaż — `POST /api/sell.php`

```json
{ "ekwipunek_id": 456 }
```

Odpowiedź **200**:

```json
{
  "success": true,
  "sprzedany_przedmiot": { "ekwipunek_id": 456, "przedmiot_id": 3, "nazwa": "Bass Małogębowy" },
  "otrzymane_monety": 25.0,
  "nowe_saldo": 115.0,
  "message": "Sprzedano \"Bass Małogębowy\" za 25,00 monet."
}
```

Kody błędów: `item_not_found` (404), `already_sold` (409).

> [!NOTE]
> `wartosc` w ekwipunku to cena **zamrożona w chwili połowu**. Zmiana balansu
> w seedzie nie przeceni przedmiotów, które gracze już złowili.

### H. Historia — `GET /api/history.php`

Query opcjonalne: `?limit=20` (1–100, domyślnie 20).

```json
{
  "success": true,
  "historia": [
    {
      "id": 123,
      "czas": "2026-10-10 14:32:11",
      "typ": "wedka",
      "typ_nazwa": "Rzut wędką",
      "wedka": "Wędka Spławikowa",
      "przedmiot_id": 3,
      "nazwa": "Bass Małogębowy",
      "rzadkosc": "Niezła",
      "kolor": "#3b82f6",
      "ikona": "assets/icons/smallmouth_bass.png",
      "koszt": 10.0,
      "wygrana_wartosc": 25.0,
      "bilans": 15.0,
      "szansa_procent": 15.0
    }
  ],
  "rozbicie_rzadkosci": { "pospolita": 41, "przecietna": 22, "niezla": 9, "mityczna": 2 },
  "rtp": 1.83
}
```

### Gotowy przykład w JavaScript

```javascript
const TOKEN_KEY = 'mocnydrop_token';

async function api(sciezka, { metoda = 'GET', body } = {}) {
  const naglowki = { 'Content-Type': 'application/json' };
  const token = localStorage.getItem(TOKEN_KEY);

  if (token) {
    naglowki['Authorization'] = 'Bearer ' + token;
  }

  const odpowiedz = await fetch('api/' + sciezka, {
    method: metoda,
    headers: naglowki,
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  const dane = await odpowiedz.json();

  if (!odpowiedz.ok || dane.success === false) {
    // `kod` jest stabilny — można na nim budować logikę, `error` to tekst dla gracza.
    const blad = new Error(dane.error || 'Nieznany błąd');
    blad.kod = dane.kod || 'error';
    blad.status = odpowiedz.status;
    throw blad;
  }

  return dane;
}

// Logowanie
const sesja = await api('login.php', {
  metoda: 'POST',
  body: { login: 'nowy_gracz', password: 'bezpiecznehaslo123' },
});
localStorage.setItem(TOKEN_KEY, sesja.token);

// Zarzut wędki
try {
  const rzut = await api('fish.php', { metoda: 'POST', body: { wedka_id: 1 } });

  // KOLEJNOŚĆ MA ZNACZENIE: najpierw animacja, potem wynik.
  await animujRuletke(rzut.reel, rzut.reel_indeks_wygranej);
  pokazWygrana(rzut.przedmiot);
  aktualizujSaldo(rzut.saldo_po);
} catch (blad) {
  if (blad.kod === 'insufficient_funds') {
    pokazBrakSrodkow();
  } else {
    pokazBlad(blad.message);
  }
}
```

---

## 5. Zadania dla zespołu

### @moxmar26 — treść i dane testowe

Najważniejsze: **bez Twoich danych gra nie ruszy.** Backend celowo nie zawiera
ani jednej ryby — w `database/seed/` leży na razie tylko zestaw demo dorzucony
przez @KubeKoslaw, żeby dało się odpalić mechanikę. Możesz go nadpisać w całości.
Do zrobienia:

1. Utwórz (albo podmień) `database/seed/wedki.csv`, `database/seed/przedmioty.csv`,
   `database/seed/wedka_przedmioty.csv` (format w [sekcji 6](#6-format-plików-seed)).
2. Nazwy plików ikon wpisuj **bez ścieżki**, np. `blue_marlin.png`. Backend sam
   dołoży `assets/icons/` i sprawdzi, czy plik istnieje — literówka pojawi się
   jako ostrzeżenie przy `php database/migrate.php`.
3. W `przedmioty.csv` używaj wyłącznie kodów: `pospolita`, `przecietna`, `niezla`,
   `mityczna`, `niesamowita`, `ultra`. Inny kod = wiersz odrzucony z ostrzeżeniem.
4. Kolumnę `waga` w `wedka_przedmioty.csv` zostaw **pustą**, jeśli ryba ma mieć
   wagę domyślną z README. Wypełnij ją tylko wtedy, gdy na danej wędce przedmiot
   ma być częstsza lub rzadsza niż domyślnie.
5. Dokumentacja w `docs/` jest Twoja (CODEOWNERS).

> [!TIP]
> Po każdej zmianie CSV odpal `php database/migrate.php --fresh` i przeczytaj
> ostrzeżenia. Każde mówi wprost, który wiersz i dlaczego został pominięty.

### @jgpostrach — JavaScript

1. **Zapisywanie tokena** po zalogowaniu w `localStorage` i wysyłanie go w
   nagłówku `Authorization: Bearer <token>` — gotowy helper jest w sekcji 4.
2. **Animacja łowienia** — jest gotowa jako komponent `<fishing-scene>`
   (`public/assets/js/fishing/`, [sekcja 7](#7-animacja-łowienia--komponent-fishing-scene)).
   Pasek `reel` przewija się pod wodą i zatrzymuje na `reel_indeks_wygranej`,
   kolory i ikony bierze z API. Do zrobienia zostaje **integracja z docelowym
   layoutem**: wstawić element, wywołać `scene.select()` + `scene.cast()`
   i obsłużyć zdarzenia `catch` / `error` / `castrequest`.
3. **Kolejność**: pilnuje jej komponent (najpierw odpowiedź z API, potem
   animacja, potem karta i saldo z `saldo_po`). Po swojej stronie musisz tylko
   zaktualizować portfel i listy w obsłudze zdarzenia `catch`.
4. **Obsługa `kod` błędu**, nie tekstu: `insufficient_funds` → zablokuj przycisk
   i pokaż brak monet; `rod_empty` → wędkę bez przedmiotów; `already_sold` → odśwież
   ekwipunek.
5. Plik `public/assets/js/` jest Twój (CODEOWNERS).

> [!WARNING]
> Nie losuj wyniku w przeglądarce. `reel` to tylko materiał do animacji —
> wygrana jest już w `przedmiot` i leży w bazie.

### @THOPA174 — HTML/CSS

1. **Nawigacja**: logo, stan portfela, przyciski logowania albo profil + wyloguj.
2. **Strona łowienia**: kafle wędek z `GET /api/wedki.php` — nazwa, opis,
   `koszt_uzycia`, przycisk "Zarzuć wędkę".
3. **Podgląd puli**: lista przedmiotów w puli wędki z `szansa_procent` i kolorem rzadkości.
4. **Kolory rzadkości** bierz z odpowiedzi API (pole `kolor`), nie hardcoded
   w CSS — wtedy zmiana w bazie automatycznie trafi na stronę.
5. **Miejsce na scenę łowienia**: wstaw `<fishing-scene>` (z
   `public/assets/js/fishing/`, patrz [sekcja 7](#7-animacja-łowienia--komponent-fishing-scene)).
   Komponent ma własny kadr (16:9, na mobile 4:5) i skaluje się sam — potrzebuje
   tylko kontenera o sensownej szerokości. Wygląd stroisz zmiennymi CSS
   (`--fs-water-y`, `--fs-cast-x`, `--fs-pivot-*`, paleta) bez dotykania JS.
6. **Ekwipunek**: siatka ryb z przyciskiem "Sprzedaj za X monet"
   (`GET /api/inventory.php`, `POST /api/sell.php`).
7. Pliki `public/*.php`, `public/partials/`, `public/assets/css/` są Twoje.

### @KubeKoslaw — backend (zrobione, do utrzymania)

- Przegląd PR-ów (CODEOWNERS wymusza review na wszystkim).
- Zmiany w `database/schema.sql` wymagają odpalenia `php tests/run.php` i
  `php database/migrate.php --fresh`.
- Tryb skrzynek i upgrader: `WeightedRoller` jest gotowy do ponownego użycia —
  potrzeba tylko osobnej tabeli łączącej i serwisu na wzór `FishingService`.

---

## 6. Format plików seed

Katalog `database/seed/` należy do **@moxmar26**. Backend czyta trzy pliki CSV
z nagłówkami w pierwszym wierszu. Separator: przecinek, kodowanie: UTF-8.

Kolejność ładowania: `wedki.csv` → `przedmioty.csv` → `wedka_przedmioty.csv`.

### `wedki.csv`

```
nazwa,opis,koszt_uzycia,ikona,kolejnosc
Wędka Spławikowa,Spokojna woda dla początkujących,10,,1
Rzeka Bystra,Szybki nurt i lepsze ryby,25,,2
Głębia,Miejsce dla odważnych,100,,3
```

### `przedmioty.csv`

```
nazwa,rzadkosc_kod,wartosc,ikona,opis
Anchois,pospolita,2,anchovy.png,Mała srebrzysta rybka
Jesiotr,niesamowita,400,sturgeon.png,Król głębin
Ryba Otchłani,ultra,25000,void_fish.png,Nikt nie wie skąd się wzięła
```

### `wedka_przedmioty.csv`

```
wedka_nazwa,przedmiot_nazwa,waga
Wędka Spławikowa,Anchois,
Wędka Spławikowa,Jesiotr,
Rzeka Bystra,Anchois,5
Rzeka Bystra,Jesiotr,
```

Pusta `waga` = ryba dziedziczy wagę domyślną swojego poziomu rzadkości.
Wpisana liczba = nadpisanie wagi **tylko na tej wędce**.

### Co robi backend z błędnymi danymi

Nic nie przerywa ładowania — każdy problem kończy się ostrzeżeniem:

| Sytuacja | Skutek |
|---|---|
| Wiersz z inną liczbą kolumn | pominięty + ostrzeżenie |
| Nieznany `rzadkosc_kod` | ryba pominięta + lista dozwolonych kodów |
| Ujemna wartość lub koszt | wiersz pominięty + ostrzeżenie |
| Ikona wskazująca nieistniejący plik | ryba wczytana + ostrzeżenie |
| Nieznana nazwa wędki lub przedmiotu w `wedka_przedmioty.csv` | połączenie pominięte |
| Brak całego katalogu `database/seed/` | ostrzeżenie, gra działa |

Ładowanie jest idempotentne (`INSERT OR IGNORE`) — można je odpalać wielokrotnie.

---

## 7. Animacja łowienia — komponent `<fishing-scene>`

> Właściciel logiki i kontraktu: **@KubeKoslaw** · CSS: **@THOPA174** · integracja
> w docelowym layoucie: **@jgpostrach**
>
> Zakres: **wyłącznie warstwa UI/UX zarzutu**. Logika losowania, endpointy API,
> schemat bazy i testy backendu są **bez zmian**.

Mechanika "otwierania skrzynki" (poziomy pasek ruletki przesuwający się pod
wskaźnikiem) została zastąpiona **łowieniem wędką**: tafla wody ze spławikiem,
zarzut, oczekiwanie na branie, zacięcie, wyciąganie zdobyczy z wody i karta
z wylosowanym dropem.

### 7.1 Co gdzie leży

| Plik | Rola | Właściciel |
|---|---|---|
| `public/assets/css/fishing-scene.css` | cała warstwa wizualna sceny (zmienne, keyframes, responsywność, reduced-motion) | @THOPA174 |
| `public/assets/js/fishing/fishing-scene.js` | komponent `<fishing-scene>`: szablon DOM, maszyna stanów, geometria wędziska i żyłki | @KubeKoslaw |
| `public/assets/js/fishing/reel-lane.js` | podwodny pasek `reel` — przewinięcie i zatrzymanie na `reel_indeks_wygranej` | @KubeKoslaw |
| `public/assets/js/fishing/contract.js` | walidacja odpowiedzi `fish.php` (czysta funkcja, testowalna w Node) | @KubeKoslaw |
| `public/assets/js/fishing/config.js` | fazy, czasy, kąty wędziska, słownik rzadkości | @KubeKoslaw |
| `public/assets/js/fishing/timeline.js` | `wait`, `tween` (rAF + watchdog), easing, token anulowania | @KubeKoslaw |
| `public/assets/js/fishing/api.js` | klient `fish.php` / `wedki.php` (Bearer, błędy po `kod`) | @jgpostrach |
| `public/assets/js/fishing/index.js` | punkt wejścia (rejestruje element, eksportuje resztę) | @jgpostrach |
| `public/assets/js/package.json` | `"type": "module"` — patrz uwaga niżej | @KubeKoslaw |
| `dev/fishing-demo.html` | harness animacji: mock połowu bez logowania i bez bazy | @KubeKoslaw |
| `dev/check-fishing.mjs` | weryfikacja logiki i kontraktu w Node (`node dev/check-fishing.mjs`) | @KubeKoslaw |

> [!NOTE]
> `public/assets/js/package.json` zawiera tylko `{"type": "module"}`. Projekt nie
> używa npm — plik mówi Node, że `*.js` w tym katalogu to moduły ES, dzięki czemu
> krok CI `Skladnia JS (node --check)` przechodzi na każdej wersji Node.
> Przeglądarki nigdy go nie pobierają.

### 7.2 Stany animacji

Kolejność jest stała, a bieżąca faza jest w atrybucie `data-phase` na `.fs-stage`
(na tym selectorze opiera się CSS):

| Faza | `data-phase` | Co widzi gracz |
|---|---|---|
| bezczynność | `idle` | tafla wody, fale, spławik wisi pod wędziskiem, przycisk **"Zarzuć wędkę"** |
| żądanie | `requesting` | klik → wędzisko unosi się, przycisk pulsuje ("Zarzucam…"). **Trwa do odpowiedzi API** |
| zarzut | `casting` | zamach, lot spławika po łuku, plusk i kręgi na wodzie |
| oczekiwanie | `waiting` | spławik kołysze się na falach, pod wodą płynie parada ryb (pasek `reel`) |
| branie | `bite` | gwałtowne zanurzenie spławika, plusk, szarpnięcie wędziska, wstrząs sceny |
| wyciąganie | `reeling` | żyłka się napina i skraca, z wody wyłania się **wylosowana nagroda** i wędruje w górę |
| odkrycie | `reveal` | karta z dropem: nazwa, rzadkość, wartość, szansa, koszt, saldo przed → po |
| błąd | `error` | komunikat z `error` + `kod` z API (np. brak monet) |

> [!IMPORTANT]
> Faza `requesting` istnieje po to, żeby **nie startować animacji przed odpowiedzią
> serwera** (punkt 1 kontraktu z `api/fish.php`). Uniesienie wędziska to tylko
> informacja zwrotna po kliknięciu — nie zdradza i nie zmienia wyniku.

### 7.3 Co się stało z paskiem `reel` (kontrakt bez zmian)

Pasek ruletki **nie zniknął** — zszedł pod wodę. Ryby z `reel` płyną w stronę
haczyka (który stoi dokładnie pod spławikiem), a pasek zatrzymuje się tak, że
`reel[reel_indeks_wygranej]` jest na haczyku. **Moment zatrzymania = branie.**

Dzięki temu umowa z backendu jest spełniona co do piksela, a prezentacja pasuje
do mechaniki łowienia:

```
reel (60 pozycji z API)  ->  parada ryb pod wodą
reel_indeks_wygranej     ->  pozycja, która zatrzymuje się na haczyku
zatrzymanie paska        ->  faza `bite` (spławik nurkuje)
przedmiot                ->  ryba wyciągana z wody + karta wygranej
saldo_po                 ->  saldo w karcie i w portfelu (po zamknięciu karty)
```

Komponent dodatkowo **pilnuje kontraktu** (`contract.js`): jeśli odpowiedź nie ma
`przedmiot`, `reel` albo indeks wygranej jest poza zakresem — animacja nie
zgaduje, tylko pokazuje błąd `bad_response`. Rozjazd
`reel[indeks].przedmiot_id != przedmiot.przedmiot_id` jest logowany do konsoli.

> [!WARNING]
> W przeglądarce **nie ma ani jednego losowania wyniku**. `Math.random()` pojawia
> się tylko w dekoracjach (kąty kropel plusku, pozycje iskier, bąbelki). Wynik
> pochodzi z `przedmiot`, a ryba leży już w ekwipunku i w historii połowów.

### 7.4 Jak wpiąć komponent na stronie

Wystarczą dwie linijki — moduł rejestruje element i sam podpina swój CSS:

```html
<script type="module" src="public/assets/js/fishing/index.js"></script>
<fishing-scene api-base="api/" show-lane></fishing-scene>
```

```js
const scene = document.querySelector('fishing-scene');

// Jeśli strona sama wyświetla listę wędek, przekaż wybrane do sceny:
scene.select(rod.id, { nazwa: rod.nazwa, koszt: rod.koszt_uzycia });

// Pełny obieg: POST fish.php -> animacja -> karta. Promise rozwiązuje się
// po zamknięciu karty (albo zwraca null, gdy coś poszło nie tak).
await scene.cast(rod.id);

// Albo bez API (mock, testy, demo):
await scene.play(payloadZCastPhp);
```

**Atrybuty**

| Atrybut | Domyślnie | Znaczenie |
|---|---|---|
| `api-base` | `api/` | prefiks endpointów (przy docroocie w `public/` ustaw np. `../api/`) |
| `token-key` | `mocnydrop_token` | klucz tokena w `localStorage` |
| `rod-id` / `rod-name` / `cost` | — | wybrana wędka i jej koszt (podpis pod przyciskiem) |
| `label` | `Zarzuć wędkę` | własna etykieta przycisku w fazie `idle` |
| `show-lane` | brak | włącza podwodną paradę ryb (pasek `reel`); bez niego zostaje sama tafla |
| `reduced` | brak | wymusza tryb ograniczonego ruchu (niezależnie od ustawień systemu) |

**Metody i właściwości**

| API | Opis |
|---|---|
| `cast(rodId?)` | żądanie + pełna animacja; zwraca payload albo `null` |
| `play(payload)` | animacja z gotowym payloadem (bez żądania) |
| `select(rodId, {nazwa, koszt})` | ustawia wędkę bez zarzutu |
| `reset()` | przerywa animację i wraca do `idle` (wyniku w bazie NIE cofa) |
| `phase` / `busy` / `rodId` / `reduced` | tylko do odczytu |

**Zdarzenia**

| Zdarzenie | `detail` | Kiedy |
|---|---|---|
| `catch` | `{ payload, fish }` | karta wygranej się otwiera — tu aktualizuj saldo (`payload.saldo_po`), ekwipunek i historię |
| `error` | `{ kod, message, status }` | błąd API lub walidacji — reaguj na `kod`, nie na tekst |
| `phasechange` | `{ phase }` | każda zmiana fazy (HUD, debugowanie, analityka) |
| `castrequest` | `{ rodId: null }` | klik w przycisk, gdy nie wybrano wędki — strona powinna ją wybrać |

Gotowy przykład podpięcia jest w `dev/playground.html` (funkcje `bindScene()`,
`cast()` i `reportError()`).

### 7.5 Co można zmieniać w CSS (bez dotykania JavaScriptu)

Scena **mierzy** geometrię z layoutu (`offsetLeft`/`offsetWidth`), a nie trzyma jej
na sztywno w JS. Dlatego przestawienie poniższych zmiennych automatycznie
przeliczy tor lotu spławika i przebieg żyłki:

| Zmienna | Domyślnie | Za co odpowiada |
|---|---|---|
| `--fs-water-y` | `62%` | wysokość linii wody |
| `--fs-cast-x` | `37%` | gdzie ląduje spławik **i gdzie stoi haczyk** (oś paska `reel`) |
| `--fs-pivot-x` / `--fs-pivot-y` | `74%` / `57%` | dłonie wędkarza = koniec wędziska (żyłka wychodzi z tego punktu) |
| `--fs-rod-len` | `30cqw` | długość wędziska |
| `--fs-aspect` | `16 / 9` | proporcje sceny (mobile: `4 / 5`) |
| `--fs-sky-*`, `--fs-water-*`, `--fs-foam`, `--fs-accent` | — | paleta |
| `--fs-wave-a` / `--fs-wave-b` / `--fs-bob` | `14s` / `21s` / `2.8s` | tempo fal i kołysania spławika |

Kolory rzadkości **nie są** w CSS — pochodzą z pola `kolor` odpowiedzi API i są
wstawiane jako `--fs-rarity` na karcie oraz na rybie (patrz sekcja 3).

### 7.6 Strojenie czasów i kątów

Wszystko w `public/assets/js/fishing/config.js`:

| Stała | Wartość | Efekt |
|---|---|---|
| `TIMINGS.rodLift` | 380 ms | uniesienie wędziska w `requesting` |
| `TIMINGS.castArc` | 950 ms | lot spławika |
| `TIMINGS.splashSettle` | 360 ms | wybrzmienie plusku |
| `TIMINGS.lanePerItem` | 95 ms | czas parady na jedną pozycję paska |
| `TIMINGS.laneMin` / `laneMax` | 3600 / 7200 ms | widełki oczekiwania na branie |
| `TIMINGS.laneSettle` | 620 ms | pauza z wygraną rybą na haczyku |
| `TIMINGS.bite` | 900 ms | zanurzenie spławika i zacięcie |
| `TIMINGS.reelIn` | 1500 ms | wyciąganie ryby z wody |
| `TIMINGS.reducedScale` | 0.12 | mnożnik czasów przy ograniczonym ruchu |
| `ROD_ANGLES.*` | stopnie | pozy wędziska (`idle`, `lift`, `cast`, `waiting`, `bite`, `reel`, `reveal`) |
| `SCENE.heroLift` | 0.62 | jak wysoko nad wodą zatrzymuje się ryba (0 = woda, 1 = przelotka) |
| `SCENE.bitePlunge` | 0.055 | głębokość zanurzenia spławika przy braniu |

Czas oczekiwania skaluje się z `reel_dlugosc` z ustawień bazy (60 pozycji ≈ 5,7 s),
więc zmiana `reel_dlugosc` w tabeli `ustawienia` automatycznie wydłuża/skraca
animację — bez poprawek w kodzie.

### 7.7 Responsywność i dostępność

- **Rozmiary** są w `cqw` + `clamp()` (host ma `container-type: inline-size`), więc
  scena skaluje się płynnie od telefonu do szerokiego layoutu.
- **Mobile**: `@media (max-width: 640px)` przełącza kadr na `4 / 5` i przestawia
  geometrię (`--fs-pivot-*`, `--fs-rod-len`), żeby wędkarz i spławik zostali czytelni.
  To media query na **szerokość okna** — testuj w DevTools device mode, nie tylko
  zwężając kontener.
- **Karta wygranej** jest overlayem wewnątrz komponentu (`position: absolute`, nie
  `fixed`), więc nigdy nie rozjeżdża layoutu strony i działa w wąskiej kolumnie.
- **Klawiatura/a11y**: przycisk to prawdziwy `<button>` (min. 44 px celu dotykowego),
  karta ma `role="dialog"` i zamyka się klawiszem `Esc`, a postęp jest ogłoszony
  przez `role="status"` + `aria-live="polite"`.
- **`prefers-reduced-motion`**: fale, bąbelki, iskry i wstrząsy są wyłączane, czasy
  faz skracane 8× (`TIMINGS.reducedScale`), ale **przebieg faz zostaje ten sam** —
  gracz nadal widzi zarzut, branie i kartę. Można też wymusić atrybutem `reduced`.
- **Throttling karty w tle**: każdy tween ma watchdog (`timeline.js`), więc animacja
  domknie się nawet wtedy, gdy przeglądarka wstrzyma `requestAnimationFrame`.
  Bez tego gracz zostałby z zablokowanym przyciskiem i już pobraną opłatą.

### 7.8 Jak to przetestować

| Co | Jak |
|---|---|
| Animacja bez bazy i logowania | <http://localhost:8000/dev/fishing-demo.html> — mock z prawdziwych przedmiotów z `api/wedki.php`, ręczny wybór zwycięzcy i rzadkości |
| Animacja bez klikania | `dev/fishing-demo.html?auto=1&rarity=ultra` (dodaj `&close=1`, żeby domknąć kartę) |
| Pełny obieg z bazą | <http://localhost:8000/dev/playground.html> — konto demo + prawdziwy zarzut |
| Pełny obieg bez klikania | `dev/playground.html?auto=1&spot=1` (zakłada konto demo i **realnie** wydaje monety) |
| Logika i kontrakt w Node | `node dev/check-fishing.mjs` |
| Kontrakt na żywej odpowiedzi | `curl ... api/fish.php > /tmp/fish.json && node dev/check-fishing.mjs /tmp/fish.json` |
| Backend bez zmian | `php tests/run.php` (74 testy / 400 asercji) |

Przykład sprawdzenia sceny w headless Chromium (bez klikania, zrzut DOM po cyklu):

```bash
chromium --headless=new --no-sandbox --disable-gpu --virtual-time-budget=40000 \
  --dump-dom 'http://localhost:8000/dev/fishing-demo.html?auto=1&rarity=ultra&close=1' \
  | grep -o 'data-phase="[a-z]*"'
```

### 7.9 Czego NIE robić

1. **Nie losuj wyniku w przeglądarce** — ani "na wszelki wypadek", ani do
   przyspieszenia animacji. Wygrana jest w `przedmiot` i już leży w bazie.
2. **Nie startuj animacji przed odpowiedzią API** — faza `requesting` jest od tego.
3. **Nie pomijaj `reel_indeks_wygranej`** — pasek ma się zatrzymać dokładnie tam.
   Przerwanie animacji w połowie nie zmienia wygranej, ale psuje spójność prezentacji.
4. **Nie hardkoduj kolorów rzadkości w CSS** — bierz `kolor` z odpowiedzi API.
5. **Nie kopiuj komponentu do `public/*.php`** — importuj go z
   `public/assets/js/fishing/index.js`, żeby była jedna wersja do utrzymania.

---

*Pytać @KubeKoslaw*
