<?php
$pageTitle = 'Logowanie i Rejestracja';
$activePage = 'login';
require_once __DIR__ . '/includes/header.php';
?>

<div style="max-width: 500px; margin: 40px auto;">
    <!-- Karta Logowania -->
    <div class="card" id="loginCard">
        <h2 style="margin-bottom: 16px; color: var(--primary);">🔐 Logowanie gracza</h2>
        <form id="loginForm" onsubmit="handleLogin(event)">
            <div style="margin-bottom: 14px;">
                <label style="display: block; margin-bottom: 6px; font-size: 0.9rem; color: var(--text-muted);">Login lub E-mail:</label>
                <input type="text" id="loginUsername" required value="gracz" style="width: 100%; padding: 10px; background: #0f172a; border: 1px solid var(--card-border); color: #fff; border-radius: 6px;">
            </div>
            <div style="margin-bottom: 18px;">
                <label style="display: block; margin-bottom: 6px; font-size: 0.9rem; color: var(--text-muted);">Hasło:</label>
                <input type="password" id="loginPassword" required value="haslo123" style="width: 100%; padding: 10px; background: #0f172a; border: 1px solid var(--card-border); color: #fff; border-radius: 6px;">
            </div>
            <button type="submit" class="btn" style="width: 100%;">Zaloguj się</button>
        </form>

        <div style="margin-top: 16px; text-align: center; font-size: 0.85rem; color: var(--text-muted);">
            Nie masz konta? <a href="#" onclick="showRegister()" style="color: var(--primary); font-weight: bold;">Zarejestruj się (Start: 50 monet)</a>
        </div>
        <div style="margin-top: 12px; font-size: 0.8rem; color: var(--text-muted); background: #0f172a; padding: 8px; border-radius: 6px;">
            Domyślne konto testowe: <b>gracz</b> / hasło: <b>haslo123</b>
        </div>
    </div>

    <!-- Karta Rejestracji -->
    <div class="card" id="registerCard" style="display: none;">
        <h2 style="margin-bottom: 16px; color: var(--green);">✨ Nowe konto gracza</h2>
        <form id="registerForm" onsubmit="handleRegister(event)">
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 6px; font-size: 0.9rem; color: var(--text-muted);">Login (min. 3 znaki):</label>
                <input type="text" id="regUsername" required minlength="3" placeholder="Wpisz swój login" style="width: 100%; padding: 10px; background: #0f172a; border: 1px solid var(--card-border); color: #fff; border-radius: 6px;">
            </div>
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 6px; font-size: 0.9rem; color: var(--text-muted);">Adres E-mail:</label>
                <input type="email" id="regEmail" required placeholder="twoj@email.pl" style="width: 100%; padding: 10px; background: #0f172a; border: 1px solid var(--card-border); color: #fff; border-radius: 6px;">
            </div>
            <div style="margin-bottom: 18px;">
                <label style="display: block; margin-bottom: 6px; font-size: 0.9rem; color: var(--text-muted);">Hasło (min. 8 znaków):</label>
                <input type="password" id="regPassword" required minlength="8" placeholder="Wpisz bezpieczne hasło" style="width: 100%; padding: 10px; background: #0f172a; border: 1px solid var(--card-border); color: #fff; border-radius: 6px;">
            </div>
            <button type="submit" class="btn btn-success" style="width: 100%;">Utwórz darmowe konto</button>
        </form>

        <div style="margin-top: 16px; text-align: center; font-size: 0.85rem; color: var(--text-muted);">
            Masz już konto? <a href="#" onclick="showLogin()" style="color: var(--primary); font-weight: bold;">Wróć do logowania</a>
        </div>
    </div>
</div>

<script>
    function showRegister() {
        document.getElementById('loginCard').style.display = 'none';
        document.getElementById('registerCard').style.display = 'block';
    }

    function showLogin() {
        document.getElementById('registerCard').style.display = 'none';
        document.getElementById('loginCard').style.display = 'block';
    }

    async function handleLogin(e) {
        e.preventDefault();
        const login = document.getElementById('loginUsername').value.trim();
        const password = document.getElementById('loginPassword').value.trim();

        try {
            const data = await apiFetch('api/login.php', 'POST', { login, password });
            localStorage.setItem('mocnydrop_token', data.token);
            alert('Zalogowano pomyślnie! Przekierowuję na stronę główną...');
            window.location.href = 'index.php';
        } catch (err) {
            alert('Błąd logowania: ' + err.message);
        }
    }

    async function handleRegister(e) {
        e.preventDefault();
        const login = document.getElementById('regUsername').value.trim();
        const email = document.getElementById('regEmail').value.trim();
        const password = document.getElementById('regPassword').value.trim();

        try {
            await apiFetch('api/register.php', 'POST', { login, email, password });
            alert('Rejestracja udana! Otrzymujesz 50 monet powitalnych. Teraz nastąpi logowanie...');
            const logData = await apiFetch('api/login.php', 'POST', { login, password });
            localStorage.setItem('mocnydrop_token', logData.token);
            window.location.href = 'index.php';
        } catch (err) {
            alert('Błąd rejestracji: ' + err.message);
        }
    }

    // Jeśli gracz jest już zalogowany, przekieruj na index.php
    if (localStorage.getItem('mocnydrop_token')) {
        apiFetch('api/me.php').then(() => {
            window.location.href = 'index.php';
        }).catch(() => {});
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
