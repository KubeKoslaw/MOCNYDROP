<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;

/** Pula modeli wędek; stare skrzynka_przedmioty pozostają jedynie dla historii. */
final class SkrzynkaWedka
{
    public static function ustawPule(int $skrzynkaId, array $wpisy, PDO $db): int
    {
        if ($wpisy === []) {
            throw ApiException::badRequest('Wybierz przynajmniej jedną wędkę.', 'case_pool_empty');
        }
        $rows = [];
        foreach ($wpisy as $wpis) {
            if (!is_array($wpis) || !isset($wpis['wedka_id']) || filter_var($wpis['wedka_id'], FILTER_VALIDATE_INT) === false || (int) $wpis['wedka_id'] <= 0) {
                throw ApiException::badRequest('Wpis puli musi wskazywać wedka_id.', 'invalid_pool_entry');
            }
            $id = (int) $wpis['wedka_id'];
            if (isset($rows[$id])) {
                throw ApiException::conflict('Wędka jest już w tej puli.', 'duplicate_pool_entry');
            }
            Wedka::wymagaj($id, $db);
            $waga = $wpis['waga'] ?? 1;
            if (!is_numeric($waga) || !is_finite((float) $waga) || (float) $waga <= 0) {
                throw ApiException::badRequest('Waga musi być dodatnią liczbą.', 'invalid_weight');
            }
            $rows[$id] = (float) $waga;
        }
        if (!is_finite(array_sum($rows))) {
            throw ApiException::badRequest('Suma wag jest zbyt duża.', 'invalid_weight');
        }
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM skrzynka_wedki WHERE skrzynka_id = ?')->execute([$skrzynkaId]);
            $stmt = $db->prepare('INSERT INTO skrzynka_wedki (skrzynka_id, wedka_id, waga) VALUES (?, ?, ?)');
            foreach ($rows as $id => $waga) $stmt->execute([$skrzynkaId, $id, $waga]);
            if ($own) $db->commit();
        } catch (\Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return count($rows);
    }
}
