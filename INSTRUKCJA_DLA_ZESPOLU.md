# 📘 Instrukcja dla Zespołu — MOCNY DROP (Backend & API)

1. **Co już jest gotowe w kodzie**
2. **Jak odpalić projekt lokalnie**
3. **Co każda osoba ma dalej robić (podział zadań)**
4. **Ściągawka z endpointów API dla JavaScriptu**

---

## 1. Jak uruchomić projekt na swoim komputerze?

### Sposób 1: Wbudowany serwer PHP (najszybszy — bez instalacji XAMPP)
Otwórz terminal w folderze projektu `MOCNYDROP` i wpisz:
```bash
php -S localhost:8000
```
Wejdź w przeglądarce pod adres: **`http://localhost:8000`**

### Sposób 2: XAMPP
1. Skopiuj folder projektu do katalogu `xampp/htdocs/MOCNYDROP`.
2. W panelu XAMPP włącz moduł **Apache**.
3. Wejdź w przeglądarce na: **`http://localhost/MOCNYDROP`**.

> [!NOTE]
> Baza danych SQLite utworzy się **automatycznie** przy pierwszym wejściu na stronę. Nie musisz niczego ręcznie importować w phpMyAdminie

---

## 2. Co jest w tym branchu?

| Katalog / Plik | Za co odpowiada? |
|---|---|
| `database/schema.sql` | Prosta struktura 7 tabel w SQLite (użytkownicy, skrzynki, przedmioty, wagi, ekwipunek, historia). |
| `database/seed.sql` | Przykładowe dane startowe (konta testowe, skrzynki i dropy z rybami z gifa). |
| `database/init.php` | Skrypt resetujący bazę danych (`php database/init.php`), gdybyście chcieli zacząć od zera. |
| `includes/header.php`, `footer.php` | Wspólny nagłówek z nawigacją, paskiem stanu konta i stopka. |
| `index.php` | Strona główna (Hub) — powitanie i kafle wyboru trybów gry. |
| `login.php` | Osobna podstrona logowania i rejestracji gracza. |
| `cases.php` | Osobna podstrona trybu skrzynek (otwieranie dropu). |
| `fish.php` | Osobna podstrona trybu łowiska (rzut wędką i połów). |
| `upgrade.php` | Osobna podstrona trybu Upgradera (ulepszanie ryb). |
| `inventory.php` | Osobna podstrona ekwipunku (przegląd i sprzedaż ryb). |
| `src/*.php` | Klasy logiki biznesowej (baza, autoryzacja, losowanie wagowe, skrzynki, ekwipunek). |
| `api/*.php` | Zestaw endpointów REST zwracających dane w formacie JSON. |
| `tests/test_backend.php` | Skrypt testowy sprawdzający poprawność bazy oraz symulujący 10 000 losowań. |

---

## 3. Co dalej robić? (Zadania dla zespołu)

###  @THOPA174 — Layout w HTML/CSS


**Co przygotować w HTML/CSS:**
1. **Nawigacja (Header):**
   - Logo MOCNY DROP
   - Stan portfela gracza (np. złoty badge z liczbą monet)
   - Przyciski "Zaloguj się", "Zarejestruj się" lub profil użytkownika z przyciskiem "Wyloguj".
2. **Karty skrzynek w sklepie:**
   - Karta skrzynki: nazwa, ikona/grafika, cena otwarcia, przycisk "Otwórz".
   - Podgląd dropu: mała lista lub kafelki z przedmiotami możliwymi do trafienia i ich rzadkościami.
3. **Kolorystyka rzadkości przedmiotów:**
   Warto użyć kolorów pasujących do rzadkości (zgodnie z `README.md`):
   - **Zwykła (50%):** szary / srebrny (`#94a3b8`)
   - **Rzadka (30%):** niebieski (`#3b82f6`)
   - **Epicka (15%):** fioletowy (`#a855f7`)
   - **Legendarna (5%):** złoty / żółty z poświatą (`#eab308`)
4. **Sekcja ekwipunku (Inwentarz):**
   - Siatka przedmiotów zdobytych przez gracza z przyciskiem "Sprzedaj za X monet".
5. **Modal / Kontener na ruletkę:**
   - Miejsce na animację przewijania przedmiotów (dla kolegi od JS).

---

### @jgpostrach — JavaScript / Walidacja i interakcje


**Co zrobić w JS:**
1. **Zapisywanie tokena sesji:**
   Po zalogowaniu zapisuj otrzymany `token` w `localStorage`:
   ```javascript
   localStorage.setItem('token', data.token);
   ```
2. **Wysyłanie tokena w zapytaniach:**
   Wszystkie chronione endpointy (`/api/me.php`, `/api/open_case.php`, `/api/inventory.php`, `/api/sell_item.php`) wymagają nagłówka:
   ```javascript
   headers: {
       'Content-Type': 'application/json',
       'Authorization': 'Bearer ' + localStorage.getItem('token')
   }
   ```
3. **Animacja otwierania skrzynki (Ruletka):**
   Gdy wywołasz `POST /api/open_case.php`, w odpowiedzi dostaniesz:
   - `result.wylosowany_przedmiot` – przedmiot, który gracz faktycznie wygrał.
   - `result.wszystkie_przedmioty` – pełną listę przedmiotów w skrzynce.
   Możesz wygenerować poziomy pasek z losowymi przedmiotami i zakończyć animację przewijania dokładnie na wylosowanym przedmiocie!
4. **Walidacja formularzy:**
   - Sprawdzanie czy login ma min. 3 znaki.
   - Sprawdzanie czy hasło ma min. 8 znaków.
   - Sprawdzanie czy email zawiera `@`.

---

### 📝 @moxmar26 — Treść, dane testowe i dokumentacja

**Co zrobić:**
1. **Rozbudowa danych w `database/seed.sql`:**
   - Dodaj więcej ryb do tabeli `przedmioty` (np. rzadkie gatunki morskie, ryby drapieżne, jesiotry, nowe łowiska).
   - Skonfiguruj wagi $w_i$ w tabeli `skrzynka_przedmioty`, pamiętając o wzorze z `README.md`:
     - Im większa waga $w_i$, tym częściej wypada dany przedmiot.
     - Przykładowe wagi: 50 (Zwykła), 30 (Rzadka), 15 (Epicka), 5 (Legendarna).
2. **Balans ekonomii:**
   - Upewnij się, że cena skrzynki jest dobrze zbalansowana w stosunku do wartości nagród (żeby wirtualna platforma nie zbankrutowała po 3 losowaniach).
3. **Dokumentacja projektu:**
   - Przygotowanie opisów skrzynek i fabuły (np. motyw ryb z gifa `assets/input2.gif`).
   - Opis działania aplikacji do sprawozdania szkolnego.

---

## 4. Ściągawka: Endpointy REST API dla JavaScriptu

Wszystkie endpointy przyjmują i zwracają JSON.

### A. Rejestracja nowego gracza
- **URL:** `POST /api/register.php`
- **Body JSON:**
  ```json
  {
    "login": "nowy_gracz",
    "email": "gracz@poczta.pl",
    "password": "bezpiecznehaslo123"
  }
  ```
- **Odpowiedź (201):**
  ```json
  {
    "success": true,
    "user_id": 3,
    "message": "Rejestracja udana! Możesz się teraz zalogować."
  }
  ```

---

### B. Logowanie
- **URL:** `POST /api/login.php`
- **Body JSON:**
  ```json
  {
    "login": "nowy_gracz",
    "password": "bezpiecznehaslo123"
  }
  ```
- **Odpowiedź (200):**
  ```json
  {
    "token": "a78f69c0d3bf...",
    "token_type": "Bearer",
    "expires_at": "2026-09-19 12:00:00",
    "user": {
      "id": 3,
      "login": "nowy_gracz",
      "email": "gracz@poczta.pl",
      "rola": "user",
      "saldo": 50.00
    }
  }
  ```

---

### C. Pobranie danych gracza i salda
- **URL:** `GET /api/me.php`
- **Nagłówek:** `Authorization: Bearer <TWÓJ_TOKEN>`
- **Odpowiedź (200):**
  ```json
  {
    "user": {
      "id": 3,
      "login": "nowy_gracz",
      "saldo": 50.00
    }
  }
  ```

---

### D. Pobranie listy skrzynek ze sklepu
- **URL:** `GET /api/cases.php`
- **Odpowiedź (200):**
  ```json
  {
    "cases": [
      {
        "id": 1,
        "nazwa": "Skrzynia Jeziorna",
        "koszt": 10.00,
        "ikona": "🎣",
        "przedmioty": [
          { "nazwa": "Karaś Pospolity", "rzadkosc": "Zwykła", "wartosc": 4.00, "szansa_procent": 50 },
          { "nazwa": "Szczupak Pospolity", "rzadkosc": "Rzadka", "wartosc": 18.00, "szansa_procent": 30 },
          { "nazwa": "Węgorz Europejski", "rzadkosc": "Epicka", "wartosc": 75.00, "szansa_procent": 15 },
          { "nazwa": "Bieługa Królewska", "rzadkosc": "Legendarna", "wartosc": 500.00, "szansa_procent": 5 }
        ]
      }
    ]
  }
  ```

---

### E. Otwarcie skrzynki
- **URL:** `POST /api/open_case.php`
- **Nagłówek:** `Authorization: Bearer <TWÓJ_TOKEN>`
- **Body JSON:**
  ```json
  { "case_id": 1 }
  ```
- **Odpowiedź (200):**
  ```json
  {
    "success": true,
    "wylosowany_przedmiot": {
      "inventory_id": 12,
      "nazwa": "Srebrny Szczupak",
      "rzadkosc": "Rzadka",
      "wartosc": 18.00,
      "ikona": "🎣",
      "szansa_procent": 30
    },
    "saldo_przed": 50.00,
    "saldo_po": 40.00,
    "wszystkie_przedmioty": [ ... ]
  }
  ```

---

### F. Pobranie ekwipunku gracza
- **URL:** `GET /api/inventory.php`
- **Nagłówek:** `Authorization: Bearer <TWÓJ_TOKEN>`
- **Odpowiedź (200):**
  ```json
  {
    "inventory": [
      {
        "inventory_id": 12,
        "nazwa": "Srebrny Szczupak",
        "rzadkosc": "Rzadka",
        "wartosc": 18.00,
        "ikona": "🎣"
      }
    ]
  }
  ```

---

### G. Sprzedaż przedmiotu z ekwipunku
- **URL:** `POST /api/sell_item.php`
- **Nagłówek:** `Authorization: Bearer <TWÓJ_TOKEN>`
- **Body JSON:**
  ```json
  { "inventory_id": 12 }
  ```
- **Odpowiedź (200):**
  ```json
  {
    "success": true,
    "sprzedany_przedmiot": "Srebrny Szczupak",
    "otrzymane_monety": 18.00,
    "nowe_saldo": 58.00,
    "message": "Pomyślnie sprzedano 'Srebrny Szczupak' za 18 monet!"
  }
  ```

---

## 5. Gotowy przykład zapytania w JavaScript (dla @jgpostrach)

```javascript
// Przykład otwarcia skrzynki o ID 1:
async function otworzSkrzynke(caseId) {
    const token = localStorage.getItem('token');
    
    const response = await fetch('api/open_case.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + token
        },
        body: JSON.stringify({ case_id: caseId })
    });

    const data = await response.json();

    if (response.ok) {
        console.log('Wygrano:', data.wylosowany_przedmiot.nazwa);
        console.log('Nowe saldo:', data.saldo_po);
    } else {
        alert('Błąd: ' + data.error);
    }
}
```

*Pytać @KubeKoslaw*
