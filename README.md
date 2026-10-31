# MOCNY DROP

Strona z elementami hazardu z nagrodami o różnych wartościach, oparta na wirtualnej walucie. Projekt szkolny — losowanie nagród, konto użytkownika i zarządzanie stanem konta.

## Tech stack

- **Frontend:** HTML + CSS + JavaScript
- **Backend:** PHP
- **Baza danych:** SQLite lokalnie, w przyszłości mariadb

## Wymagania

- PHP 8.2+ z rozszerzeniami `pdo_mysql` i `mbstring`
- MariaDB 10.6+; do testów SQLite i generowania instalatora SQL także `pdo_sqlite`
- Dowolne IDE wspierające powyższe języki programowania *(Rekomendowane: VSCode, VSCodium, Apache NetBeans, PhpStorm itp.)*



## Zespół

Podział obowiązków:

| Osoba | Rola |
|---|---|
| @KubeKoslaw | Backend_1 - api, autoryzacja, rdzenne funkcje | 
| @moxmar26 | Backend_2 - Testy, integracja z bazą danych | 
| @jgpostrach | Frontend_1 - JS, logika łowienie i interakcji na stronie
| @THOPA174 | Frontend_2 - ayout w HTML/CSS |

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


## Plan na następne kroki:

*[Plan działania](plan.md)*
