<?php

declare(strict_types=1);

use MocnyDrop\ApiException;
use MocnyDrop\Json;

require_once dirname(__DIR__) . '/backend/bootstrap.php';

mb_internal_encoding('UTF-8');

if (!headers_sent()) {
    header_remove('X-Powered-By');
}

Json::enableCors();

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
