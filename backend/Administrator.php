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
 * Endpointy panelu administratora (strona 3 diagramu PROJEKT) nie są jeszcze
 * zbudowane — ta klasa jest domeną, którą te endpointy tylko wywołają.
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
     * Klasa Skrzynka (tryb skrzynek, POST /api/open_case.php) nie jest jeszcze
     * zaimplementowana, więc parametr nie może być nią typowany — sygnatura
     * z diagramu jest zachowana, a wywołanie uczciwie zgłasza 501 zamiast
     * udawać, że coś edytuje.
     */
    public function edytujSkrzynke(mixed $skrzynka): void
    {
        throw ApiException::notImplemented(
            'Tryb skrzynek (klasa Skrzynka z diagramu klas) nie jest jeszcze zaimplementowany.',
            'cases_not_implemented'
        );
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
