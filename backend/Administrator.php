<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;
use Throwable;

/**
 * backend/Administrator.php
 *
 * Klasa Administrator z diagramu klas — GENERALIZACJA Uzytkownik (krawędź e0):
 *
 *     + zbanuj(u : Uzytkownik)
 *     + korygujSaldo(u : Uzytkownik, kwota : float)
 *     + edytujSkrzynke(s : Skrzynka)
 *     + eksportujRaport() : string
 *
 * plus przerywana asocjacja «zapisuje» do LogAudytowy (krawędź e15).
 *
 * W bazie administrator to zwykły wiersz `uzytkownicy` z rola = 'admin' — bez
 * osobnej tabeli, dokładnie tak jak na diagramie. Stąd dziedziczenie po
 * Uzytkownik zamiast osobnej encji.
 *
 * Endpointy panelu administratora (strona 3 diagramu PROJEKT) wywołują dokładnie
 * te cztery metody z diagramu: api/admin/skrzynki.php (edytujSkrzynke),
 * api/admin/uzytkownicy.php (zbanuj / odbanuj / korygujSaldo) i
 * api/admin/monitoring.php oraz api/admin/raport.php (eksportujRaport).
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class Administrator extends Uzytkownik
{
    /** Administrator z bieżącego żądania: token z nagłówka + rola admin. */
    public static function zalogowany(?PDO $pdo = null): self
    {
        $uzytkownik = Token::wymagajZalogowania($pdo);

        if (!$uzytkownik->rola->czyAdmin()) {
            throw ApiException::forbidden('Wymagane uprawnienia administratora.', 'admin_required');
        }

        return self::zUzytkownika($uzytkownik, $pdo);
    }

    /** Przeładowuje gracza jako Administrator (ta sama encja, inna rola w kodzie). */
    public static function zUzytkownika(Uzytkownik $uzytkownik, ?PDO $pdo = null): self
    {
        // znajdz() jest `static`, więc zwróci Administrator, a nie Uzytkownik.
        return self::znajdz($uzytkownik->id, $pdo);
    }

    /**
     * Metoda z diagramu: blokada konta (ban) i unieważnienie tokenów.
     *
     * Przypadek rozszerzający z diagramu use case — „Próba zablokowania
     * głównego administratora" — jest odrzucany: nie da się zbanować ani siebie,
     * ani innego administratora.
     */
    public function zbanuj(Uzytkownik $u): void
    {
        if ($u->id === $this->id || $u->rola->czyAdmin()) {
            throw ApiException::forbidden(
                'Nie można zablokować konta administratora.',
                'admin_cannot_be_banned'
            );
        }

        $db = $this->polaczenie();

        $db->beginTransaction();

        try {
            $stmt = $db->prepare('UPDATE uzytkownicy SET zablokowany = 1 WHERE id = ?');
            $stmt->execute([$u->id]);

            // Ban bez unieważnienia tokenów nic by nie dał: istniejący token
            // działałby dalej do końca ważności.
            $stmt = $db->prepare('DELETE FROM tokeny WHERE user_id = ?');
            $stmt->execute([$u->id]);

            LogAudytowy::wpis(
                'ban',
                $this->id,
                sprintf('user_id=%d login=%s', $u->id, $u->login),
                $db
            );

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }

        $u->zablokowany = true;
    }

    /**
     * Metoda z diagramu: ręczna korekta salda gracza.
     * Kwota dodatnia = doładowanie, ujemna = potrącenie.
     */
    public function korygujSaldo(Uzytkownik $u, float $kwota): void
    {
        if (!is_finite($kwota) || abs($kwota) < 0.01) {
            throw ApiException::badRequest('Kwota korekty musi być niezerową, skończoną liczbą.', 'invalid_amount');
        }

        $db = $this->polaczenie();
        $portfel = $u->portfel($db);

        if ($kwota > 0) {
            $portfel->dodajSrodki($kwota);
        } elseif (!$portfel->pobierzOplate(-$kwota)) {
            throw ApiException::insufficientFunds(-$kwota, $portfel->saldo);
        }

        LogAudytowy::wpis(
            'korekta_saldo',
            $this->id,
            sprintf('user_id=%d kwota=%.2f saldo_po=%.2f', $u->id, $kwota, $portfel->saldo),
            $db
        );
    }

    /**
     * Metoda z diagramu: `+ edytujSkrzynke(s : Skrzynka)`.
     *
     * «Kreator i Edytor Skrzynek» ze strony 3 diagramu PROJEKT — ta sama metoda
     * zakłada nową skrzynkę (id = 0) i aktualizuje istniejącą (id > 0).
     *
     * Zapis jest JEDEN i transakcyjny („Transakcyjny zapis zmian w tabelach
     * SQLite"): parametry skrzynki oraz przypisane dropy i wagi
     * (SkrzynkaPrzedmiot, tabela skrzynka_przedmioty) lądują w bazie razem
     * albo wcale.
     *
     * Przypadek rozszerzający ADM1_ERR — «Błędne wagi lub ujemna cena» — jest
     * odrzucany PRZED jakimkolwiek zapisem (kody invalid_price / invalid_weight).
     */
    public function edytujSkrzynke(Skrzynka $s): void
    {
        self::walidujSkrzynke($s);

        $db = $this->polaczenie();

        // Nazwa jest UNIQUE w schemacie — sprawdzamy ją sami, żeby zwrócić 409
        // zamiast surowego PDOException (które _bootstrap zamieniłby na 500).
        $stmt = $db->prepare('SELECT COUNT(*) FROM skrzynki WHERE nazwa = ? AND id != ?');
        $stmt->execute([$s->nazwa, $s->id]);

        if ((int) $stmt->fetchColumn() > 0) {
            throw ApiException::conflict('Skrzynka o tej nazwie już istnieje.', 'case_name_taken');
        }

        $tworzenie = $s->id <= 0;

        if (!$tworzenie) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM skrzynki WHERE id = ?');
            $stmt->execute([$s->id]);

            if ((int) $stmt->fetchColumn() === 0) {
                throw ApiException::notFound('Nie ma skrzynki o id ' . $s->id . '.', 'case_not_found');
            }
        }

        // Walidacja wag (druga część ADM1_ERR) też musi się zdarzyć przed zapisem
        // — ustawPule() sprawdza wszystko, zanim cokolwiek skasuje.
        $wlasna = !$db->inTransaction();

        if ($wlasna) {
            $db->beginTransaction();
        }

        try {
            if ($tworzenie) {
                $stmt = $db->prepare(
                    'INSERT INTO skrzynki (nazwa, opis, cena, ikona, aktywna, kolejnosc, dzienna)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $s->nazwa,
                    $s->opis,
                    $s->cena,
                    $s->ikona,
                    $s->aktywna ? 1 : 0,
                    $s->kolejnosc,
                    $s->dzienna ? 1 : 0,
                ]);

                $s->id = (int) $db->lastInsertId();
            } else {
                $stmt = $db->prepare(
                    'UPDATE skrzynki
                     SET nazwa = ?, opis = ?, cena = ?, ikona = ?, aktywna = ?, kolejnosc = ?, dzienna = ?
                     WHERE id = ?'
                );
                $stmt->execute([
                    $s->nazwa,
                    $s->opis,
                    $s->cena,
                    $s->ikona,
                    $s->aktywna ? 1 : 0,
                    $s->kolejnosc,
                    $s->dzienna ? 1 : 0,
                    $s->id,
                ]);
            }

            $wpisow = 0;

            // null = „nie ruszaj puli" (np. samo przełączenie aktywna = 0/1).
            if ($s->pulaDoZapisu !== null) {
                $wpisow = SkrzynkaWedka::ustawPule($s->id, $s->pulaDoZapisu, $db);
            }

            LogAudytowy::wpis(
                $tworzenie ? 'skrzynka_utworzona' : 'skrzynka_edycja',
                $this->id,
                sprintf(
                    'skrzynka_id=%d nazwa=%s cena=%.2f aktywna=%d wpisy_puli=%d',
                    $s->id,
                    $s->nazwa,
                    $s->cena,
                    $s->aktywna ? 1 : 0,
                    $wpisow
                ),
                $db
            );

            if ($wlasna) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($wlasna && $db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Walidacja parametrów skrzynki — «Błędne wagi lub ujemna cena» (ADM1_ERR).
     * Schemat ma CHECK (cena >= 0), ale odrzucamy to wcześniej i po polsku.
     */
    private static function walidujSkrzynke(Skrzynka $s): void
    {
        if (trim($s->nazwa) === '' || mb_strlen($s->nazwa) > 100) {
            throw ApiException::badRequest('Nazwa skrzynki musi mieć od 1 do 100 znaków.', 'invalid_case_name');
        }

        if (!is_finite($s->cena) || $s->cena < 0) {
            throw ApiException::badRequest('Cena skrzynki nie może być ujemna.', 'invalid_price');
        }

        if ($s->dzienna && $s->cena != 0) {
            throw ApiException::badRequest('Dzienna skrzynka musi być darmowa.', 'invalid_price');
        }
        if (mb_strlen($s->opis) > 500) {
            throw ApiException::badRequest('Opis skrzynki może mieć maksymalnie 500 znaków.', 'invalid_case_description');
        }

        if ($s->kolejnosc < 0) {
            throw ApiException::badRequest('Kolejność wyświetlania nie może być ujemna.', 'invalid_case_order');
        }
    }

    /**
     * Zdjęcie blokady z konta — odwrotność zbanuj() z diagramu.
     *
     * Panel administratora musi umieć cofnąć bana («Zablokowanie konta» jest
     * przełącznikiem, nie operacją jednokierunkową). Tokeny po banie są
     * usunięte, więc gracz loguje się od nowa.
     */
    public function odbanuj(Uzytkownik $u): void
    {
        $db = $this->polaczenie();

        $db->beginTransaction();

        try {
            $stmt = $db->prepare('UPDATE uzytkownicy SET zablokowany = 0 WHERE id = ?');
            $stmt->execute([$u->id]);

            LogAudytowy::wpis(
                'odblokowanie',
                $this->id,
                sprintf('user_id=%d login=%s', $u->id, $u->login),
                $db
            );

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }

        $u->zablokowany = false;
    }

    /**
     * Metoda z diagramu: `+ eksportujRaport() : string`.
     *
     * Raport finansowy platformy jako CSV (strona 3 diagramu PROJEKT: „Eksport
     * raportu finansowego platformy do CSV/JSON"). Zwraca string, więc endpoint
     * może go wysłać jako plik albo wkleić w JSON.
     */
    public function eksportujRaport(): string
    {
        $db = $this->polaczenie();

        $rows = $db->query(
            'SELECT h.id, h.czas, h.typ, u.login, h.koszt, h.wygrana_wartosc
             FROM historia_losowan h
             LEFT JOIN uzytkownicy u ON u.id = h.user_id
             ORDER BY h.id ASC'
        )->fetchAll();

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Nie udało się otworzyć strumienia tymczasowego dla raportu.');
        }

        fputcsv($handle, ['id', 'czas', 'typ', 'login', 'koszt', 'wygrana_wartosc', 'bilans'], ',', '"', '');

        $sumaKoszt = 0.0;
        $sumaWygrana = 0.0;

        foreach ($rows as $row) {
            $koszt = (float) $row['koszt'];
            $wygrana = (float) $row['wygrana_wartosc'];

            $sumaKoszt += $koszt;
            $sumaWygrana += $wygrana;

            fputcsv($handle, [
                (int) $row['id'],
                (string) $row['czas'],
                (string) $row['typ'],
                (string) ($row['login'] ?? ''),
                $koszt,
                $wygrana,
                round($wygrana - $koszt, 2),
            ], ',', '"', '');
        }

        fputcsv($handle, [
            'SUMA', '', '', '',
            round($sumaKoszt, 2),
            round($sumaWygrana, 2),
            round($sumaWygrana - $sumaKoszt, 2),
        ], ',', '"', '');

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        LogAudytowy::wpis('eksport_raportu', $this->id, sprintf('wierszy=%d', count($rows)), $db);

        return $csv;
    }
}
