<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;
use Throwable;

final class Administrator extends Uzytkownik
{

    public static function zalogowany(?PDO $pdo = null): self
    {
        $uzytkownik = Token::wymagajZalogowania($pdo);

        if (!$uzytkownik->rola->czyAdmin()) {
            throw ApiException::forbidden('Wymagane uprawnienia administratora.', 'admin_required');
        }

        return self::zUzytkownika($uzytkownik, $pdo);
    }

    public static function zUzytkownika(Uzytkownik $uzytkownik, ?PDO $pdo = null): self
    {

        return self::znajdz($uzytkownik->id, $pdo);
    }

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

            $stmt = $db->prepare('DELETE FROM oauth_tokeny WHERE user_id = ?');
            $stmt->execute([$u->id]);

            $stmt = $db->prepare('DELETE FROM oauth_kody WHERE user_id = ?');
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

}
