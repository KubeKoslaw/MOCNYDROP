<?php
$pageTitle = 'Łowisko Rybne';
$activePage = 'fish';
require_once __DIR__ . '/includes/header.php';
?>

<div style="max-width: 700px; margin: 0 auto; text-align: center;">
    <div class="card">
        <h1 style="color: var(--green); margin-bottom: 8px;">🎣 Łowisko Rybne</h1>
        <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 20px;">
            Kup przynętę za <b>5.00 monet</b>, zarzuć wędkę w toń jeziora i sprawdź, jaka ryba złapie za haczyk!
        </p>

        <!-- GIF Ryby z repozytorium -->
        <div style="margin: 20px auto; max-width: 320px; border-radius: 12px; overflow: hidden; border: 2px solid var(--card-border); background: #000;">
            <img src="assets/input2.gif" alt="Łowisko Ryb" style="width: 100%; height: auto; display: block;">
        </div>

        <div id="catchBox" style="display: none; padding: 16px; margin: 16px 0; border-radius: 10px; background: #064e3b33; border: 1px solid var(--green);">
            <div style="font-size: 0.9rem; color: var(--text-muted);">🎣 Coś bierze! Wyciągasz z wody:</div>
            <div id="catchIcon" style="font-size: 3.5rem; margin: 8px 0;">🐟</div>
            <div id="catchName" style="font-size: 1.4rem; font-weight: bold;">—</div>
            <div id="catchDetails" style="font-size: 0.85rem; color: var(--text-muted); margin-top: 4px;">—</div>
        </div>

        <button id="fishBtn" class="btn btn-success" style="font-size: 1.1rem; padding: 12px 32px;" onclick="rzucWedke()">
            Zarzuć wędkę (5.00 monet)
        </button>

        <div style="margin-top: 20px; font-size: 0.8rem; color: var(--text-muted);">
            Szansa na połów: Zwykła (55%), Rzadka (30%), Epicka (12%), Legendarna (3%)
        </div>
    </div>
</div>

<script>
    async function rzucWedke() {
        if (!currentToken) {
            alert('Zaloguj się, aby łowić ryby!');
            window.location.href = 'login.php';
            return;
        }

        const btn = document.getElementById('fishBtn');
        const catchBox = document.getElementById('catchBox');
        btn.disabled = true;
        btn.textContent = 'Czekasz na branie... 🌊';
        catchBox.style.display = 'none';

        try {
            // Krótkie opóźnienie dla klimatu łowienia
            await new Promise(r => setTimeout(r, 600));

            const res = await apiFetch('api/fish.php', 'POST');
            const fish = res.wylowiona_ryba;

            catchBox.style.display = 'block';
            document.getElementById('catchIcon').textContent = fish.ikona;
            document.getElementById('catchName').textContent = fish.nazwa;
            document.getElementById('catchName').className = `rarity-${fish.rzadkosc}`;
            document.getElementById('catchDetails').textContent = 
                `Rzadkość: ${fish.rzadkosc} | Wartość rynkowa: ${fish.wartosc.toFixed(2)} monet | Trafienie z szansą ${fish.szansa_procent}%`;

            await aktualizujPasekNawigacji();

        } catch (err) {
            alert(err.message);
        } finally {
            btn.disabled = false;
            btn.textContent = 'Zarzuć wędkę (5.00 monet)';
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
