<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;
use Throwable;

/**
 * backend/Ekwipunek.php
 *
 * Klasa Ekwipunek z diagramu klas:
 *
 *     - id : int
 *     - data_nabycia : datetime
 *     - sprzedany : bool
 *     + dodaj(p : Przedmiot)
 *     + usun(p : Przedmiot)
 *     + sprzedaj(p : Przedmiot) : float
 *     + wartoscRynkowa() : float
 *
 * Asocjacje z diagramu:
 *   Uzytkownik ◆— Ekwipunek (1 : 1..*)  -> ekwipunek.user_id
 *   Ekwipunek — Przedmiot (* : *)       -> ekwipunek.przedmiot_id
 *
 * Jeden wiersz tabeli `ekwipunek` to jedna złowiona sztuka, a metody z diagramu
 * przyjmują Przedmiot — więc operują w zakresie ekwipunku tego gracza
 * (klasa jest trzymana per użytkownik: `new Ekwipunek($userId)`).
 *
 * Sprzedaż jest w transakcji i — tak samo jak Portfel::pobierzOplate — używa
 * UPDATE z warunkiem `sprzedany = 0`. Dzięki temu dwukrotne kliknięcie
 * „Sprzedaj" (albo dwa równoległe żądania) nie wypłaci monet dwa razy.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class Ekwipunek
{
    public function __construct(
        public readonly int $uzytkownikId,
        private ?PDO $db = null,
    ) {
    }

    /**
     * Metoda z diagramu: dokłada przedmiot do ekwipunku gracza.
     *
     * Wartość jest zamrażana w chwili nabycia (domyślnie
     * Przedmiot::obliczWartosc()), żeby późniejsza zmiana balansu nie
     * przeceniła tego, co gracz już ma.
     *
     * @return int id nowego wpisu
     */
    public function dodaj(Przedmiot $przedmiot, ?float $wartosc = null): int
    {
        $db = $this->polaczenie();

        $stmt = $db->prepare('INSERT INTO ekwipunek (user_id, przedmiot_id, wartosc) VALUES (?, ?, ?)');
        $stmt->execute([
            $this->uzytkownikId,
            $przedmiot->id,
            $wartosc ?? $przedmiot->obliczWartosc(),
        ]);

        return (int) $db->lastInsertId();
    }

    /**
     * Metoda z diagramu: usuwa przedmiot z ekwipunku (najstarszy niesprzedany
     * wpis — gracz może mieć kilka takich samych sztuk).
     */
    public function usun(Przedmiot $przedmiot): bool
    {
        $db = $this->polaczenie();

        $wpis = $this->najstarszyWpis($przedmiot->id);

        if ($wpis === null) {
            return false;
        }

        $stmt = $db->prepare('DELETE FROM ekwipunek WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) $wpis['id'], $this->uzytkownikId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Metoda z diagramu: sprzedaje przedmiot i zwraca otrzymane monety.
     * Sprzedaje najstarszy niesprzedany wpis z tym przedmiotem (FIFO).
     */
    public function sprzedaj(Przedmiot $przedmiot): float
    {
        $wpis = $this->najstarszyWpis($przedmiot->id);

        if ($wpis === null) {
            throw ApiException::notFound('Nie znaleziono takiego przedmiotu w ekwipunku.', 'item_not_found');
        }

        return $this->sprzedajWiersz((int) $wpis['id'], (float) $wpis['wartosc'])['kwota'];
    }

    /** Metoda z diagramu: łączna rynkowa wartość niesprzedanych przedmiotów. */
    public function wartoscRynkowa(): float
    {
        $stmt = $this->polaczenie()->prepare(
            'SELECT COALESCE(SUM(wartosc), 0) FROM ekwipunek WHERE user_id = ? AND sprzedany = 0'
        );
        $stmt->execute([$this->uzytkownikId]);

        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Wariant API: sprzedaż po jednoznacznym id wpisu.
     *
     * `sprzedaj(Przedmiot)` z diagramu sprzedaje najstarszą sztukę, ale gracz
     * może mieć trzy identyczne ryby i frontend klika w KONKRETNY wpis —
     * stąd ta metoda. Kształt odpowiedzi zostaje taki, jaki zna frontend.
     *
     * @return array<string, mixed>
     */
    public function sprzedajWpis(int $ekwipunekId): array
    {
        $db = $this->polaczenie();

        $stmt = $db->prepare('SELECT * FROM ekwipunek WHERE id = ? AND user_id = ?');
        $stmt->execute([$ekwipunekId, $this->uzytkownikId]);

        $row = $stmt->fetch();

        if ($row === false) {
            // Celowo nie odróżniamy „nie istnieje" od „nie twoje" — inaczej
            // można by po id wyliczyć, jakie wpisy mają inni gracze.
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

    /**
     * Przedmioty gracza.
     *
     * @return list<array<string, mixed>>
     */
    public function lista(bool $zeSprzedanymi = false): array
    {
        $sql = 'SELECT e.id AS ekwipunek_id,
                       e.wartosc,
                       e.sprzedany,
                       e.data_nabycia,
                       e.sprzedano_kiedy,
                       p.id AS przedmiot_id,
                       p.nazwa,
                       p.rzadkosc_kod,
                       p.ikona,
                       z.nazwa AS rzadkosc_nazwa,
                       z.kolor
                FROM ekwipunek e
                JOIN przedmioty p ON p.id = e.przedmiot_id
                JOIN rzadkosci z  ON z.kod = p.rzadkosc_kod
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
            ];
        }

        return $out;
    }

    /** Podsumowanie ekwipunku — do paska stanu na stronie. @return array<string, mixed> */
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

    /**
     * Jeden wpis ekwipunku gracza — z przedmiotem i zamrożoną wartością.
     *
     * Rzuca 404, gdy wpisu nie ma albo należy do innego gracza, i 409, gdy jest
     * już sprzedany (nie da się go wtedy ulepszyć ani wymienić).
     *
     * @return array<string, mixed>
     */
    public function wpis(int $ekwipunekId): array
    {
        $db = $this->polaczenie();

        $stmt = $db->prepare(
            'SELECT e.id AS ekwipunek_id, e.przedmiot_id, e.wartosc, e.data_nabycia, e.sprzedany,
                    p.nazwa, p.ikona, p.opis,
                    z.kod AS rzadkosc_kod, z.nazwa AS rzadkosc_nazwa, z.kolor, z.kolejnosc, z.mnoznik
             FROM ekwipunek e
             JOIN przedmioty p ON p.id = e.przedmiot_id
             JOIN rzadkosci z  ON z.kod = p.rzadkosc_kod
             WHERE e.id = ? AND e.user_id = ?'
        );
        $stmt->execute([$ekwipunekId, $this->uzytkownikId]);

        $row = $stmt->fetch();

        if ($row === false) {
            throw ApiException::notFound('Nie masz takiego przedmiotu w ekwipunku.', 'item_not_found');
        }

        if ((int) $row['sprzedany'] === 1) {
            throw ApiException::conflict('Ten przedmiot został już sprzedany.', 'already_sold');
        }

        return self::mapujWpis($row);
    }

    /**
     * Wiele wpisów naraz — tyle, ile potrzebuje Trader (kontrakt 3–10 sztuk).
     *
     * @param list<int> $ekwipunekIds
     * @return list<array<string, mixed>> w tej samej kolejności co $ekwipunekIds
     */
    public function wpisy(array $ekwipunekIds): array
    {
        $unikalne = array_values(array_unique(array_map('intval', $ekwipunekIds)));

        if ($unikalne !== array_values($ekwipunekIds)) {
            throw ApiException::badRequest('Ten sam przedmiot jest wybrany dwa razy.', 'duplicate_entry');
        }

        $out = [];

        foreach ($unikalne as $id) {
            $out[] = $this->wpis($id);
        }

        return $out;
    }

    /**
     * Trwałe usunięcie wpisu z bazy («spalenie»).
     *
     * To jest krok „Zablokowanie i trwałe usunięcie wkładu" z upgradera oraz
     * „Trwałe usunięcie (spalenie) wybranych przedmiotów" z tradera. Wpis znika
     * z tabeli `ekwipunek` — nie da się go sprzedać ani odzyskać.
     *
     * @return array<string, mixed> usunięty wpis (ten sam kształt co wpis())
     */
    public function spalWpis(int $ekwipunekId): array
    {
        $wpis = $this->wpis($ekwipunekId);

        $db = $this->polaczenie();
        $wlasna = !$db->inTransaction();

        if ($wlasna) {
            $db->beginTransaction();
        }

        try {
            $stmt = $db->prepare('DELETE FROM ekwipunek WHERE id = ? AND user_id = ? AND sprzedany = 0');
            $stmt->execute([$ekwipunekId, $this->uzytkownikId]);

            // rowCount 0 = wpis zniknął między naszym SELECT a DELETE (np. gracz
            // kliknął „Sprzedaj" w drugiej karcie).
            if ($stmt->rowCount() === 0) {
                throw ApiException::conflict('Ten przedmiot został już sprzedany.', 'already_sold');
            }

            if ($wlasna) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($wlasna && $db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }

        return $wpis;
    }

    /**
     * Spalenie wielu wpisów w JEDNEJ transakcji — albo wszystkie, albo żadne.
     *
     * @param list<int> $ekwipunekIds
     * @return list<array<string, mixed>>
     */
    public function spalWpisy(array $ekwipunekIds): array
    {
        // Walidacja wszystkich wpisów PRZED transakcją: trader nie może spalić
        // trzech ryb i wywalić się na czwartej.
        $wpisy = $this->wpisy($ekwipunekIds);

        $db = $this->polaczenie();
        $wlasna = !$db->inTransaction();

        if ($wlasna) {
            $db->beginTransaction();
        }

        try {
            $stmt = $db->prepare('DELETE FROM ekwipunek WHERE id = ? AND user_id = ? AND sprzedany = 0');

            foreach ($wpisy as $wpis) {
                $stmt->execute([(int) $wpis['ekwipunek_id'], $this->uzytkownikId]);

                if ($stmt->rowCount() === 0) {
                    throw ApiException::conflict(
                        'Przedmiot „' . $wpis['nazwa'] . '" został już sprzedany.',
                        'already_sold'
                    );
                }
            }

            if ($wlasna) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($wlasna && $db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }

        return $wpisy;
    }

    /**
     * Łączna zamrożona wartość wpisów — «Zsumowanie łącznej wartości rynkowej
     * wkładu» z kontraktu tradera.
     *
     * @param list<array<string, mixed>> $wpisy
     */
    public static function sumaWartosci(array $wpisy): float
    {
        $suma = 0.0;

        foreach ($wpisy as $wpis) {
            $suma += (float) ($wpis['wartosc'] ?? 0.0);
        }

        return round($suma, 2);
    }

    /** @param array<string, mixed> $row wiersz z JOIN-a ekwipunek + przedmioty + rzadkosci */
    private static function mapujWpis(array $row): array
    {
        return [
            'ekwipunek_id' => (int) $row['ekwipunek_id'],
            'przedmiot_id' => (int) $row['przedmiot_id'],
            'nazwa' => (string) $row['nazwa'],
            'rzadkosc' => (string) $row['rzadkosc_nazwa'],
            'rzadkosc_kod' => (string) $row['rzadkosc_kod'],
            'rzadkosc_kolejnosc' => (int) $row['kolejnosc'],
            'kolor' => (string) $row['kolor'],
            'wartosc' => round((float) $row['wartosc'], 2),
            'ikona' => Config::iconUrl((string) $row['ikona']),
            'data_nabycia' => (string) $row['data_nabycia'],
        ];
    }

    /** Najstarszy niesprzedany wpis z tym przedmiotem (FIFO). @return array<string, mixed>|null */
    private function najstarszyWpis(int $przedmiotId): ?array
    {
        $stmt = $this->polaczenie()->prepare(
            'SELECT id, wartosc FROM ekwipunek
             WHERE user_id = ? AND przedmiot_id = ? AND sprzedany = 0
             ORDER BY data_nabycia ASC, id ASC
             LIMIT 1'
        );
        $stmt->execute([$this->uzytkownikId, $przedmiotId]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Wspólny, atomowy rdzeń sprzedaży: oznacza wpis jako sprzedany i dopisuje
     * monety do portfela w JEDNEJ transakcji.
     *
     * @return array{kwota: float, saldo: float}
     */
    private function sprzedajWiersz(int $ekwipunekId, float $cena): array
    {
        $db = $this->polaczenie();

        $db->beginTransaction();

        try {
            $upd = $db->prepare("UPDATE ekwipunek SET sprzedany = 1, sprzedano_kiedy = datetime('now')
                                 WHERE id = ? AND user_id = ? AND sprzedany = 0");
            $upd->execute([$ekwipunekId, $this->uzytkownikId]);

            // rowCount 0 = ktoś sprzedał ten wpis między naszym SELECT a UPDATE.
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
