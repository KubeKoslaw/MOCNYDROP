<?php

declare(strict_types=1);

/**
 * api/inventory.php — GET /api/inventory.php
 * Nagłówek: Authorization: Bearer <token>
 *
 * Query (opcjonalne): ?z_sprzedanymi=1  — pokaż też przedmioty już sprzedane
 *
 * Klasa Ekwipunek z diagramu klas (atrybuty data_nabycia i sprzedany).
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\Ekwipunek;
use MocnyDrop\Json;
use MocnyDrop\Token;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('GET');

$uzytkownik = Token::wymagajZalogowania();

$includeSold = filter_var($_GET['z_sprzedanymi'] ?? '0', FILTER_VALIDATE_BOOL);

$ekwipunek = new Ekwipunek($uzytkownik->id);

Json::success([
    'ekwipunek' => $ekwipunek->lista($includeSold),
    'podsumowanie' => $ekwipunek->podsumowanie(),
]);
