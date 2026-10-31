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

## Szybki start

```bash
cp config/database.example.php config/database.local.php
# Uzupełnij plik lokalny danymi utworzonej bazy i użytkownika MariaDB.
php database/migrate.php          # tabele + dane gry; zachowuje istniejące dane
php -S localhost:8000             # serwer lokalny
```

Konfiguracja lokalna z hasłem jest
ignorowana przez Git. MariaDB nie tworzy tabel przy żądaniu API; inicjalizację
wykonaj przed uruchomieniem strony. Opcja `--fresh` jest dla MariaDB blokowana.

Opcjonalnie, do lokalnej pracy bez serwera MariaDB:

```bash
DB_DRIVER=sqlite php database/migrate.php --fresh
DB_DRIVER=sqlite php -S localhost:8000
```

Testy: `php tests/run.php` (SQLite w pamięci), a wariant MariaDB opisano
w [sekcji testów](#testy-lokalne).

Gra: <http://localhost:8000/dev/playground.html>

Animacja łowienia (mock, bez logowania): <http://localhost:8000/dev/fishing-demo.html>

`dev/playground.html` to tymczasowa konsola developerska do testowania API —
docelowy layout powstanie w `public/`. Konsola pokrywa wszystkie przypadki
użycia z diagramu: łowisko, skrzynki, upgrader, trader, ekwipunek, historię
i panel administratora.


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
