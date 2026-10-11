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
 * Po co: w grze nie ma endpointu doładowania (monety zdobywa się sprzedażą ryb),
 * więc po wyczerpaniu salda startowego testowanie pętli "zarzut → sprzedaż"
 * wymagałoby kasowania bazy. Ten skrypt pozwala ją odblokować bez ruszania API.
 *
 * Zabezpieczenie: działa wyłącznie z CLI (jak database/migrate.php), więc nie da
 * się go wywołać przez HTTP i nie jest dziurą w ekonomii gry.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

use MocnyDrop\Database;

require_once dirname(__DIR__) . '/backend/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ten skrypt można uruchamiać tylko z linii komend.');
}

$args = array_slice($argv ?? [], 1);
$db = Database::connection();

/** @return list<array{id: int, login: string, saldo: float}> */
$allUsers = static function () use ($db): array {
    $rows = $db->query('SELECT id, login, saldo FROM uzytkownicy ORDER BY id ASC')->fetchAll();

    return array_map(static fn (array $r): array => [
        'id' => (int) $r['id'],
        'login' => (string) $r['login'],
        'saldo' => (float) $r['saldo'],
    ], $rows);
};

if ($args === [] || in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo "Użycie:\n"
        . "  php database/topup.php <login> <kwota>\n"
        . "  php database/topup.php --all <kwota>\n"
        . "  php database/topup.php --list\n";
    exit(0);
}

if ($args[0] === '--list') {
    $users = $allUsers();

    if ($users === []) {
        echo "Brak kont. Zarejestruj gracza: POST /api/register.php\n";
        exit(0);
    }

    foreach ($users as $user) {
        echo sprintf("  #%-4d %-20s %10.2f\n", $user['id'], $user['login'], $user['saldo']);
    }

    exit(0);
}

$kwota = isset($args[1]) ? str_replace(',', '.', trim($args[1])) : '';

if (!is_numeric($kwota) || (float) $kwota <= 0) {
    fwrite(STDERR, 'Kwota musi być liczbą dodatnią.' . PHP_EOL);
    exit(1);
}

$kwota = round((float) $kwota, 2);

if ($args[0] === '--all') {
    $stmt = $db->prepare('UPDATE uzytkownicy SET saldo = saldo + ?');
    $stmt->execute([$kwota]);

    echo sprintf('Doładowano wszystkie konta o %.2f monet.%s', $kwota, PHP_EOL);
} else {
    $login = trim($args[0]);

    $stmt = $db->prepare('SELECT id, saldo FROM uzytkownicy WHERE login = ?');
    $stmt->execute([$login]);
    $user = $stmt->fetch();

    if ($user === false) {
        fwrite(STDERR, 'Nie ma konta o loginie "' . $login . '".' . PHP_EOL);
        exit(1);
    }

    $stmt = $db->prepare('UPDATE uzytkownicy SET saldo = saldo + ? WHERE id = ?');
    $stmt->execute([$kwota, (int) $user['id']]);

    echo sprintf(
        '%s: %.2f -> %.2f%s',
        $login,
        (float) $user['saldo'],
        (float) $user['saldo'] + $kwota,
        PHP_EOL
    );
}

foreach ($allUsers() as $user) {
    echo sprintf("  #%-4d %-20s %10.2f\n", $user['id'], $user['login'], $user['saldo']);
}
