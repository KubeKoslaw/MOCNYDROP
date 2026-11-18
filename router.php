<?php

declare(strict_types=1);

$sciezka = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$sciezka = '/' . ltrim(str_replace('\\', '/', rawurldecode((string) $sciezka)), '/');

$zablokowane = ['/backend/', '/database/', '/tests/', '/.github/', '/.git/'];

foreach ($zablokowane as $prefix) {
    if (str_starts_with($sciezka, $prefix)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Dostęp zabroniony.';
        exit;
    }
}
return false;
