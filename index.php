<?php
$pageTitle = 'Strona Główna';
$activePage = 'home';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Banner powitalny -->
<div class="card" style="text-align: center; padding: 40px 20px; background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);">
    <div style="font-size: 3.5rem; margin-bottom: 8px;">🐟 🎣 🌊</div>
    <h1 style="font-size: 2.2rem; color: var(--primary); margin-bottom: 10px;">Witaj w MOCNY DROP!</h1>
    <p style="color: var(--text-muted); max-width: 600px; margin: 0 auto 20px auto; font-size: 1.05rem;">
        Wirtualna platforma hazardowa z nagrodami rybnymi. Wybierz swój tryb gry, otwieraj skrzynie z rzadkimi rybami lub zarzucaj wędkę w łowisku!
    </p>
    <div id="heroButtons">
        <a href="cases.php" class="btn" style="font-size: 1.05rem; padding: 12px 24px;">📦 Otwórz Skrzynkę</a>
        <a href="fish.php" class="btn btn-secondary" style="font-size: 1.05rem; padding: 12px 24px; margin-left: 10px;">🎣 Idź na Łowisko</a>
    </div>
</div>

<!-- Siatka kafelków z trybami gry -->
<h2 style="margin: 30px 0 16px 0; font-size: 1.4rem;">Wybierz tryb gry</h2>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px;">
    <!-- Kafel 1: Skrzynki -->
    <a href="cases.php" class="card" style="display: block; transition: transform 0.2s; cursor: pointer; text-decoration: none;" onmouseover="this.style.transform='translateY(-4px)'" onmouseout="this.style.transform='translateY(0)'">
        <div style="font-size: 2.5rem; margin-bottom: 12px;">📦</div>
        <h3 style="color: var(--primary); margin-bottom: 6px;">Skrzynki Dropu</h3>
        <p style="color: var(--text-muted); font-size: 0.9rem;">
            Klasyczne otwieranie skrzynek z ważonym prawdopodobieństwem: Zwykłe (50%), Rzadkie (30%), Epickie (15%), Legendarne (5%).
        </p>
        <span class="btn" style="margin-top: 14px; width: 100%; font-size: 0.85rem;">Przejdź do skrzynek &rarr;</span>
    </a>

    <!-- Kafel 2: Łowisko -->
    <a href="fish.php" class="card" style="display: block; transition: transform 0.2s; cursor: pointer; text-decoration: none;" onmouseover="this.style.transform='translateY(-4px)'" onmouseout="this.style.transform='translateY(0)'">
        <div style="font-size: 2.5rem; margin-bottom: 12px;">🎣</div>
        <h3 style="color: var(--green); margin-bottom: 6px;">Łowisko Rybne</h3>
        <p style="color: var(--text-muted); font-size: 0.9rem;">
            Kup przynętę, zarzuć wędkę w toń wody i wyłów pospolite lub legendarne okazy morskie!
        </p>
        <span class="btn btn-success" style="margin-top: 14px; width: 100%; font-size: 0.85rem;">Zarzuć wędkę &rarr;</span>
    </a>

    <!-- Kafel 3: Upgrader -->
    <a href="upgrade.php" class="card" style="display: block; transition: transform 0.2s; cursor: pointer; text-decoration: none;" onmouseover="this.style.transform='translateY(-4px)'" onmouseout="this.style.transform='translateY(0)'">
        <div style="font-size: 2.5rem; margin-bottom: 12px;">⚡</div>
        <h3 style="color: var(--purple); margin-bottom: 6px;">Upgrader Ryb</h3>
        <p style="color: var(--text-muted); font-size: 0.9rem;">
            Zaryzykuj mniejszą rybę, aby wygrać znacznie cenniejszy okaz! Szansa zależy od wartości obu ryb.
        </p>
        <span class="btn" style="background: var(--purple); color: #fff; margin-top: 14px; width: 100%; font-size: 0.85rem;">Ulepszaj okazy &rarr;</span>
    </a>

    <!-- Kafel 4: Ekwipunek -->
    <a href="inventory.php" class="card" style="display: block; transition: transform 0.2s; cursor: pointer; text-decoration: none;" onmouseover="this.style.transform='translateY(-4px)'" onmouseout="this.style.transform='translateY(0)'">
        <div style="font-size: 2.5rem; margin-bottom: 12px;">🎒</div>
        <h3 style="color: var(--gold); margin-bottom: 6px;">Twój Ekwipunek</h3>
        <p style="color: var(--text-muted); font-size: 0.9rem;">
            Zarządzaj swoimi złowionymi rybami. Trzymaj je na pamiątkę lub natychmiast sprzedawaj za monety ($S_{n+1} = S_n + v_i$).
        </p>
        <span class="btn" style="background: var(--gold); color: #000; margin-top: 14px; width: 100%; font-size: 0.85rem;">Zobacz ryby &rarr;</span>
    </a>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
