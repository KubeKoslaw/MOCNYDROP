# MOCNY DROP

Strona z elementami hazardu z nagrodami o różnych wartościach, oparta na wirtualnej walucie. Projekt szkolny — losowanie nagród, konto użytkownika i zarządzanie stanem konta.

## Tech stack

- **Frontend:** HTML + CSS + JavaScript
- **Backend:** PHP
- **Baza danych:** SQLite

## Wymagania

- XAMPP (Apache + PHP + SQLite)
- Dowolne IDE wspierające powyższe języki programowania *(Rekomendowane: VSCode, VSCodium, Apache NetBeans, PhpStorm itp.)*

## Zespół

Podział obowiązków:

| Osoba | Rola |
|---|---|
| @KubeKoslaw | Core Backend, Tech lead | 
| @moxmar26 | Treść, dane testowe i dokumentacja | 
| @jgpostrach | JavaScript / Walidacja i interakcje |
| @THOPA174 | Layout w HTML/CSS |

## Jak to działa

Losowanie nagrody to ważone losowanie z rozkładem prawdopodobieństwa. Szansa na wylosowanie nagrody $i$ z wagą $w_i$:

$$P(i) = \frac{w_i}{\sum_{j=1}^{n} w_j}$$

Przykładowe wagi (rzadkość nagrody rośnie → waga maleje):

| Rzadkość | Waga $w_i$ | Szansa $P(i)$ |
|---|---|---|
| Zwykła | $50$ | $50\%$ |
| Rzadka | $30$ | $30\%$ |
| Epicka | $15$ | $15\%$ |
| Legendarna | $4$ | $4\%$ |
| Mityczna | $1$ | $1\%$ |


## Plan działania

- [x] 1. Ustalenie schematu barwnego, działania i wyglądu strony (słownictwo, przyciski itd.).
- [ ] 2. Fundamenty strony — prosty statyczny wygląd, pozycjonowanie kart zgodnie z projektem, połączenie z PHP, prototyp animacji w JavaScript.
- [ ] 3. Implementacja algorytmu losowania, aktualizacja stanu strony (konta), wyświetlanie zapytań, styl losowanych przycisków.
- [ ] 4. Testy, merytoryka i ewentualna naprawa błędów.

PROJEKT W PEŁNI LEGALNY BO BEZ PRAWDZIWEJ WALUTY POZDRAWIAM *cytat moxmar*

