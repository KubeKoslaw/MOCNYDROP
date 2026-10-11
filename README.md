# MOCNY DROP

Strona z elementami hazardu z nagrodami o różnych wartościach, oparta na wirtualnej walucie. Projekt szkolny — losowanie nagród, konto użytkownika i zarządzanie stanem konta.

## Tech stack

- **Frontend:** HTML + CSS + JavaScript
- **Backend:** PHP
- **Baza danych:** SQLite

## Wymagania

- PHP 8.2+ z rozszerzeniem `pdo_sqlite` (XAMPP ma je w zestawie)
- Dowolne IDE wspierające powyższe języki programowania *(Rekomendowane: VSCode, VSCodium, Apache NetBeans, PhpStorm itp.)*

## Szybki start

```bash
php database/migrate.php --fresh   # baza + dane startowe (6 wędek, 54 przedmioty)
php -S localhost:8000              # serwer
```

Gra: <http://localhost:8000/dev/playground.html>

Animacja łowienia (mock, bez logowania): <http://localhost:8000/dev/fishing-demo.html>

`dev/playground.html` to tymczasowa konsola developerska do testowania API —
docelowy layout powstanie w `public/`. Szczegóły (instalacja PHP na różnych
systemach, XAMPP, testy, reset bazy, doładowywanie salda) są w
[INSTRUKCJA_DLA_ZESPOLU.md](INSTRUKCJA_DLA_ZESPOLU.md#2-jak-uruchomić-lokalnie).

## Animacja łowienia

Zamiast otwierania skrzynki gracz łowi rybę wędką: tafla wody ze spławikiem,
zarzut, oczekiwanie na branie, zacięcie, wyciąganie zdobyczy z wody i karta
z wylosowanym dropem.

```
idle -> requesting -> casting -> waiting -> bite -> reeling -> reveal
```

- **Komponent:** `<fishing-scene>` — czysty HTML/CSS/JS (custom element + moduły ES,
  bez bundlera i bez zależności), `public/assets/js/fishing/` + `public/assets/css/fishing-scene.css`.
- **Logika losowania bez zmian:** wynik ustala serwer, a pasek `reel` z API płynie
  pod wodą i zatrzymuje się na `reel_indeks_wygranej` — to jest moment brania.
  W przeglądarce nie ma żadnego losowania dropu.
- **Responsywność:** kadr 16:9 (mobile 4:5), rozmiary w `cqw` + `clamp()`,
  obsługa `prefers-reduced-motion`.
- Dokumentacja komponentu (atrybuty, zdarzenia, zmienne CSS, strojenie czasów):
  [INSTRUKCJA_DLA_ZESPOLU.md — sekcja 7](INSTRUKCJA_DLA_ZESPOLU.md#7-animacja-łowienia--komponent-fishing-scene).

## Zespół

Podział obowiązków:

| Osoba | Rola |
|---|---|
| @KubeKoslaw | Core Backend, Tech lead | 
| @moxmar26 | Treść, dane testowe i dokumentacja | 
| @jgpostrach | JavaScript / Walidacja i interakcje |
| @THOPA174 | Layout w HTML/CSS |

> 💡 **Instrukcja dla zespołu:** architektura backendu, schemat bazy, podział
> zadań i ściągawka z endpointów API są w pliku
> [INSTRUKCJA_DLA_ZESPOLU.md](INSTRUKCJA_DLA_ZESPOLU.md).

## Jak to działa

Losowanie nagrody to ważone losowanie z rozkładem prawdopodobieństwa. Szansa na wylosowanie nagrody $i$ z wagą $w_i$:

$$P(i) = \frac{w_i}{\sum_{j=1}^{n} w_j}$$

Przykładowe wagi (rzadkość nagrody rośnie → waga maleje):

| Rzadkość | Waga $w_i$ | Szansa $P(i)$ |
|---|---|---|
| Pospolita | $50$ | $50\%$ |
| Przeciętna | $30$ | $30\%$ |
| Niezła | $15$ | $15\%$ |
| Mityczna | $4$ | $4\%$ |
| Niesamowita | $1$ | $1\%$ |
|  *ULTRA MEGA NIESAMOWITA* | $0.001$ | $0.001\%$ |


## Plan działania

- [x] 1. Ustalenie schematu barwnego, działania i wyglądu strony (słownictwo, przyciski itd.).
- [ ] 2. Fundamenty strony — prosty statyczny wygląd, pozycjonowanie kart zgodnie z projektem, połączenie z PHP, prototyp animacji w JavaScript.
- [ ] 3. Implementacja algorytmu losowania, aktualizacja stanu strony (konta), wyświetlanie zapytań, styl losowanych przycisków.
- [ ] 4. Testy, merytoryka i ewentualna naprawa błędów.

PROJEKT W PEŁNI LEGALNY BO BEZ PRAWDZIWEJ WALUTY POZDRAWIAM *cytat moxmar*

