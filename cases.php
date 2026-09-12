<?php
$pageTitle = 'Skrzynki Dropu';
$activePage = 'cases';
require_once __DIR__ . '/includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h1 style="color: var(--primary);">📦 Skrzynki z Rybami</h1>
        <p style="color: var(--text-muted); font-size: 0.9rem;">Ważone losowanie z rozkładem prawdopodobieństwa $P(i) = \frac{w_i}{\sum w}$</p>
    </div>
</div>

<!-- Okno wylosowanej ryby -->
<div class="card" id="winBox" style="display: none; text-align: center; border: 2px dashed var(--primary); background: #0c4a6e22;">
    <div style="font-size: 0.9rem; color: var(--text-muted);">🎉 Gratulacje! Wylosowano rybę:</div>
    <div id="winItemIcon" style="font-size: 4rem; margin: 10px 0;">🐟</div>
    <div id="winItemName" style="font-size: 1.5rem; font-weight: bold;">—</div>
    <div id="winItemDetails" style="font-size: 0.9rem; color: var(--text-muted); margin: 6px 0;">—</div>
    <div style="margin-top: 14px;">
        <a href="inventory.php" class="btn btn-secondary" style="font-size: 0.85rem;">Przejdź do ekwipunku</a>
    </div>
</div>

<!-- Lista skrzynek -->
<div id="casesList" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px;">
    <div style="color: var(--text-muted);">Ładowanie skrzynek...</div>
</div>

<script>
    async function zaladujSkrzynki() {
        try {
            const data = await apiFetch('api/cases.php');
            const container = document.getElementById('casesList');
            container.innerHTML = '';

            data.cases.forEach(c => {
                let itemsHtml = c.przedmioty.map(it => `
                    <div style="display: flex; justify-content: space-between; padding: 4px 0; border-bottom: 1px solid var(--card-border); font-size: 0.85rem;">
                        <span class="rarity-${it.rzadkosc}">${it.ikona} ${it.nazwa}</span>
                        <span><b>${it.szansa_procent}%</b> (${it.wartosc.toFixed(2)} m.)</span>
                    </div>
                `).join('');

                const card = document.createElement('div');
                card.className = 'card';
                card.style.display = 'flex';
                card.style.flexDirection = 'column';
                card.style.justifyContent = 'space-between';
                card.innerHTML = `
                    <div>
                        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                            <span style="font-size: 2.5rem;">${c.ikona}</span>
                            <div>
                                <h3 style="font-size: 1.2rem;">${c.nazwa}</h3>
                                <p style="font-size: 0.8rem; color: var(--text-muted);">${c.opis}</p>
                            </div>
                        </div>

                        <div style="font-weight: bold; color: var(--gold); margin: 10px 0;">
                            Cena otwarcia: ${c.koszt.toFixed(2)} monet
                        </div>

                        <div style="background: #0f172a; padding: 10px; border-radius: 8px; margin: 10px 0; max-height: 140px; overflow-y: auto;">
                            <div style="font-size: 0.75rem; font-weight: bold; color: var(--text-muted); margin-bottom: 4px;">ZAWARTOŚĆ SKRZYNKI:</div>
                            ${itemsHtml}
                        </div>
                    </div>

                    <button class="btn" style="width: 100%; margin-top: 10px;" onclick="otworzSkrzynke(${c.id})">
                        Otwórz za ${c.koszt.toFixed(2)} monet
                    </button>
                `;
                container.appendChild(card);
            });
        } catch (err) {
            document.getElementById('casesList').innerHTML = `<div style="color: var(--danger);">Błąd: ${err.message}</div>`;
        }
    }

    async function otworzSkrzynke(caseId) {
        if (!currentToken) {
            alert('Zaloguj się, aby otworzyć skrzynkę!');
            window.location.href = 'login.php';
            return;
        }

        try {
            const res = await apiFetch('api/open_case.php', 'POST', { case_id: caseId });
            const won = res.wylosowany_przedmiot;

            const winBox = document.getElementById('winBox');
            winBox.style.display = 'block';
            document.getElementById('winItemIcon').textContent = won.ikona;
            document.getElementById('winItemName').textContent = won.nazwa;
            document.getElementById('winItemName').className = `rarity-${won.rzadkosc}`;
            document.getElementById('winItemDetails').textContent = 
                `Rzadkość: ${won.rzadkosc} | Szansa: ${won.szansa_procent}% | Wartość rynkowa: ${won.wartosc.toFixed(2)} monet`;

            // Odświeżamy stan w pasku
            await aktualizujPasekNawigacji();
            winBox.scrollIntoView({ behavior: 'smooth' });

        } catch (err) {
            alert(err.message);
        }
    }

    window.addEventListener('DOMContentLoaded', () => {
        zaladujSkrzynki();
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
