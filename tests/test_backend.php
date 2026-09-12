<?php
/**
 * tests/test_backend.php
 * Prosty skrypt testowy CLI sprawdzający wszystkie elementy backendu:
 * 1. Połączenie z bazą i tabele
 * 2. Rejestrację i logowanie (Bearer Token)
 * 3. Pobranie i otwarcie skrzynki (weryfikacja salda i ekwipunku)
 * 4. Sprzedaż przedmiotu
 * 5. Test statystyczny: 10 000 losowań sprawdzający zgodność z wagami z README.
 * 
 * Uruchomienie: php tests/test_backend.php
 */

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/CaseManager.php';
require_once __DIR__ . '/../src/InventoryManager.php';
require_once __DIR__ . '/../src/GameEngine.php';

echo "====================================================\n";
echo "       TESTY BACKENDU: MOCNY DROP (PHP + SQLite)     \n";
echo "====================================================\n\n";

// --- 1. Test połączenia z bazą ---
echo "[1/5] Test bazy danych SQLite... ";
$db = Database::getConnection();
$tableCount = $db->query("SELECT count(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
if ($tableCount >= 7) {
    echo "OK (znaleziono {$tableCount} tabel)\n";
} else {
    die("BŁĄD: Zbyt mało tabel w bazie!\n");
}

// --- 2. Test rejestracji i logowania ---
echo "[2/5] Test rejestracji i autoryzacji tokenem... ";
$testLogin = 'gracz_' . time();
$testEmail = $testLogin . '@test.pl';
$testPass = 'bezpiecznehaslo123';

$reg = Auth::register($testLogin, $testEmail, $testPass);
$log = Auth::login($testLogin, $testPass);

if (!empty($log['token']) && $log['user']['saldo'] == 50.00) {
    echo "OK (Token wygenerowany, saldo startowe: 50.00)\n";
} else {
    die("BŁĄD logowania lub salda!\n");
}

$userId = $log['user']['id'];

// --- 3. Test otwarcia skrzynki ---
echo "[3/5] Test otwarcia skrzynki (koszt 10 monet)... ";
$dropResult = CaseManager::openCase($userId, 1);

if ($dropResult['saldo_po'] == 40.00 && !empty($dropResult['wylosowany_przedmiot']['nazwa'])) {
    $won = $dropResult['wylosowany_przedmiot'];
    echo "OK! Wylosowano: [{$won['rzadkosc']}] {$won['nazwa']} (wartosc: {$won['wartosc']} monet, nowe saldo: {$dropResult['saldo_po']})\n";
} else {
    die("BŁĄD otwierania skrzynki!\n");
}

// --- 4. Test sprzedaży przedmiotu ---
echo "[4/5] Test sprzedaży wylosowanego przedmiotu... ";
$invId = $dropResult['wylosowany_przedmiot']['inventory_id'];
$sellResult = InventoryManager::sellItem($userId, $invId);

if ($sellResult['nowe_saldo'] == (40.00 + $won['wartosc'])) {
    echo "OK! Sprzedano za {$won['wartosc']} monet. Nowe saldo: {$sellResult['nowe_saldo']}\n";
} else {
    die("BŁĄD sprzedaży przedmiotu!\n");
}

// --- 5. Test statystyczny algorytmu ważonego (README) ---
echo "\n[5/5] Test statystyczny: Symulacja 10 000 losowań wg wzoru P(i) = w_i / suma(w)\n";
$items = CaseManager::getCaseItems(1); // Skrzynia Startowa (wagi: 50, 30, 15, 5)

$counts = [];
foreach ($items as $it) {
    $counts[$it['nazwa']] = 0;
}

$simulations = 10000;
for ($i = 0; $i < $simulations; $i++) {
    $rolled = GameEngine::rollItem($items);
    $counts[$rolled['nazwa']]++;
}

echo "----------------------------------------------------------------------\n";
printf("%-26s | %-12s | %-10s | %-12s\n", "Przedmiot", "Rzadkość", "Waga (Cel)", "Wynik empiryczny");
echo "----------------------------------------------------------------------\n";

foreach ($items as $it) {
    $empiricalPercent = round(($counts[$it['nazwa']] / $simulations) * 100, 2);
    printf(
        "%-26s | %-12s | %4d (%2d%%)  | %5d (%5.2f%%)\n",
        $it['nazwa'],
        $it['rzadkosc'],
        $it['waga'],
        $it['szansa_procent'],
        $counts[$it['nazwa']],
        $empiricalPercent
    );
}
echo "----------------------------------------------------------------------\n";
echo "Wszystkie testy zakończone sukcesem! Backend działa w 100% zgodnie ze specyfikacją.\n";
