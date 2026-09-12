<?php
/**
 * includes/header.php
 * Wspólny nagłówek i pasek nawigacyjny dla wszystkich podstron projektu.
 */
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'MOCNY DROP' ?> - Hazard Rybny</title>
    <style>
        :root {
            --bg: #0b1329;
            --card: #16203c;
            --card-border: #23335c;
            --primary: #38bdf8;
            --primary-hover: #0ea5e9;
            --text: #f8fafc;
            --text-muted: #94a3b8;
            --gold: #facc15;
            --green: #22c55e;
            --purple: #a855f7;
            --blue: #3b82f6;
            --danger: #ef4444;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body { background: var(--bg); color: var(--text); min-height: 100vh; display: flex; flex-direction: column; }
        a { color: inherit; text-decoration: none; }

        /* Pasek nawigacji */
        .navbar {
            background: #0f172a;
            border-bottom: 1px solid var(--card-border);
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }
        .nav-brand { font-size: 1.3rem; font-weight: 800; color: var(--primary); display: flex; align-items: center; gap: 8px; }
        .nav-links { display: flex; gap: 14px; align-items: center; }
        .nav-link {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--text-muted);
            transition: 0.2s;
        }
        .nav-link:hover, .nav-link.active { color: #fff; background: var(--card-border); }

        .nav-user { display: flex; align-items: center; gap: 12px; }
        .balance-badge {
            background: #854d0e33;
            border: 1px solid var(--gold);
            color: var(--gold);
            padding: 4px 12px;
            border-radius: 9999px;
            font-weight: bold;
            font-size: 0.9rem;
        }

        /* Główny kontener */
        .main-container { max-width: 1100px; margin: 24px auto; padding: 0 16px; flex: 1; width: 100%; }

        /* Karty i elementy UI */
        .card {
            background: var(--card);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.2);
        }
        .btn {
            background: var(--primary);
            color: #0b1329;
            border: none;
            padding: 9px 16px;
            border-radius: 6px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
            display: inline-block;
            text-align: center;
        }
        .btn:hover { background: var(--primary-hover); color: #fff; }
        .btn-secondary { background: var(--card-border); color: #fff; }
        .btn-secondary:hover { background: #334570; }
        .btn-danger { background: #991b1b; color: #fff; }
        .btn-danger:hover { background: var(--danger); }
        .btn-success { background: #15803d; color: #fff; }
        .btn-success:hover { background: var(--green); }

        /* Style rzadkości ryb */
        .rarity-Zwykła { color: #94a3b8; font-weight: 600; }
        .rarity-Rzadka { color: var(--blue); font-weight: 700; }
        .rarity-Epicka { color: var(--purple); font-weight: bold; }
        .rarity-Legendarna { color: var(--gold); font-weight: 900; text-shadow: 0 0 10px rgba(250, 204, 21, 0.4); }

        footer { text-align: center; padding: 20px; color: var(--text-muted); font-size: 0.85rem; border-top: 1px solid var(--card-border); margin-top: auto; }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="index.php" class="nav-brand">MOCNY DROP 🐟</a>
    
    <div class="nav-links">
        <a href="index.php" class="nav-link <?= ($activePage ?? '') === 'home' ? 'active' : '' ?>">🏠 Główna</a>
        <a href="cases.php" class="nav-link <?= ($activePage ?? '') === 'cases' ? 'active' : '' ?>">📦 Skrzynki</a>
        <a href="fish.php" class="nav-link <?= ($activePage ?? '') === 'fish' ? 'active' : '' ?>">🎣 Łowisko</a>
        <a href="upgrade.php" class="nav-link <?= ($activePage ?? '') === 'upgrade' ? 'active' : '' ?>">⚡ Upgrader</a>
        <a href="inventory.php" class="nav-link <?= ($activePage ?? '') === 'inventory' ? 'active' : '' ?>">🎒 Ekwipunek</a>
    </div>

    <div class="nav-user" id="navUserSection">
        <!-- Generowane dynamicznie przez JS w zależności od logowania -->
        <a href="login.php" class="btn btn-secondary" style="font-size: 0.85rem;">Zaloguj się</a>
    </div>
</nav>

<div class="main-container">
