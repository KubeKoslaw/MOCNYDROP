# MOCNYDROP — plan projektu

## @KubeKoslaw — backend

- [x] Struktura katalogów
- [x] Konfiguracja i połączenie z bazą (SQLite/PDO)
- [x] Schemat bazy: użytkownicy, przedmioty, ekwipunek, skrzynki, historia
- [x] Migracje tworzą bazę
- [x] Kontrakt API: format JSON odpowiedzi
- [ ] Rejestracja i logowanie
- [ ] OAuth 2.0 i role użytkowników (access + refresh token, rola admin)
- [ ] Portfel i saldo
- [ ] Ważone losowanie
- [ ] Zapis losowań do historii
- [ ] Ekwipunek i zarzuty wędek (`inventory.php`)
- [ ] Katalog skrzynek i wędek (`skrzynki.php`, `wedki.php`)
- [ ] Łowisko (`fish.php`)
- [ ] Skrzynki (`open_case.php`)
- [ ] Darmowa skrzynka raz na 24 h
- [ ] Panel admina: skrzynki (API)
- [ ] Panel admina: użytkownicy i saldo (API)
- [ ] Panel admina: RTP, audyt, eksport (API)
- [ ] Kontrakt animacji: pasek ikon z serwera (`reel`)

## @moxmar26 — dane, testy, dokumentacja

- [ ] Seed skrzynek
- [ ] Seed wędek i ryb
- [ ] Wagi rzadkości
- [ ] Test rozkładu losowania
- [ ] Testy trybów (łowisko, skrzynki, darmowa skrzynka)
- [ ] Testy OAuth 2.0 i tokenów
- [ ] Test kontraktu animacji (`tests/check-fishing.mjs`)
- [ ] README z instalacją i uruchomieniem
- [ ] Diagramy zgodne z kodem

## @jgpostrach — JavaScript

- [ ] Klient API po ustaleniu kontraktu
- [ ] Klient OAuth 2.0 (kod → token → refresh)
- [ ] Obsługa błędów w UI
- [ ] Logowanie i rejestracja
- [ ] Profil: lista ekwipunku w modalu
- [ ] Lista skrzynek i wędek
- [ ] Walidacja formularzy
- [ ] Animacja łowienia (pasek ikon)
- [ ] Animacja otwierania skrzynki (rolka)

## @THOPA174 — layout HTML/CSS

- [ ] Szkielet strony i nawigacja
- [ ] Ekran logowania i rejestracji
- [ ] Profil jako modal (dane, przedmioty, zarzuty)
- [ ] Widoki trzech trybów (Łowisko, Skrzynki, Historia)
- [ ] Mobile i desktop
- [ ] Puste stany i ładowanie
- [ ] Testy klikane po stronie (manualna checklista)
