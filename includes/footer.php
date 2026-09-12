</div> <!-- Koniec .main-container -->

<footer>
    MOCNY DROP — Projekt szkolny (Backend: PHP 8 + SQLite). Gałąź: <code>PHP-+-sqllite-KubeK</code>
</footer>

<script>
    // Globalny helper dla wszystkich podstron
    let currentToken = localStorage.getItem('mocnydrop_token') || null;

    async function apiFetch(endpoint, method = 'GET', body = null) {
        const headers = { 'Content-Type': 'application/json' };
        if (currentToken) {
            headers['Authorization'] = 'Bearer ' + currentToken;
        }

        const options = { method, headers };
        if (body) {
            options.body = JSON.stringify(body);
        }

        const res = await fetch(endpoint, options);
        const data = await res.json();

        if (!res.ok) {
            throw new Error(data.error || 'Wystąpił nieoczekiwany błąd API.');
        }
        return data;
    }

    // Aktualizacja paska użytkownika w nagłówku
    async function aktualizujPasekNawigacji() {
        const userSec = document.getElementById('navUserSection');
        if (!userSec) return;

        if (!currentToken) {
            userSec.innerHTML = `<a href="login.php" class="btn btn-secondary" style="font-size: 0.85rem;">Zaloguj się</a>`;
            return;
        }

        try {
            const data = await apiFetch('api/me.php');
            const u = data.user;
            userSec.innerHTML = `
                <span class="balance-badge">💰 <span id="globalBalance">${Number(u.saldo).toFixed(2)}</span> monet</span>
                <span style="font-size: 0.9rem; font-weight: 600;">${u.login}</span>
                <button class="btn btn-secondary" style="padding: 4px 10px; font-size: 0.8rem;" onclick="wyloguj()">Wyloguj</button>
            `;
        } catch (err) {
            // Token niepoprawny lub wygasł
            localStorage.removeItem('mocnydrop_token');
            currentToken = null;
            userSec.innerHTML = `<a href="login.php" class="btn btn-secondary" style="font-size: 0.85rem;">Zaloguj się</a>`;
        }
    }

    function wyloguj() {
        localStorage.removeItem('mocnydrop_token');
        currentToken = null;
        window.location.href = 'login.php';
    }

    window.addEventListener('DOMContentLoaded', () => {
        aktualizujPasekNawigacji();
    });
</script>
</body>
</html>
