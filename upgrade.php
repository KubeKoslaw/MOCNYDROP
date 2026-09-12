<?php
$pageTitle = 'Upgrader Ryb';
$activePage = 'upgrade';
require_once __DIR__ . '/includes/header.php';
?>

<div style="max-width: 750px; margin: 0 auto;">
    <div class="card" style="text-align: center;">
        <h1 style="color: var(--purple); margin-bottom: 6px;">⚡ Upgrader Okazów</h1>
        <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 24px;">
            Poświęć mniejszą rybę ze swojego ekwipunku, aby spróbować wygrać znacznie cenniejszy okaz!
        </p>

        <!-- Wybór wkładu i celu -->
        <div style="display: grid; grid-template-columns: 1fr auto 1fr; gap: 16px; align-items: center; margin-bottom: 24px;">
            <!-- Ryba wkładu -->
            <div style="background: #0f172a; padding: 16px; border-radius: 10px; border: 1px solid var(--card-border);">
                <div style="font-size: 0.8rem; font-weight: bold; color: var(--text-muted); margin-bottom: 8px;">TWÓJ WKŁAD (EKWIPUNEK):</div>
                <select id="selectInputFish" onchange="przeliczSzanse()" style="width: 100%; padding: 8px; background: var(--card); border: 1px solid var(--card-border); color: #fff; border-radius: 6px;">
                    <option value="">Wybierz rybę...</option>
                </select>
                <div id="inputValueDisplay" style="margin-top: 8px; font-weight: bold; color: var(--gold);">Wartość: —</div>
            </div>

            <div style="font-size: 2rem; color: var(--purple);">➔</div>

            <!-- Ryba celu -->
            <div style="background: #0f172a; padding: 16px; border-radius: 10px; border: 1px solid var(--card-border);">
                <div style="font-size: 0.8rem; font-weight: bold; color: var(--text-muted); margin-bottom: 8px;">CEL DO ZDOBYCIA:</div>
                <select id="selectTargetFish" onchange="przeliczSzanse()" style="width: 100%; padding: 8px; background: var(--card); border: 1px solid var(--card-border); color: #fff; border-radius: 6px;">
                    <option value="">Wybierz cel...</option>
                </select>
                <div id="targetValueDisplay" style="margin-top: 8px; font-weight: bold; color: var(--gold);">Wartość: —</div>
            </div>
        </div>

        <!-- Licznik szansy -->
        <div style="background: #1e1b4b; border: 1px solid var(--purple); padding: 16px; border-radius: 10px; margin-bottom: 20px;">
            <div style="font-size: 0.9rem; color: #c084fc;">Kalkulacja szansy wygranej:</div>
            <div id="chanceDisplay" style="font-size: 2.2rem; font-weight: 900; color: #fff; margin: 6px 0;">0.00%</div>
            <div style="font-size: 0.75rem; color: var(--text-muted);">Wzór: $\frac{W_{wkład}}{W_{cel}} \cdot (1 - marża)$</div>
        </div>

        <!-- Wynik ulepszenia -->
        <div id="upgradeResultBox" style="display: none; padding: 16px; border-radius: 10px; margin-bottom: 20px;"></div>

        <button id="upgradeBtn" class="btn" style="background: var(--purple); color: #fff; font-size: 1.1rem; padding: 12px 32px;" onclick="wykonajUpgrade()">
            Rozpocznij ulepszanie ⚡
        </button>
    </div>
</div>

<script>
    let userInventory = [];
    let catalogItems = [];

    async function zaladujDane() {
        if (!currentToken) {
            document.getElementById('selectInputFish').innerHTML = '<option>Zaloguj się najpierw!</option>';
            return;
        }

        try {
            // 1. Pobieramy ekwipunek
            const invData = await apiFetch('api/inventory.php');
            userInventory = invData.inventory;
            const inputSelect = document.getElementById('selectInputFish');
            inputSelect.innerHTML = '<option value="">Wybierz rybę z ekwipunku...</option>';
            userInventory.forEach(it => {
                inputSelect.innerHTML += `<option value="${it.inventory_id}" data-val="${it.wartosc}">${it.ikona} ${it.nazwa} (${it.wartosc.toFixed(2)} m.)</option>`;
            });

            // 2. Pobieramy skrzynki i zbieramy unikalne ryby do katalogu
            const casesData = await apiFetch('api/cases.php');
            const itemsMap = new Map();
            casesData.cases.forEach(c => {
                c.przedmioty.forEach(p => itemsMap.set(p.id, p));
            });
            catalogItems = Array.from(itemsMap.values()).sort((a, b) => a.wartosc - b.wartosc);

            const targetSelect = document.getElementById('selectTargetFish');
            targetSelect.innerHTML = '<option value="">Wybierz cel...</option>';
            catalogItems.forEach(p => {
                targetSelect.innerHTML += `<option value="${p.id}" data-val="${p.wartosc}">${p.ikona} ${p.nazwa} (${p.wartosc.toFixed(2)} m.)</option>`;
            });

        } catch (err) {
            console.error(err);
        }
    }

    function przeliczSzanse() {
        const selIn = document.getElementById('selectInputFish');
        const selOut = document.getElementById('selectTargetFish');
        const optIn = selIn.options[selIn.selectedIndex];
        const optOut = selOut.options[selOut.selectedIndex];

        const valIn = optIn && optIn.dataset.val ? parseFloat(optIn.dataset.val) : 0;
        const valOut = optOut && optOut.dataset.val ? parseFloat(optOut.dataset.val) : 0;

        document.getElementById('inputValueDisplay').textContent = valIn > 0 ? `Wartość: ${valIn.toFixed(2)} monet` : 'Wartość: —';
        document.getElementById('targetValueDisplay').textContent = valOut > 0 ? `Wartość: ${valOut.toFixed(2)} monet` : 'Wartość: —';

        if (valIn <= 0 || valOut <= 0 || valOut <= valIn) {
            document.getElementById('chanceDisplay').textContent = '0.00%';
            return;
        }

        let szansa = ((valIn / valOut) * 0.95) * 100;
        szansa = Math.min(95, Math.max(1, szansa));
        document.getElementById('chanceDisplay').textContent = szansa.toFixed(2) + '%';
    }

    async function wykonajUpgrade() {
        const inputId = document.getElementById('selectInputFish').value;
        const targetId = document.getElementById('selectTargetFish').value;

        if (!inputId || !targetId) {
            alert('Wybierz rybę wkładu oraz cel ulepszenia!');
            return;
        }

        const btn = document.getElementById('upgradeBtn');
        const resBox = document.getElementById('upgradeResultBox');
        btn.disabled = true;
        btn.textContent = 'Trwa ulepszanie... ⚡';
        resBox.style.display = 'none';

        try {
            const res = await apiFetch('api/upgrade.php', 'POST', {
                input_inventory_id: parseInt(inputId),
                target_item_id: parseInt(targetId)
            });

            resBox.style.display = 'block';
            if (res.wygrana) {
                resBox.style.background = '#064e3b33';
                resBox.style.border = '1px solid var(--green)';
                resBox.innerHTML = `
                    <div style="font-size: 2rem;">🎉</div>
                    <div style="font-size: 1.3rem; font-weight: bold; color: var(--green);">SUKCES!</div>
                    <p style="margin-top: 6px;">Ulepszono do: <b>${res.nagroda.ikona} ${res.nagroda.nazwa}</b>!</p>
                `;
            } else {
                resBox.style.background = '#450a0a33';
                resBox.style.border = '1px solid var(--danger)';
                resBox.innerHTML = `
                    <div style="font-size: 2rem;">💥</div>
                    <div style="font-size: 1.3rem; font-weight: bold; color: var(--danger);">PORAŻKA!</div>
                    <p style="margin-top: 6px;">Rzut koła fortuny (${res.wylosowany_roll}%) nie trafił w szansę (${res.szansa_procent}%). Ryba przepadła.</p>
                `;
            }

            await zaladujDane();
            await aktualizujPasekNawigacji();

        } catch (err) {
            alert(err.message);
        } finally {
            btn.disabled = false;
            btn.textContent = 'Rozpocznij ulepszanie ⚡';
        }
    }

    window.addEventListener('DOMContentLoaded', () => {
        zaladujDane();
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
