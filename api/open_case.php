<?php

declare(strict_types=1);

use MocnyDrop\ApiException;
use MocnyDrop\Json;
use MocnyDrop\Skrzynka;
use MocnyDrop\Token;

require_once __DIR__ . '/_bootstrap.php';
Json::requireMethod('POST');
$u = Token::wymagajZalogowania();
$body = Json::readBody();
if (filter_var($body['sprzedaj_natychmiast'] ?? false, FILTER_VALIDATE_BOOL)) {
    throw ApiException::badRequest('Skrzynki odblokowują wędki. Duplikaty rozliczamy automatycznie.', 'case_sell_removed');
}
$id = Json::stringField($body, 'request_id');
if ($id === '') { throw ApiException::badRequest('Podaj request_id otwarcia.', 'missing_request_id'); }
Json::success(Skrzynka::wymagaj(Json::intField($body, 'skrzynka_id'))->otworzSzczegolowo($u, requestId: $id));
