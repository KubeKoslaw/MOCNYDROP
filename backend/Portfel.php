<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;

/**
 * backend/Portfel.php
 *
 * Klasa Portfel z diagramu klas:
 *
 *     - id : int
 *     - saldo : float
 *     - waluta : string
 *     + czyStac(koszt : float) : bool
 *     + pobierzOplate(kwota : float) : bool
 *     + dodajSrodki(kwota : float)
 *
 * Asocjacja z diagramu: Uzytkownik ◆— Portfel (1 : 1), «posiada». W bazie to
 * tabela `portfele` z user_id UNIQUE — pieniądze NIE leżą w `uzytkownicy`.
 *
 * Kluczowa rzecz: obciążenie robimy JEDNYM zapytaniem UPDATE z warunkiem
 * `saldo >= kwota`, a nie odczytem i zapisem w dwóch krokach. Dzięki temu dwa
 * równoległe zarzuty nie wydadzą tych samych monet — przy podejściu „SELECT
 * potem UPDATE" oba żądania przeczytałyby stare saldo i przeszły, schodząc
 * poniżej zera.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class Portfel
{
    /** Saldo jest zmienne: odświeżamy je po każdej operacji. */
    public float $saldo;

    public function __construct(
        public readonly int $id,
        public readonly int $uzytkownikId,
        float $saldo,
        public readonly string $waluta,
        private ?PDO $db = null,
    ) {
        $this->saldo = $saldo;
    }

    /** Metoda z diagramu: czy na portfelu jest dość środków na dany koszt. */
    public function czyStac(float $koszt): bool
    {
        $this->odswiez();

        return $koszt >= 0 && $this->saldo >= $koszt;
    }

    /**
     * Metoda z diagramu: pobiera opłatę.
     *
     * Zwraca false (a nie wyjątek), gdy brak środków — tak ma się zachowywać
     * wg sygnatury `: bool`. To wywołujący decyduje, co z tym zrobić;
     * FishingService rzuca wtedy ApiException::insufficientFunds().
     */
    public function pobierzOplate(float $kwota): bool
    {
        self::sprawdzKwote($kwota);

        $db = $this->polaczenie();

        $stmt = $db->prepare('UPDATE portfele SET saldo = saldo - ? WHERE user_id = ? AND saldo >= ?');
        $stmt->execute([$kwota, $this->uzytkownikId, $kwota]);

        // rowCount 0 = albo brak portfela, albo za mało środków. Przy kwocie 0
        // SQLite też potrafi nic nie zmienić, dlatego zero traktujemy osobno.
        if ($stmt->rowCount() === 0 && $kwota > 0.0) {
            $this->odswiez();

            return false;
        }

        $this->odswiez();

        return true;
    }

    /** Metoda z diagramu: dopisuje środki (wygrana, zwrot ze sprzedaży, korekta admina). */
    public function dodajSrodki(float $kwota): void
    {
        self::sprawdzKwote($kwota);

        $db = $this->polaczenie();

        $stmt = $db->prepare('UPDATE portfele SET saldo = saldo + ? WHERE user_id = ?');
        $stmt->execute([$kwota, $this->uzytkownikId]);

        if ($stmt->rowCount() === 0 && !self::istnieje($this->uzytkownikId, $db)) {
            throw ApiException::notFound('Gracz nie istnieje.', 'user_not_found');
        }

        $this->odswiez();
    }

    /**
     * Portfel gracza. Tworzy go, gdy jeszcze nie istnieje — kompozycja 1:1
     * z diagramu oznacza, że każdy Uzytkownik ma dokładnie jeden Portfel.
     */
    public static function dlaUzytkownika(int $userId, ?PDO $pdo = null): self
    {
        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare('INSERT OR IGNORE INTO portfele (user_id, saldo, waluta) VALUES (?, 0, ?)');
        $stmt->execute([$userId, Settings::get(Settings::KEY_CURRENCY, 'monety', $db)]);

        return self::wczytaj($userId, $db);
    }

    /** Nowy portfel z saldem startowym — wołane przez Uzytkownik::zarejestruj(). */
    public static function utworz(int $userId, ?float $saldoStartowe = null, ?PDO $pdo = null): self
    {
        $db = $pdo ?? Database::connection();

        $saldo = $saldoStartowe ?? Settings::getFloat(Settings::KEY_START_BALANCE, 50.0, $db);

        $stmt = $db->prepare(
            'INSERT INTO portfele (user_id, saldo, waluta) VALUES (?, ?, ?)
             ON CONFLICT(user_id) DO UPDATE SET saldo = excluded.saldo'
        );
        $stmt->execute([$userId, $saldo, Settings::get(Settings::KEY_CURRENCY, 'monety', $db)]);

        return self::wczytaj($userId, $db);
    }

    /** @return array<string, mixed> kształt zwracany przez API */
    public function doApi(): array
    {
        return [
            'saldo' => round($this->saldo, 2),
            'waluta' => $this->waluta,
        ];
    }

    public function odswiez(): void
    {
        $this->saldo = self::wczytaj($this->uzytkownikId, $this->polaczenie())->saldo;
    }

    private static function wczytaj(int $userId, PDO $db): self
    {
        $stmt = $db->prepare('SELECT id, user_id, saldo, waluta FROM portfele WHERE user_id = ?');
        $stmt->execute([$userId]);

        $row = $stmt->fetch();

        if ($row === false) {
            throw ApiException::notFound('Gracz nie ma portfela.', 'wallet_not_found');
        }

        return new self((int) $row['id'], (int) $row['user_id'], (float) $row['saldo'], (string) $row['waluta'], $db);
    }

    private static function istnieje(int $userId, PDO $db): bool
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM portfele WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Pilnuje arytmetyki: NaN, INF i wartości ujemne nie mają prawa wejść do
     * salda. Schemat ma CHECK (saldo >= 0), ale lepiej odrzucić wcześniej i
     * powiedzieć wprost, co jest nie tak.
     */
    private static function sprawdzKwote(float $kwota): void
    {
        if (!is_finite($kwota) || $kwota < 0) {
            throw ApiException::badRequest('Kwota musi być nieujemną, skończoną liczbą.');
        }
    }

    private function polaczenie(): PDO
    {
        return $this->db ??= Database::connection();
    }
}
