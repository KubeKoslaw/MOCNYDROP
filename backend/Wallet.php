<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;

/**
 * backend/Wallet.php
 *
 * Operacje na saldzie gracza.
 *
 * Kluczowa rzecz: obciążenie robimy JEDNYM zapytaniem UPDATE z warunkiem
 * `saldo >= kwota`, a nie odczytem i zapisem w dwóch krokach. Dzięki temu
 * dwa równoległe rzuty wędką nie wydadzą tych samych monet — przy
 * podejściu "SELECT potem UPDATE" oba żądania przeczytałyby stare saldo
 * i przeszły, schodząc poniżej zera.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class Wallet
{
    public static function balance(PDO $db, int $userId): float
    {
        $stmt = $db->prepare('SELECT saldo FROM uzytkownicy WHERE id = ?');
        $stmt->execute([$userId]);

        $saldo = $stmt->fetchColumn();

        if ($saldo === false || $saldo === null) {
            throw ApiException::notFound('Gracz nie istnieje.');
        }

        return (float) $saldo;
    }

    /**
     * Pobiera opłatę. Rzuca ApiException::insufficientFunds, gdy brak środków.
     *
     * @return float saldo po operacji
     */
    public static function charge(PDO $db, int $userId, float $amount): float
    {
        self::assertAmount($amount);

        $stmt = $db->prepare('UPDATE uzytkownicy SET saldo = saldo - ? WHERE id = ? AND saldo >= ?');
        $stmt->execute([$amount, $userId, $amount]);

        if ($stmt->rowCount() === 0) {
            // Albo gracz nie istnieje, albo nie ma środków — sprawdzamy które.
            $saldo = self::balance($db, $userId);

            throw ApiException::insufficientFunds($amount, $saldo);
        }

        return self::balance($db, $userId);
    }

    /**
     * Dopisuje wygraną / zwrot ze sprzedaży.
     *
     * @return float saldo po operacji
     */
    public static function credit(PDO $db, int $userId, float $amount): float
    {
        self::assertAmount($amount);

        $stmt = $db->prepare('UPDATE uzytkownicy SET saldo = saldo + ? WHERE id = ?');
        $stmt->execute([$amount, $userId]);

        if ($stmt->rowCount() === 0) {
            throw ApiException::notFound('Gracz nie istnieje.');
        }

        return self::balance($db, $userId);
    }

    /**
     * Pilnuje arytmetyki: NaN, INF i wartości ujemne nie mają prawa wejść
     * do salda. Schemat ma CHECK (saldo >= 0), ale lepiej odrzucić wcześniej
     * i powiedzieć wprost, co jest nie tak.
     */
    private static function assertAmount(float $amount): void
    {
        if (!is_finite($amount) || $amount < 0) {
            throw ApiException::badRequest('Kwota musi być nieujemną, skończoną liczbą.');
        }
    }
}
