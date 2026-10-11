<?php

declare(strict_types=1);

/**
 * database/topup.php — skrypt CLI TYLKO dla środowiska developerskiego
 *
 * Użycie:
 *   php database/topup.php <login> <kwota>     np. php database/topup.php wedkarz 500
 *   php database/topup.php --all <kwota>       doładowuje każde konto
 *   php database/topup.php --list              pokazuje konta i salda
 *
 * Po co: w grze nie ma endpointu doładowania (monety zdobywa się sprzedażą
 * przedmiotów), więc po wyczerpaniu salda startowego testowanie pętli
 * „zarzut → sprzedaż" wymagałoby kasowania bazy. Ten skrypt pozwala ją
 * odblokować bez ruszania API.
 *
 * Pieniądze leżą w tabeli `portfele` (klasa Portfel z diagramu klas), więc
 * skrypt idzie przez Portfel::dodajSrodki() zamiast pisać UPDATE ręcznie.
 *
 * Zabezpieczenie: działa wyłącznie z CLI (jak database/migrate.php), więc nie da
 * się go wywołać przez HTTP i nie jest dziurą w ekonomii gry.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\Database;
use MocnyDrop\Portfel;
use MocnyDrop\Uzytkownik;

require_once dirname(__DIR__) . '/backend/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ten skrypt można uruchamiać tylko z linii komend.');
}

$args = array_slice($argv ?? [], 1);
$db = Database::connection();

/** @return list<array{id: int, login: string, saldo: float}> */
$allUsers = static function () use ($db): array {
    $rows = $db->query(
        'SELECT u.id, u.login, COALESCE(p.saldo, 0) AS saldo
         FROM uzytkownicy u
         LEFT JOIN portfele p ON p.user_id = u.id
         ORDER BY u.id ASC'
    )->fetchAll();

    return array_map(static fn (array $r): array => [
        'id' => (int) $r['id'],
        'login' => (string) $r['login'],
        'saldo' => (float) $r['saldo'],
    ], $rows);
};

$printAll = static function () use ($allUsers): void {
    foreach ($allUsers() as $user) {
        echo sprintf("  #%-4d %-20s %10.2f\n", $user['id'], $user['login'], $user['saldo']);
    }
};

if ($args === [] || in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo "Użycie:\n"
        . "  php database/topup.php <login> <kwota>\n"
        . "  php database/topup.php --all <kwota>\n"
        . "  php database/topup.php --list\n";
    exit(0);
}

if ($args[0] === '--list') {
    if ($allUsers() === []) {
        echo "Brak kont. Zarejestruj gracza: POST /api/register.php\n";
        exit(0);
    }

    $printAll();
    exit(0);
}

$kwota = isset($args[1]) ? str_replace(',', '.', trim($args[1])) : '';

if (!is_numeric($kwota) || (float) $kwota <= 0) {
    fwrite(STDERR, 'Kwota musi być liczbą dodatnią.' . PHP_EOL);
    exit(1);
}

$kwota = round((float) $kwota, 2);

if ($args[0] === '--all') {
    $ile = 0;

    foreach ($allUsers() as $user) {
        Portfel::dlaUzytkownika($user['id'], $db)->dodajSrodki($kwota);
        $ile++;
    }

    echo sprintf('Doładowano %d kont(a) o %.2f monet.%s', $ile, $kwota, PHP_EOL);
} else {
    $login = trim($args[0]);
    $uzytkownik = Uzytkownik::szukaj($login, $db);

    if ($uzytkownik === null) {
        fwrite(STDERR, 'Nie ma konta o loginie "' . $login . '".' . PHP_EOL);
        exit(1);
    }

    $portfel = $uzytkownik->portfel($db);
    $przed = $portfel->saldo;

    $portfel->dodajSrodki($kwota);

    echo sprintf('%s: %.2f -> %.2f%s', $login, $przed, $portfel->saldo, PHP_EOL);
}

$printAll();
