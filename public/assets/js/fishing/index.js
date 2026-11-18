import './fishing-scene.js';
import { ACCESS_KEY, REFRESH_KEY, EXPIRES_KEY, REFRESH_MARGIN_MS } from './config.js';
import { tokenRequest } from './api.js';
import {
  storageSet, storageRemove, getAccessToken, getRefreshToken, getAccessExpiresAt,
  SoundFx, showToast, formatMoney, castsLabel, esc, iconOf
} from './contract.js';

export { FishingScene, fishingAndCasesMixin } from './fishing-scene.js';
export { createApi, ApiError, ApiClient, TOKEN_KEY, readToken, tokenRequest } from './api.js';
export * from './config.js';
export * from './contract.js';

export const sessionAndProfileMixin = {
  readUrlParams() {
    const params = new URLSearchParams(window.location.search);
    return { code: params.get('code'), loginError: params.get('login_error') };
  },

  clearUrlParams() {
    const url = new URL(window.location.href);
    url.searchParams.delete('code');
    url.searchParams.delete('login_error');
    if (!url.searchParams.toString()) url.search = '';
    window.history.replaceState({}, document.title, url.pathname + url.search + url.hash);
  },

  loginErrorMessage(raw) {
    const known = {
      invalid_credentials: 'Nieprawidłowy login lub hasło.',
      account_blocked: 'Konto jest zablokowane.',
      unauthorized: 'Nieprawidłowy login lub hasło.',
      access_denied: 'Autoryzacja została odrzucona.',
      invalid_grant: 'Kod autoryzacyjny jest nieprawidłowy lub wygasł.',
      invalid_request: 'Nieprawidłowe żądanie autoryzacji.',
      server_error: 'Błąd serwera — spróbuj ponownie.',
    };
    const key = String(raw ?? '').trim();
    if (!key) return 'Logowanie nie udało się.';
    return known[key] || `Logowanie nie udało się (${key}).`;
  },

  async beginOAuth(login, password) {
    const data = await this.api.fetch('login.php', {
      method: 'POST',
      body: { login, password },
      auth: false,
    });

    if (!data.code) {
      const err = new Error('Serwer nie zwrócił kodu autoryzacyjnego.');
      err.kod = 'invalid_grant';
      throw err;
    }

    return this.exchangeCode(data.code);
  },

  async exchangeCode(code) {
    const data = await tokenRequest({ grant_type: 'authorization_code', code });

    if (!data.access_token) {
      const err = new Error('Serwer nie zwrócił tokenu dostępu.');
      err.kod = 'invalid_grant';
      throw err;
    }

    this.storeTokens(data);

    if (data.user) {
      this.user = data.user;
      this.saldo = data.user.saldo ?? 0;
    }

    this.renderSessionState();
    return data;
  },

  async refreshAccessToken() {
    const refreshToken = getRefreshToken();
    if (!refreshToken) return 'expired';
    if (this.refreshPromise) return this.refreshPromise;

    this.refreshPromise = (async () => {
      try {
        const data = await tokenRequest({ grant_type: 'refresh_token', refresh_token: refreshToken });
        this.storeTokens(data);
        if (data.user) {
          this.user = data.user;
          this.saldo = data.user.saldo ?? this.saldo;
        }
        this.renderSessionState();
        return 'ok';
      } catch (err) {
        if (err.kod === 'network_error' || err.kod === 'bad_response') return 'offline';
        return 'expired';
      }
    })();

    try {
      return await this.refreshPromise;
    } finally {
      this.refreshPromise = null;
    }
  },

  async ensureFreshToken() {
    if (!getAccessToken() && !getRefreshToken()) return false;

    const expiresAt = getAccessExpiresAt();
    if (getAccessToken() && (!expiresAt || expiresAt - Date.now() > REFRESH_MARGIN_MS)) {
      return true;
    }

    if (getAccessToken() && !getRefreshToken()) return true;

    const result = await this.refreshAccessToken();
    if (result === 'ok') return true;
    if (result === 'expired') this.handleSessionExpired();
    return false;
  },

  storeTokens(data) {
    storageSet(ACCESS_KEY, data.access_token);
    if (data.refresh_token) storageSet(REFRESH_KEY, data.refresh_token);
    const seconds = Number(data.expires_in) > 0 ? Number(data.expires_in) : 3600;
    storageSet(EXPIRES_KEY, String(Date.now() + seconds * 1000));
  },

  clearTokens() {
    storageRemove(ACCESS_KEY);
    storageRemove(REFRESH_KEY);
    storageRemove(EXPIRES_KEY);
  },

  handleSessionExpired() {
    if (!this.user && !getAccessToken() && !getRefreshToken()) return;

    this.clearTokens();
    this.user = null;
    this.saldo = 0;
    this.inventory = [];
    this.freeCaseReadyAt = null;
    clearInterval(this.freeCaseTimer);
    this.freeCaseTimer = null;

    this.renderSessionState();
    this.renderRods();
    this.renderHistoryTable([]);
    this.closeModal('profile-modal');
    showToast('Sesja wygasła — zaloguj się ponownie.', 'error');
  },

  async completeOAuthRedirect(code) {
    try {
      await this.exchangeCode(code);
      await this.loadMe();
      showToast(`Witaj, ${this.user?.login || 'graczu'}!`, 'success');
      SoundFx.coin();
    } catch (err) {
      const message = this.loginErrorMessage(err.kod || err.message);
      this.setAuthError(message);
      showToast(message, 'error');
      this.switchAuthTab('login');
      this.openModal('auth-modal');
    }
  },

  async loadMe() {
    try {
      const data = await this.api.fetch('me.php');
      this.user = data.user;
      this.saldo = data.saldo ?? data.user?.saldo ?? 0;
      this.renderSessionState();
      await Promise.allSettled([
        this.loadInventory(),
        this.loadHistory(),
        this.loadFreeCase(),
      ]);
    } catch (err) {
      if (err.status === 401) return;
      showToast(err.message, 'error');
    }
  },

  renderSessionState() {
    const isLogged = Boolean(this.user);
    const authBox = document.getElementById('nav-auth-box');
    const guestBox = document.getElementById('nav-guest-box');

    if (isLogged) {
      if (guestBox) guestBox.style.display = 'none';
      if (authBox) authBox.style.display = 'flex';

      const saldoEl = document.getElementById('nav-saldo-val');
      const loginEl = document.getElementById('nav-user-login');
      const roleEl = document.getElementById('nav-user-role');
      if (saldoEl) saldoEl.textContent = formatMoney(this.saldo);
      if (loginEl) loginEl.textContent = this.user.login;
      if (roleEl) {
        const role = String(this.user.rola || 'user').toLowerCase();
        roleEl.textContent = role;
        roleEl.classList.toggle('admin', role === 'admin');
      }
    } else {
      if (guestBox) guestBox.style.display = 'flex';
      if (authBox) authBox.style.display = 'none';
      this.renderFreeCase();
    }
  },

  async login(login, password) {
    this.setAuthBusy(true);
    this.clearAuthError();
    try {
      await this.beginOAuth(login, password);
      await this.loadMe();
      this.closeModal('auth-modal');
      this.resetAuthForms();
      showToast(`Witaj, ${this.user?.login || login}!`, 'success');
      SoundFx.coin();
    } catch (err) {
      const message = err.message || this.loginErrorMessage(err.kod || '');
      this.setAuthError(message);
      showToast(message, 'error');
      SoundFx.loss();
    } finally {
      this.setAuthBusy(false);
    }
  },

  async register(login, email, password) {
    try {
      const data = await this.api.fetch('register.php', {
        method: 'POST',
        body: { login, email, password },
        auth: false,
      });
      showToast(data.message || 'Konto utworzone.', 'success');
      SoundFx.fanfare();
      await this.login(login, password);
    } catch (err) {
      this.setAuthError(err.message);
      showToast(err.message, 'error');
    }
  },

  async registerDemo() {
    const suffix = Math.random().toString(36).slice(2, 8);
    const demoLogin = `gracz_${suffix}`;
    this.setAuthBusy(true);
    try {
      await this.register(demoLogin, `${demoLogin}@mocnydrop.local`, 'demo12345');
    } finally {
      this.setAuthBusy(false);
    }
  },

  async logout(userInitiated = true) {
    if (getAccessToken() && userInitiated) {
      try { await this.api.fetch('logout.php', { method: 'POST' }); } catch {}
    }

    this.clearTokens();
    this.user = null;
    this.saldo = 0;
    this.inventory = [];
    this.freeCaseReadyAt = null;
    clearInterval(this.freeCaseTimer);
    this.freeCaseTimer = null;

    this.renderSessionState();
    this.renderRods();
    this.renderHistoryTable([]);
    this.closeModal('profile-modal');

    if (userInitiated) {
      showToast('Wylogowano.', 'info');
    }
  },

  bindProfile() {
    document.getElementById('btn-profile')?.addEventListener('click', () => this.openProfile());
    document.getElementById('btn-profile-logout')?.addEventListener('click', () => this.logout(true));
  },

  async openProfile() {
    if (!this.requireLogin('Zaloguj się, aby zobaczyć profil.')) return;
    const content = document.getElementById('profile-content');
    if (content) content.innerHTML = '<p class="hint">Wczytywanie profilu…</p>';
    this.openModal('profile-modal');
    await this.renderProfile();
  },

  async renderProfile() {
    const content = document.getElementById('profile-content');
    if (!content) return;

    try {
      const [me, inventory] = await Promise.all([
        this.api.fetch('me.php'),
        this.api.fetch('inventory.php'),
      ]);

      if (me.user) {
        this.user = me.user;
        this.saldo = me.saldo ?? me.user.saldo ?? this.saldo;
        this.renderSessionState();
      }

      this.inventory = inventory.ekwipunek || [];
      const summary = inventory.podsumowanie || me.ekwipunek || {};

      content.innerHTML = this.profileTemplate(this.user, summary, this.inventory);
      this.renderRods();
    } catch (err) {
      content.innerHTML = `<p class="hint error-text">${esc(err.message)}</p>`;
    }
  },

  profileTemplate(user, summary, items) {
    if (!user) return '<p class="hint">Nie jesteś zalogowany.</p>';

    const role = String(user.rola || 'user').toLowerCase();
    const rows = [
      ['Login', esc(user.login)],
      ['E-mail', user.email ? esc(user.email) : '—'],
      ['Rola', `<span class="user-role-pill ${role === 'admin' ? 'admin' : ''}">${esc(role)}</span>`],
      ['Saldo', `<strong class="profile-saldo">${formatMoney(this.saldo)} 🪙</strong>`],
      [
        'Ekwipunek',
        `<b>${Number(summary.liczba_przedmiotow ?? items.length)}</b> szt. · wartość
         <b>${formatMoney(summary.laczna_wartosc)} 🪙</b>`,
      ],
    ];

    const cards = items.length
      ? items.map(item => `
          <div class="fish-item-card" style="border-left-color:${esc(item.kolor)}">
            <img src="${iconOf(item)}" alt="">
            <div class="fish-name" style="color:${esc(item.kolor)}">${esc(item.nazwa)}</div>
            <div class="fish-rarity">${esc(item.rzadkosc)}</div>
            ${item.zarzuty_pozostale !== null && item.zarzuty_pozostale !== undefined
              ? `<div class="fish-casts">🎣 ${castsLabel(item.zarzuty_pozostale)}</div>` : ''}
            <div class="fish-val">${formatMoney(item.wartosc)} 🪙</div>
          </div>
        `).join('')
      : '<p class="hint profile-empty">Ekwipunek jest pusty — zarzuć wędkę albo otwórz skrzynkę.</p>';

    return `
      <dl class="profile-account">
        ${rows.map(([label, value]) => `
          <div class="profile-row">
            <dt>${label}</dt>
            <dd>${value}</dd>
          </div>
        `).join('')}
      </dl>
      <h4 class="profile-heading">🎒 Posiadane przedmioty</h4>
      <div class="inv-grid">${cards}</div>
    `;
  },
};