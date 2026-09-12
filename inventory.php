<?php
$pageTitle = 'Mój Ekwipunek';
$activePage = 'inventory';
require_once __DIR__ . '/includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div>
        <h1 style="color: var(--gold);">🎒 Twój Ekwipunek Ryb</h1>
        <p style="color: var(--text-muted); font-size: 0.9rem;">Przeglądaj złowione ryby lub sprzedaj je, aby powiększyć swoje saldo ($S_{n+1} = S_n + v_i$)</p>
    </div>
    <div id="invSummary" style="font-size: 0.95rem; background: #0f172a; padding: 8px 16px; border-radius: 8px; border: 1px solid var(--card-border);">
        Łączna wartość ryb: <b id="totalInvValue" style="color: var(--gold);">0.00</b> monet
    </div>
</div>

<div id="invGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px;">
    <div style="color: var(--text-muted);">Ładowanie ekwipunku...</div>
</div>

<script>
    async function zaladujEkwipunek() {
        if (!currentToken) {
            document.getElementById('invGrid').innerHTML = `
                <div class="card" style="grid-column: 1/-1; text-align: center;">
                    <p style="margin-bottom: 12px; color: var(--text-muted);">Musisz być zalogowany, aby zobaczyć swój ekwipunek.</p>
                    <a href="login.php" class="btn">Zaloguj się</a>
                </div>
            `;
            return;
        }

        try {
            const data = await apiFetch('api/inventory.php');
            const container = document.getElementById('invGrid');
            container.innerHTML = '';

            if (data.inventory.length === 0) {
                container.innerHTML = `
                    <div class="card" style="grid-column: 1/-1; text-align: center; padding: 40px 20px;">
                        <div style="font-size: 3rem; margin-bottom: 10px;">🪣</div>
                        <h3 style="margin-bottom: 8px;">Twój kosz na ryby jest pusty!</h3>
                        <p style="color: var(--text-muted); margin-bottom: 16px;">Nie posiadasz jeszcze żadnych okazów.</p>
                        <a href="cases.php" class="btn">Otwórz pierwszą skrzynkę</a>
                    </div>
                `;
                document.getElementById('totalInvValue').textContent = '0.00';
                return;
            }

            let totalValue = 0;
            data.inventory.forEach(it => {
                totalValue += Number(it.wartosc);

                const card = document.createElement('div');
                card.className = 'card';
                card.style.textAlign = 'center';
                card.style.padding = '16px';
                card.innerHTML = `
                    <div style="font-size: 2.5rem; margin-bottom: 8px;">${it.ikona}</div>
                    <div class="rarity-${it.rzadkosc}" style="font-size: 1.05rem; margin-bottom: 4px;">${it.nazwa}</div>
                    <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 8px;">${it.rzadkosc}</div>
                    <div style="font-weight: bold; color: var(--gold); margin-bottom: 12px;">${it.wartosc.toFixed(2)} monet</div>
                    <button class="btn btn-success" style="width: 100%; font-size: 0.85rem;" onclick="sprzedaj(${it.inventory_id})">
                        Sprzedaj (+${it.wartosc.toFixed(2)} m.)
                    </button>
                `;
                container.appendChild(card);
            });

            document.getElementById('totalInvValue').textContent = totalValue.toFixed(2);

        } catch (err) {
            document.getElementById('invGrid').innerHTML = `<div style="color: var(--danger);">Błąd: ${err.message}</div>`;
        }
    }

    async function sprzedaj(inventoryId) {
        try {
            const data = await apiFetch('api/sell_item.php', 'POST', { inventory_id: inventoryId });
            await aktualizujPasekNawigacji();
            await zaladujEkwipunek();
            alert(data.message);
        } catch (err) {
            alert(err.message);
        }
    }

    window.addEventListener('DOMContentLoaded', () => {
        zaladujEkwipunek();
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
