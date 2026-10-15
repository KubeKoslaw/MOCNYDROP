<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;
use Throwable;

/** Skrzynki odblokowują wędki. Ryby nadal pochodzą wyłącznie z łowiska. */
final class Skrzynka
{
    public ?array $pulaDoZapisu = null;

    public function __construct(
        public readonly string $nazwa,
        public readonly float $cena,
        public readonly string $ikona = '',
        public readonly bool $aktywna = true,
        public readonly string $opis = '',
        public readonly int $kolejnosc = 0,
        public int $id = 0,
        private ?PDO $db = null,
        public readonly bool $dzienna = false,
    ) {}

    public function czyAktywna(): bool { return $this->aktywna; }

    public static function aktywne(?PDO $pdo = null): array
    {
        return self::lista('SELECT * FROM skrzynki WHERE aktywna = 1 ORDER BY kolejnosc, cena, id', $pdo);
    }

    public static function wszystkie(?PDO $pdo = null): array
    {
        return self::lista('SELECT * FROM skrzynki ORDER BY kolejnosc, cena, id', $pdo);
    }

    private static function lista(string $sql, ?PDO $pdo): array
    {
        $db = $pdo ?? Database::connection();
        return array_map(static fn (array $r): self => self::zWiersza($r, $db), $db->query($sql)->fetchAll());
    }

    public static function znajdz(int $id, ?PDO $pdo = null): ?self
    {
        $db = $pdo ?? Database::connection();
        $stmt = $db->prepare('SELECT * FROM skrzynki WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::zWiersza($row, $db);
    }

    public static function wymagaj(int $id, ?PDO $pdo = null): self
    {
        return self::znajdz($id, $pdo) ?? throw ApiException::notFound('Nie ma takiej skrzynki.', 'case_not_found');
    }

    public static function nowa(string $nazwa, float $cena, string $ikona = '', string $opis = '', bool $aktywna = true, int $kolejnosc = 0, ?PDO $pdo = null, bool $dzienna = false): self
    {
        return new self($nazwa, $cena, $ikona, $aktywna, $opis, $kolejnosc, 0, $pdo, $dzienna);
    }

    public static function zWiersza(array $row, ?PDO $pdo = null): self
    {
        return new self((string) $row['nazwa'], (float) $row['cena'], (string) $row['ikona'], (bool) $row['aktywna'], (string) $row['opis'], (int) $row['kolejnosc'], (int) $row['id'], $pdo, (bool) $row['dzienna']);
    }

    public function pula(?PDO $pdo = null): array
    {
        $db = $pdo ?? $this->polaczenie();
        $stmt = $db->prepare('SELECT w.*, sw.waga, z.nazwa AS rzadkosc, z.kolor
            FROM skrzynka_wedki sw JOIN wedki w ON w.id = sw.wedka_id
            JOIN rzadkosci z ON z.kod = w.rzadkosc_kod
            WHERE sw.skrzynka_id = ? AND w.czy_aktywna = 1
              AND EXISTS (SELECT 1 FROM wedka_przedmioty wp JOIN przedmioty p ON p.id = wp.przedmiot_id WHERE wp.wedka_id = w.id AND p.czy_aktywny = 1)
            ORDER BY w.kolejnosc, w.id');
        $stmt->execute([$this->id]);
        $rows = $stmt->fetchAll();
        $chances = $rows === [] ? [] : WeightedRoller::chances($rows);
        return array_map(static fn (array $r, int $i): array => [
            'wedka_id' => (int) $r['id'], 'nazwa' => $r['nazwa'], 'typ' => 'wedka',
            'ikona' => Config::iconUrl($r['ikona']), 'rzadkosc' => $r['rzadkosc'],
            'rzadkosc_kod' => $r['rzadkosc_kod'], 'kolor' => $r['kolor'],
            'koszt_uzycia' => (float) $r['koszt_uzycia'], 'rekompensata' => (float) $r['rekompensata'],
            'waga' => (float) $r['waga'], 'szansa_procent' => $chances[$i],
        ], $rows, array_keys($rows));
    }

    public function czyDajeSieOtworzyc(): bool { return $this->aktywna && $this->pula() !== []; }

    public function losuj(): Wedka
    {
        $winner = WeightedRoller::roll($this->wymagajPuli());
        return Wedka::wymagaj($winner['wedka_id'], $this->polaczenie());
    }

    public function otworz(Uzytkownik $u): Wedka
    {
        $wynik = $this->otworzSzczegolowo($u);
        return Wedka::wymagaj($wynik['nagroda']['wedka_id'], $this->polaczenie());
    }

    private function wymagajPuli(): array
    {
        if (!$this->aktywna) {
            throw ApiException::badRequest('Ta skrzynka jest niedostępna.', 'case_inactive');
        }
        $pool = $this->pula();
        if ($pool === []) {
            throw ApiException::badRequest('Ta skrzynka nie ma dostępnych wędek.', 'case_empty');
        }
        return $pool;
    }

    public static function nastepnyOdbior(int $userId, PDO $db): int
    {
        $stmt = $db->prepare('SELECT odebrano FROM odbiory_dzienne WHERE user_id = ?');
        $stmt->execute([$userId]);
        $last = $stmt->fetchColumn();
        return $last === false ? 0 : (int) $last + 86400;
    }

    /** Opłata, własność, rekompensata, zegar i potwierdzenie są atomowe. */
    public function otworzSzczegolowo(Uzytkownik $u, ?callable $random = null, ?PDO $pdo = null, ?string $requestId = null): array
    {
        $db = $pdo ?? $this->polaczenie();
        if ($requestId !== null && preg_match('/^[a-zA-Z0-9_-]{8,100}$/D', $requestId) !== 1) {
            throw ApiException::badRequest('Niepoprawny identyfikator żądania.', 'invalid_request_id');
        }
        $db->beginTransaction();
        try {
            // Blokada zapisu PRZED pierwszym odczytem. Inaczej SELECT ustawień
            // portfela tworzy snapshot i konkurencyjny INSERT może dostać SQLITE_BUSY.
            $db->prepare('UPDATE portfele SET saldo = saldo WHERE user_id = ?')->execute([$u->id]);
            $portfel = Portfel::dlaUzytkownika($u->id, $db);
            if ($requestId !== null) {
                $stmt = $db->prepare('SELECT skrzynka_id, odpowiedz FROM otwarcia_skrzynek WHERE user_id = ? AND request_id = ?');
                $stmt->execute([$u->id, $requestId]);
                $receipt = $stmt->fetch();
                if ($receipt !== false) {
                    if ((int) $receipt['skrzynka_id'] !== $this->id) {
                        throw ApiException::conflict('Identyfikator należy do innego otwarcia.', 'request_id_conflict');
                    }
                    $db->commit();
                    return json_decode($receipt['odpowiedz'], true, 512, JSON_THROW_ON_ERROR);
                }
            }
            $pool = $this->wymagajPuli();
            $now = time();
            if ($this->dzienna && self::nastepnyOdbior($u->id, $db) > $now) {
                throw ApiException::conflict('Darmowa skrzynka będzie dostępna 24 godziny po ostatnim odbiorze.', 'daily_case_cooldown');
            }
            $cena = $this->dzienna ? 0.0 : $this->cena;
            $saldoPrzed = $portfel->saldo;
            if ($cena > 0 && !$portfel->pobierzOplate($cena)) {
                throw ApiException::insufficientFunds($cena, $saldoPrzed);
            }
            PosiadaneWedki::startowa($u->id, $db);
            $winner = WeightedRoller::roll($pool, $random);
            $duplikat = !PosiadaneWedki::przyznaj($u->id, $winner['wedka_id'], $db);
            $rekompensata = $duplikat ? $winner['rekompensata'] : 0.0;
            if ($rekompensata > 0) {
                $portfel->dodajSrodki($rekompensata);
            }
            $stmt = $db->prepare("INSERT INTO historia_losowan
                (user_id, typ, wynik_wedka_id, skrzynka_id, koszt, wygrana_wartosc, waga_wylosowana, suma_wag, duplikat)
                VALUES (?, 'skrzynka', ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$u->id, $winner['wedka_id'], $this->id, $cena, $rekompensata, $winner['waga'], $winner['suma_wag'], $duplikat ? 1 : 0]);
            $historyId = (int) $db->lastInsertId();
            if ($this->dzienna) {
                $db->prepare('INSERT INTO odbiory_dzienne (user_id, odebrano) VALUES (?, ?) ON CONFLICT(user_id) DO UPDATE SET odebrano = excluded.odebrano')->execute([$u->id, $now]);
            }
            unset($winner['suma_wag']);
            $result = [
                'otwarcie' => ['id' => $historyId, 'typ' => 'skrzynka', 'cena' => $cena],
                'skrzynka' => ['id' => $this->id, 'nazwa' => $this->nazwa, 'dzienna' => $this->dzienna],
                'nagroda' => $winner, 'duplikat' => $duplikat, 'rekompensata' => $rekompensata,
                'saldo_przed' => $saldoPrzed, 'saldo_po' => $portfel->saldo,
                'nastepny_odbior' => $this->dzienna ? $now + 86400 : null,
                'czas_serwera' => $now,
                'message' => $duplikat
                    ? sprintf('Duplikat: %s. Otrzymujesz %s monet rekompensaty.', $winner['nazwa'], ApiException::money($rekompensata))
                    : sprintf('Odblokowano: %s! Możesz używać jej w łowisku.', $winner['nazwa']),
            ];
            if ($requestId !== null) {
                $db->prepare('INSERT INTO otwarcia_skrzynek (user_id, request_id, skrzynka_id, odpowiedz) VALUES (?, ?, ?, ?)')
                    ->execute([$u->id, $requestId, $this->id, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            }
            $db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $e;
        }
    }

    public function doApi(?int $userId = null): array
    {
        $next = $this->dzienna && $userId !== null ? self::nastepnyOdbior($userId, $this->polaczenie()) : 0;
        return [
            'id' => $this->id, 'nazwa' => $this->nazwa, 'cena' => $this->dzienna ? 0 : $this->cena,
            'ikona' => Config::iconUrl($this->ikona), 'opis' => $this->opis, 'aktywna' => $this->aktywna,
            'kolejnosc' => $this->kolejnosc, 'dzienna' => $this->dzienna, 'wedki' => $this->pula(),
            'nastepny_odbior' => $this->dzienna ? $next : null,
        ];
    }

    private function polaczenie(): PDO { return $this->db ??= Database::connection(); }
}
