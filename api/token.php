<?php

declare(strict_types=1);

use MocnyDrop\ApiException;
use MocnyDrop\Json;
use MocnyDrop\OauthKod;
use MocnyDrop\Token;
use MocnyDrop\Uzytkownik;

require_once __DIR__ . '/_bootstrap.php';

Json::requireMethod('POST');

$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
$body = str_contains($contentType, 'application/json') ? Json::readBody() : $_POST;

$grantType = strtolower(Json::stringField($body, 'grant_type'));

$zwrocToken = static function (Token $token): never {
    $uzytkownik = Uzytkownik::znajdz($token->uzytkownikId);

    Json::success($token->doApi() + ['user' => $uzytkownik->doPublicznej()]);
};

switch ($grantType) {
    case 'authorization_code':
        $kod = OauthKod::znajdz(Json::stringField($body, 'code'));

        if ($kod === null) {
            throw ApiException::badRequest('Kod autoryzacyjny jest nieprawidłowy.', 'invalid_grant');
        }

        $uzytkownikId = $kod->wymien();
        $uzytkownik = Uzytkownik::znajdz($uzytkownikId);

        if ($uzytkownik->czyZablokowany()) {
            throw ApiException::forbidden('Konto jest zablokowane.', 'account_blocked');
        }

        $zwrocToken(Token::wystaw($uzytkownik));

    case 'refresh_token':
        $token = Token::odswiez(Json::stringField($body, 'refresh_token'));
        $uzytkownik = Uzytkownik::znajdz($token->uzytkownikId);

        if ($uzytkownik->czyZablokowany()) {
            throw ApiException::forbidden('Konto jest zablokowane.', 'account_blocked');
        }

        $zwrocToken($token);

    default:
        throw ApiException::badRequest(
            'Nieobsługiwany grant_type. Dozwolone: authorization_code, refresh_token.',
            'unsupported_grant_type'
        );
}
