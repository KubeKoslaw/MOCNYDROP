<?php

declare(strict_types=1);

/**
 * api/_bootstrap.php
 *
 * Wspólny punkt wejścia wszystkich endpointów w api/.
 * Plik zaczyna się od "_" — to sygnał, że nie jest to samodzielny endpoint.
 *
 * Robi trzy rzeczy: ładuje autoloader, ustawia CORS dla localhosta
 * i przekłada wyjątki na odpowiedzi JSON.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\ApiException;
use MocnyDrop\Json;

require_once dirname(__DIR__) . '/backend/bootstrap.php';

mb_internal_encoding('UTF-8');

// Nie zdradzamy, że backend to PHP.
if (!headers_sent()) {
    header_remove('X-Powered-By');
}

Json::enableCors();

/**
 * Jeden handler wyjątków dla całego API.
 *
 * Rozróżniamy dwa przypadki:
 *  - ApiException  → przewidywalny błąd biznesowy, komunikat leci do gracza
 *  - cokolwiek innego → 500 z ogólnym tekstem, szczegóły tylko do error_log
 *
 * Bez tego rozróżnienia ścieżka serwera albo fragment SQL wyszłyby do
 * przeglądarki, a to klasyczny wyciek informacji.
 */
set_exception_handler(static function (Throwable $e): void {
    if ($e instanceof ApiException) {
        Json::failWith($e);
    }

    error_log(sprintf(
        '[MOCNYDROP] %s: %s @ %s:%d',
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    Json::fail('Wewnętrzny błąd serwera.', 'server_error', 500);
});
