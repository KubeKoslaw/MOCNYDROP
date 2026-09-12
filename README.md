# MOCNY DROP
![Ryba](assets/input2.gif)

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

> 💡 **Instrukcja dla zespołu:** Szczegółowy opis architektury backendu, schematu bazy, zadań dla poszczególnych osób oraz ściągawkę zapytań API znajdziesz w pliku [INSTRUKCJA_DLA_ZESPOLU.md](INSTRUKCJA_DLA_ZESPOLU.md).

## Jak to działa

Losowanie nagrody to ważone losowanie z rozkładem prawdopodobieństwa. Szansa na wylosowanie nagrody $i$ z wagą $w_i$:

$$P(i) = \frac{w_i}{\sum_{j=1}^{n} w_j}$$

Przykładowe wagi (rzadkość nagrody rośnie → waga maleje):

| Rzadkość | Waga $w_i$ | Szansa $P(i)$ |
|---|---|---|
| Zwykła | $50$ | $50\%$ |
| Rzadka | $30$ | $30\%$ |
| Epicka | $15$ | $15\%$ |
| Legendarna | $5$ | $5\%$ |
Po każdym losowaniu stan konta aktualizowany jest o koszt losowania $c$ oraz wartość wygranej $v$:


$$S_{n+1} = S_n - c + v_i$$

gdzie $S_n$ to stan konta przed losowaniem, a $v_i$ — wartość wylosowanej nagrody $i$.

**Przykład:**

$$\begin{cases} 0.00-50.00 = Zwykła (50)
\newline 50.00-80.00 = Rzadka (30) 
\newline 80.00-95.00 = Epicka (15)
\newline 95.00-100.00 = Legendarna (5)
\end{cases}$$


## Plan działania

- [ ] 1. Ustalenie schematu barwnego, działania i wyglądu strony (słownictwo, przyciski itd.).
- [ ] 2. Fundamenty strony — prosty statyczny wygląd, pozycjonowanie kart zgodnie z projektem, połączenie z PHP, prototyp animacji w JavaScript.
- [ ] 3. Implementacja algorytmu losowania, aktualizacja stanu strony (konta), wyświetlanie zapytań, styl losowanych przycisków.
- [ ] 4. Testy, merytoryka i ewentualna naprawa błędów.

