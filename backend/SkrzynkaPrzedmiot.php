<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;
use Throwable;

final class SkrzynkaPrzedmiot implements ILosujacy
{
    public function __construct(
        public readonly int $id,
        public readonly int $skrzynkaId,
        public readonly int $przedmiotId,
        public readonly float $waga,
        private ?PDO $db = null,
    ) {
    }

    public function losuj(): Przedmiot
    {
        return Przedmiot::wymagaj($this->przedmiotId, $this->polaczenie());
    }

    public static function pula(int $skrzynkaId, ?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        $stmt = $db->prepare(
            'SELECT sp.id, sp.skrzynka_id, sp.przedmiot_id,
                    COALESCE(sp.waga, z.waga_domyslna) AS waga
             FROM skrzynka_przedmioty sp
             JOIN przedmioty p ON p.id = sp.przedmiot_id
             JOIN rzadkosci z  ON z.kod = p.rzadkosc_kod
             WHERE sp.skrzynka_id = ? AND p.czy_aktywny = 1
             ORDER BY z.kolejnosc ASC, p.wartosc ASC, p.id ASC'
        );
        $stmt->execute([$skrzynkaId]);

        $out = [];

        foreach ($stmt->fetchAll() as $row) {
            $out[] = self::zWiersza($row, $db);
        }

        return $out;
    }

    public static function ustawPule(int $skrzynkaId, array $wpisy, ?PDO $pdo = null): int
    {
        $db = $pdo ?? Database::connection();

        if ($skrzynkaId <= 0) {
            throw ApiException::badRequest('Skrzynka musi być zapisana przed przypisaniem puli.', 'case_not_saved');
        }

        if ($wpisy === []) {
            throw ApiException::badRequest(
                'Pula skrzynki nie może być pusta — skrzynki bez dropów nie da się otworzyć.',
                'case_pool_empty'
            );
        }

        $przygotowane = [];
        $seen = [];

        foreach ($wpisy as $wpis) {
            if (!is_array($wpis)) {
                throw ApiException::badRequest('Wpis puli musi być obiektem {przedmiot_id, waga}.', 'invalid_pool_entry');
            }

            $przedmiotId = (int) ($wpis['przedmiot_id'] ?? 0);

            if ($przedmiotId <= 0) {
                throw ApiException::badRequest('Wpis puli musi wskazywać przedmiot (przedmiot_id > 0).', 'invalid_pool_entry');
            }

            if (isset($seen[$przedmiotId])) {
                throw ApiException::conflict(
                    'Przedmiot o id ' . $przedmiotId . ' jest już w tej puli — waga musi być jedną liczbą.',
                    'duplicate_pool_entry'
                );
            }

            $seen[$przedmiotId] = true;

            $przedmiot = Przedmiot::znajdz($przedmiotId, $db);

            if ($przedmiot === null) {
                throw ApiException::notFound('Nie ma przedmiotu o id ' . $przedmiotId . '.', 'item_not_found');
            }

            $waga = array_key_exists('waga', $wpis) ? $wpis['waga'] : null;

            if ($waga === null || (is_string($waga) && trim($waga) === '')) {
                $waga = null;
            } else {
                $waga = is_numeric($waga) ? (float) $waga : NAN;

                if (!is_finite($waga) || $waga <= 0) {
                    throw ApiException::badRequest(
                        'Waga przy przedmiocie „' . $przedmiot->nazwa
                            . '" musi być dodatnią liczbą (albo pustą = waga domyślna).',
                        'invalid_weight'
                    );
                }
            }

            $przygotowane[] = [$skrzynkaId, $przedmiotId, $waga];
        }

        $wlasna = !$db->inTransaction();

        if ($wlasna) {
            $db->beginTransaction();
        }

        try {
            $db->prepare('DELETE FROM skrzynka_przedmioty WHERE skrzynka_id = ?')->execute([$skrzynkaId]);

            $stmt = $db->prepare(
                'INSERT INTO skrzynka_przedmioty (skrzynka_id, przedmiot_id, waga) VALUES (?, ?, ?)'
            );

            foreach ($przygotowane as $parametry) {
                $stmt->execute($parametry);
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

        return count($przygotowane);
    }

    public static function zWiersza(array $row, ?PDO $pdo = null): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['skrzynka_id'],
            (int) $row['przedmiot_id'],
            (float) $row['waga'],
            $pdo,
        );
    }

    private function polaczenie(): PDO
    {
        return $this->db ??= Database::connection();
    }
}
