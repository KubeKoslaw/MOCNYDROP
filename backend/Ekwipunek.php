<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;
use Throwable;

final class Ekwipunek
{
    public function __construct(
        public readonly int $uzytkownikId,
        private ?PDO $db = null,
    ) {
    }

    public function dodaj(Przedmiot $przedmiot, ?float $wartosc = null): int
    {
        $db = $this->polaczenie();

        $wedka = Wedka::dlaPrzedmiotu($przedmiot->id, $db);

        $stmt = $db->prepare('INSERT INTO ekwipunek (user_id, przedmiot_id, wartosc, zarzuty_pozostale) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $this->uzytkownikId,
            $przedmiot->id,
            $wartosc ?? $przedmiot->obliczWartosc(),
            $wedka?->limitZarzutow,
        ]);

        return (int) $db->lastInsertId();
    }

    public function zuzyjZarzut(int $przedmiotId): ?array
    {
        $db = $this->polaczenie();

        $stmt = $db->prepare(
            'SELECT id, wartosc, zarzuty_pozostale FROM ekwipunek
             WHERE user_id = ? AND przedmiot_id = ? AND sprzedany = 0 AND zarzuty_pozostale > 0
             ORDER BY zarzuty_pozostale ASC, id ASC
             LIMIT 1'
        );
        $stmt->execute([$this->uzytkownikId, $przedmiotId]);

        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $id = (int) $row['id'];
        $zarzuty = (int) $row['zarzuty_pozostale'];
        $wartosc = (float) $row['wartosc'];
        $koszt = $zarzuty === 1 ? round($wartosc, 2) : round($wartosc / $zarzuty, 2);

        if ($zarzuty === 1) {
            $upd = $db->prepare('DELETE FROM ekwipunek WHERE id = ? AND zarzuty_pozostale = 1 AND sprzedany = 0');
            $upd->execute([$id]);
        } else {
            $upd = $db->prepare(
                'UPDATE ekwipunek SET zarzuty_pozostale = zarzuty_pozostale - 1, wartosc = ?
                 WHERE id = ? AND zarzuty_pozostale = ? AND sprzedany = 0'
            );
            $upd->execute([max(0.0, round($wartosc - $koszt, 2)), $id, $zarzuty]);
        }

        if ($upd->rowCount() === 0) {
            throw ApiException::conflict('Wędka została właśnie użyta lub sprzedana — spróbuj ponownie.', 'rod_changed');
        }

        return ['ekwipunek_id' => $id, 'koszt' => $koszt, 'zarzuty_pozostale' => $zarzuty - 1];
    }

    public function sprzedajWpis(int $ekwipunekId): array
    {
        $db = $this->polaczenie();

        $stmt = $db->prepare('SELECT * FROM ekwipunek WHERE id = ? AND user_id = ?');
        $stmt->execute([$ekwipunekId, $this->uzytkownikId]);

        $row = $stmt->fetch();

        if ($row === false) {

            throw ApiException::notFound('Nie znaleziono takiego przedmiotu w ekwipunku.', 'item_not_found');
        }

        if ((int) $row['sprzedany'] === 1) {
            throw ApiException::conflict('Ten przedmiot został już sprzedany.', 'already_sold');
        }

        $wynik = $this->sprzedajWiersz($ekwipunekId, (float) $row['wartosc']);

        $stmt = $db->prepare('SELECT nazwa FROM przedmioty WHERE id = ?');
        $stmt->execute([(int) $row['przedmiot_id']]);
        $nazwa = (string) $stmt->fetchColumn();

        return [
            'success' => true,
            'sprzedany_przedmiot' => [
                'ekwipunek_id' => $ekwipunekId,
                'przedmiot_id' => (int) $row['przedmiot_id'],
                'nazwa' => $nazwa,
            ],
            'otrzymane_monety' => $wynik['kwota'],
            'nowe_saldo' => $wynik['saldo'],
            'message' => sprintf('Sprzedano "%s" za %s monet.', $nazwa, ApiException::money($wynik['kwota'])),
        ];
    }

    public function lista(bool $zeSprzedanymi = false): array
    {
        $sql = 'SELECT e.id AS ekwipunek_id,
                       e.wartosc,
                       e.sprzedany,
                       e.data_nabycia,
                       e.sprzedano_kiedy,
                       e.zarzuty_pozostale,
                       w.id AS wedka_id,
                       p.id AS przedmiot_id,
                       p.nazwa,
                       p.rzadkosc_kod,
                       p.ikona,
                       z.nazwa AS rzadkosc_nazwa,
                       z.kolor
                FROM ekwipunek e
                JOIN przedmioty p ON p.id = e.przedmiot_id
                JOIN rzadkosci z  ON z.kod = p.rzadkosc_kod
                LEFT JOIN wedki w ON w.przedmiot_id = p.id
                WHERE e.user_id = ?';

        if (!$zeSprzedanymi) {
            $sql .= ' AND e.sprzedany = 0';
        }

        $sql .= ' ORDER BY z.kolejnosc DESC, e.id DESC';

        $stmt = $this->polaczenie()->prepare($sql);
        $stmt->execute([$this->uzytkownikId]);

        $out = [];

        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'ekwipunek_id' => (int) $row['ekwipunek_id'],
                'przedmiot_id' => (int) $row['przedmiot_id'],
                'nazwa' => (string) $row['nazwa'],
                'rzadkosc' => (string) $row['rzadkosc_nazwa'],
                'rzadkosc_kod' => (string) $row['rzadkosc_kod'],
                'kolor' => (string) $row['kolor'],
                'wartosc' => (float) $row['wartosc'],
                'ikona' => Config::iconUrl((string) $row['ikona']),
                'sprzedany' => (int) $row['sprzedany'] === 1,
                'data_nabycia' => (string) $row['data_nabycia'],
                'sprzedano_kiedy' => $row['sprzedano_kiedy'] === null ? null : (string) $row['sprzedano_kiedy'],
                'wedka_id' => $row['wedka_id'] === null ? null : (int) $row['wedka_id'],
                'zarzuty_pozostale' => $row['zarzuty_pozostale'] === null ? null : (int) $row['zarzuty_pozostale'],
            ];
        }

        return $out;
    }

    public function podsumowanie(): array
    {
        $stmt = $this->polaczenie()->prepare(
            'SELECT COUNT(*) AS liczba, COALESCE(SUM(wartosc), 0) AS suma
             FROM ekwipunek
             WHERE user_id = ? AND sprzedany = 0'
        );
        $stmt->execute([$this->uzytkownikId]);

        $row = $stmt->fetch();

        return [
            'liczba_przedmiotow' => (int) ($row['liczba'] ?? 0),
            'laczna_wartosc' => round((float) ($row['suma'] ?? 0.0), 2),
        ];
    }

    private function sprzedajWiersz(int $ekwipunekId, float $cena): array
    {
        $db = $this->polaczenie();

        $db->beginTransaction();

        try {
            $upd = $db->prepare("UPDATE ekwipunek SET sprzedany = 1, sprzedano_kiedy = CURRENT_TIMESTAMP
                                 WHERE id = ? AND user_id = ? AND sprzedany = 0");
            $upd->execute([$ekwipunekId, $this->uzytkownikId]);

            if ($upd->rowCount() === 0) {
                throw ApiException::conflict('Ten przedmiot został już sprzedany.', 'already_sold');
            }

            $portfel = Portfel::dlaUzytkownika($this->uzytkownikId, $db);
            $portfel->dodajSrodki($cena);

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }

        return ['kwota' => round($cena, 2), 'saldo' => round($portfel->saldo, 2)];
    }

    private function polaczenie(): PDO
    {
        return $this->db ??= Database::connection();
    }
}
