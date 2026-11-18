<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;
use Throwable;

final class FishingService
{

    public static function cast(int $userId, int $wedkaId, ?callable $random = null, ?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        $wedka = Wedka::wymagaj($wedkaId, $db);
        $pula = $wedka->pula($db);

        if (!$wedka->zarzuc()) {
            throw ApiException::badRequest(
                $wedka->czyAktywna
                    ? 'Ta wędka nie ma jeszcze żadnych przedmiotów.'
                    : 'Ta wędka jest obecnie niedostępna.',
                $wedka->czyAktywna ? 'rod_empty' : 'rod_inactive'
            );
        }

        $ekwipunek = new Ekwipunek($userId, $db);

        $db->beginTransaction();

        try {

            $zarzut = $wedka->przedmiotId === null ? null : $ekwipunek->zuzyjZarzut($wedka->przedmiotId);

            if ($zarzut === null) {
                throw ApiException::badRequest(
                    'Nie masz tej wędki albo skończyły się jej zarzuty — zdobądź ją w skrzynce.',
                    'rod_not_owned'
                );
            }

            $koszt = $zarzut['koszt'];
            $saldo = Portfel::dlaUzytkownika($userId, $db)->saldo;

            $winner = WeightedRoller::roll($pula, $random);
            $wynik = Przedmiot::zPuli($winner);

            $wartosc = $wynik->obliczWartosc();

            $ekwipunekId = $ekwipunek->dodaj($wynik, $wartosc);

            $losowanie = new Losowanie(
                $userId,
                TypLosowania::Wedka,
                $wynik,
                date('Y-m-d H:i:s'),
                $koszt,
                $wartosc,
                (float) $winner['waga'],
                (float) $winner['suma_wag'],
                $wedka->id,
                0,
                $db
            );
            $losowanie->zapisz();

            $historiaId = $losowanie->id;

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }

        $dlugosc = Settings::getInt(Settings::KEY_REEL_LENGTH, 60, $db);
        $indeksWygranej = Settings::getInt(Settings::KEY_REEL_WINNER_INDEX, 54, $db);

        if ($indeksWygranej >= $dlugosc) {
            $indeksWygranej = max(0, $dlugosc - 1);
        }

        $reel = Reel::build($pula, $winner, $dlugosc, $indeksWygranej, $random);

        return [
            'success' => true,
            'rzut' => [
                'id' => $historiaId,
                'typ' => TypLosowania::Wedka->value,
                'koszt' => $koszt,
            ],
            'wedka' => [
                'id' => $wedka->id,
                'nazwa' => $wedka->nazwa,
                'koszt_uzycia' => $koszt,
                'zarzuty_pozostale' => $wedka->zarzutyGracza($userId, $db),
            ],
            'przedmiot' => [
                'ekwipunek_id' => $ekwipunekId,
                'przedmiot_id' => $wynik->id,
                'nazwa' => $wynik->nazwa,
                'rzadkosc' => $wynik->rzadkosc->nazwa,
                'rzadkosc_kod' => $wynik->rzadkosc->kod,
                'kolor' => $wynik->rzadkosc->kolor,
                'wartosc' => $wartosc,
                'ikona' => $wynik->ikonaUrl(),
                'szansa_procent' => (float) $winner['szansa_procent'],
            ],
            'saldo_przed' => round($saldo, 2),
            'saldo_po' => round($saldo, 2),
            'reel' => $reel,
            'reel_dlugosc' => $dlugosc,
            'reel_indeks_wygranej' => $indeksWygranej,
            'message' => sprintf('Złowiono: %s (%s)!', $wynik->nazwa, $wynik->rzadkosc->nazwa),
        ];
    }
}
