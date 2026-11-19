const API_BASE = 'api/';
const ACCESS_KEY = 'mocnydrop_access_token';
const REFRESH_KEY = 'mocnydrop_refresh_token';
const EXPIRES_KEY = 'mocnydrop_access_expires_at';
const LEGACY_KEY = 'mocnydrop_token';
const SPLASH_MS = 3000;
const REFRESH_MARGIN_MS = 60 * 1000;
const HISTORY_LIMIT = 15;
const DEFAULT_ICON = 'assets/icons/anchovy.png';

function storageGet(key) {
  try { return window.localStorage.getItem(key); } catch { return null; }
}

function storageSet(key, value) {
  try { window.localStorage.setItem(key, value); } catch { /* prywatny tryb przeglądarki */ }
}

function storageRemove(key) {
  try { window.localStorage.removeItem(key); } catch { /* prywatny tryb przeglądarki */ }
}

function getAccessToken() { return storageGet(ACCESS_KEY); }
function getRefreshToken() { return storageGet(REFRESH_KEY); }

function getAccessExpiresAt() {
  const value = Number(storageGet(EXPIRES_KEY));
  return Number.isFinite(value) && value > 0 ? value : 0;
}
/** Migracja ze starego, jednorazowego tokenu na parę access/refresh. */
function migrateLegacyStorage() {
  storageRemove(LEGACY_KEY);
}

/**
 * POST api/token.php — jedyne miejsce, które wymienia kod autoryzacyjny
 * albo refresh token na nową parę tokenów. Nie korzysta z ApiClient,
 * żeby auto-refresh nie mógł wywołać sam siebie rekurencyjnie.
 */
async function tokenRequest(body) {
  let response;
  try {
    response = await fetch(API_BASE + 'token.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(body),
    });
  } catch {
    const err = new Error('Brak połączenia z serwerem.');
    err.kod = 'network_error';
    throw err;
  }

  let data;
  try {
    data = await response.json();
  } catch {
    const err = new Error(`Błąd serwera (HTTP ${response.status})`);
    err.kod = 'bad_response';
    err.status = response.status;
    throw err;
  }

  if (!response.ok || data.success === false) {
    const err = new Error(data.error || `Błąd serwera (HTTP ${response.status})`);
    err.kod = data.kod || 'error';
    err.status = response.status;
    throw err;
  }

  return data;
}

const SoundFx = {
  ctx: null,
  init() {
    if (!this.ctx && (window.AudioContext || window.webkitAudioContext)) {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      this.ctx = new AudioCtx();
    }
  },
  playTone(freq, type = 'sine', duration = 0.15, vol = 0.1) {
    try {
      this.init();
      if (!this.ctx || this.ctx.state === 'suspended') {
        this.ctx?.resume();
      }
      const osc = this.ctx.createOscillator();
      const gain = this.ctx.createGain();
      osc.type = type;
      osc.frequency.setValueAtTime(freq, this.ctx.currentTime);
      gain.gain.setValueAtTime(vol, this.ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, this.ctx.currentTime + duration);
      osc.connect(gain);
      gain.connect(this.ctx.destination);
      osc.start();
      osc.stop(this.ctx.currentTime + duration);
    } catch {}
  },
  coin() {
    this.playTone(987.77, 'triangle', 0.1, 0.12);
    setTimeout(() => this.playTone(1318.51, 'triangle', 0.2, 0.15), 80);
  },
  splash() {
    this.playTone(220, 'sine', 0.25, 0.1);
    setTimeout(() => this.playTone(330, 'sine', 0.2, 0.08), 100);
  },
  fanfare() {
    [523.25, 659.25, 783.99, 1046.50].forEach((freq, idx) => {
      setTimeout(() => this.playTone(freq, 'triangle', 0.28, 0.18), idx * 110);
    });
  },
  loss() {
    this.playTone(260, 'sawtooth', 0.2, 0.08);
    setTimeout(() => this.playTone(190, 'sawtooth', 0.35, 0.1), 160);
  }
};

function showToast(message, type = 'info') {
  const container = document.getElementById('toast-container');
  if (!container) return;

  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  const text = document.createElement('span');
  text.textContent = message;
  const close = document.createElement('button');
  close.className = 'toast-close';
  close.type = 'button';
  close.setAttribute('aria-label', 'Zamknij powiadomienie');
  close.innerHTML = '&times;';
  close.addEventListener('click', () => toast.remove());
  toast.append(text, close);

  container.appendChild(toast);
  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transition = 'opacity 0.3s ease';
    setTimeout(() => toast.remove(), 300);
  }, 4200);
}

function formatMoney(amount) {
  return Number(amount ?? 0).toFixed(2).replace('.', ',');
}

function formatChance(value) {
  const n = Number(value ?? 0);
  if (n >= 10) return `${n.toFixed(1)}%`;
  if (n >= 1) return `${n.toFixed(2)}%`;
  return `${Number(n.toFixed(3))}%`;
}

function formatCountdown(seconds) {
  const s = Math.max(0, Math.floor(seconds));
  const pad = (v) => String(v).padStart(2, '0');
  return `${pad(Math.floor(s / 3600))}:${pad(Math.floor((s % 3600) / 60))}:${pad(s % 60)}`;
}

function castsLabel(count) {
  const n = Math.abs(Number(count) || 0);
  const last = n % 10;
  const lastTwo = n % 100;
  if (n === 1) return '1 zarzut';
  if (last >= 2 && last <= 4 && (lastTwo < 12 || lastTwo > 14)) return `${n} zarzuty`;
  return `${n} zarzutów`;
}

function esc(str) {
  const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  return String(str ?? '').replace(/[&<>"']/g, (c) => map[c]);
}

function iconOf(item) {
  return esc(item.ikona || DEFAULT_ICON);
}

function poolIcons(items) {
  return (items || []).map(item => {
    const tip = `${item.nazwa} · ${formatChance(item.szansa_procent)}`;
    return `
      <span class="pool-icon" tabindex="0" style="--rarity:${esc(item.kolor)}" data-tip="${esc(tip)}" aria-label="${esc(tip)}">
        <img src="${iconOf(item)}" alt="">
      </span>
    `;
  }).join('');
}

/**
 * Klient JSON API.
 *
 * OAuth 2.0: każde żądanie (poza login/register) idzie z nagłówkiem
 * `Authorization: Bearer <access>`. Gdy serwer zwróci 401 / kod
 * `unauthorized`, klient RAZ próbuje odświeżyć access token przez
 * `token.php`, a następnie powtarza oryginalne żądanie.
 */
class ApiClient {
  constructor(app, baseUrl = API_BASE) {
    this.app = app;
    this.baseUrl = baseUrl.replace(/\/$/, '') + '/';
  }

  getToken() {
    return getAccessToken();
  }

  async fetch(endpoint, options = {}) {
    return this.request(endpoint, options, true);
  }

  async request(endpoint, options, allowRefresh) {
    const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
    if (options.auth !== false) {
      const token = this.getToken();
      if (token) headers.Authorization = `Bearer ${token}`;
    }

    const url = `${this.baseUrl}${endpoint.replace(/^\//, '')}`;
    let response;
    try {
      response = await fetch(url, {
        method: options.method || 'GET',
        headers,
        body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
      });
    } catch {
      const err = new Error('Brak połączenia z serwerem.');
      err.kod = 'network_error';
      throw err;
    }

    let data;
    try {
      data = await response.json();
    } catch {
      const err = new Error(`Błąd serwera (HTTP ${response.status})`);
      err.kod = 'bad_response';
      err.status = response.status;
      throw err;
    }

    const unauthorized = response.status === 401 || data.kod === 'unauthorized';

    if (unauthorized && options.auth !== false) {
      if (allowRefresh && getRefreshToken()) {
        const result = await this.app.refreshAccessToken();
        if (result === 'ok') {
          return this.request(endpoint, options, false);
        }
        if (result === 'expired') {
          this.app.handleSessionExpired();
        }
      } else if (!getRefreshToken() && (getAccessToken() || this.app.user)) {
        this.app.handleSessionExpired();
      }
    }

    if (!response.ok || data.success === false) {
      const err = new Error(data.error || `Błąd serwera (HTTP ${response.status})`);
      err.kod = data.kod || 'error';
      err.status = response.status;
      throw err;
    }

    return data;
  }
}

class MocnyDropApp {
  constructor() {
    this.api = new ApiClient(this);
    this.user = null;
    this.saldo = 0;
    this.currentMode = 'fish';
    this.activeRods = [];
    this.activeCases = [];
    this.inventory = [];
    this.isCasting = false;
    this.selectedRodId = null;

    this.freeCaseReadyAt = null;
    this.freeCaseTimer = null;
    this.refreshPromise = null;
    this.authBusy = false;
    this.winRollTimer = null;
    this.winRollTimeout = null;
  }

  async init() {
    // Migracja: stary token z poprzedniej wersji UI nie jest już używany.
    migrateLegacyStorage();

    this.hideSplash();
    this.bindPoolTooltip();
    this.bindNavigation();
    this.bindAuthModals();
    this.bindSceneControls();
    this.bindFreeCase();
    this.bindProfile();

    await Promise.allSettled([
      this.loadRods(),
      this.loadCases(),
    ]);

    const params = this.readUrlParams();

    if (params.code) {
      this.clearUrlParams();
      await this.completeOAuthRedirect(params.code);
    } else if (params.loginError) {
      this.clearUrlParams();
      const message = this.loginErrorMessage(params.loginError);
      showToast(message, 'error');
      this.setAuthError(message);
      this.switchAuthTab('login');
      this.openModal('auth-modal');
    } else if (getAccessToken() || getRefreshToken()) {
      await this.loadMe();
    }

    this.renderSessionState();
  }

 /* ------------------------------------------------------------------ */
  /* Splash i tooltipy                                                   */
  /* ------------------------------------------------------------------ */

  hideSplash() {
    const splash = document.getElementById('splash-screen');
    if (!splash) return;
    setTimeout(() => {
      splash.classList.add('is-hidden');
      setTimeout(() => splash.remove(), 600);
    }, SPLASH_MS);
  }

  bindPoolTooltip() {
    const tip = document.createElement('div');
    tip.className = 'pool-tip';
    tip.hidden = true;
    document.body.appendChild(tip);

    const show = (icon) => {
      tip.textContent = icon.dataset.tip;
      tip.style.setProperty('--rarity', icon.style.getPropertyValue('--rarity'));
      tip.hidden = false;
      const r = icon.getBoundingClientRect();
      const w = tip.offsetWidth;
      const left = Math.min(Math.max(8, r.left + r.width / 2 - w / 2), window.innerWidth - w - 8);
      const top = r.top - tip.offsetHeight - 8;
      tip.style.left = `${left}px`;
      tip.style.top = `${top < 8 ? r.bottom + 8 : top}px`;
    };
    const hide = () => { tip.hidden = true; };

    document.addEventListener('mouseover', (e) => {
      const icon = e.target.closest?.('.pool-icon');
      if (icon) show(icon); else hide();
    });
    document.addEventListener('focusin', (e) => {
      const icon = e.target.closest?.('.pool-icon');
      if (icon) show(icon); else hide();
    });
    document.addEventListener('scroll', hide, { passive: true });
  }

  /* ------------------------------------------------------------------ */
  /* OAuth 2.0 front: kod autoryzacyjny -> access + refresh token        */
  /* ------------------------------------------------------------------ */

  readUrlParams() {
    const params = new URLSearchParams(window.location.search);
    return { code: params.get('code'), loginError: params.get('login_error') };
  }

  clearUrlParams() {
    const url = new URL(window.location.href);
    url.searchParams.delete('code');
    url.searchParams.delete('login_error');
    if (!url.searchParams.toString()) url.search = '';
    window.history.replaceState({}, document.title, url.pathname + url.search + url.hash);
  }

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
  }

  /** Krok 1: login + hasło -> jednorazowy kod autoryzacyjny z login.php. */
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
  }

  /** Krok 2: kod autoryzacyjny -> access token + refresh token. */
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
  }

  /**
   * Wymienia refresh token na nowy access token.
   * Zwraca: 'ok' | 'expired' (serwer odrzucił refresh) | 'offline' (sieć).
   * Wywołania równoległe współdzielą jedno żądanie.
   */
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
  }

  /** Proaktywne odświeżenie, gdy access token wkrótce wygaśnie. */
  async ensureFreshToken() {
    if (!getAccessToken() && !getRefreshToken()) return false;

    const expiresAt = getAccessExpiresAt();
    if (getAccessToken() && (!expiresAt || expiresAt - Date.now() > REFRESH_MARGIN_MS)) {
      return true;
    }

    // Brak refresh tokenu, a access token jest — niech decyzję podejmie API.
    if (getAccessToken() && !getRefreshToken()) return true;

    const result = await this.refreshAccessToken();
    if (result === 'ok') return true;
    if (result === 'expired') this.handleSessionExpired();
    return false;
  }

  storeTokens(data) {
    storageSet(ACCESS_KEY, data.access_token);
    if (data.refresh_token) storageSet(REFRESH_KEY, data.refresh_token);
    const seconds = Number(data.expires_in) > 0 ? Number(data.expires_in) : 3600;
    storageSet(EXPIRES_KEY, String(Date.now() + seconds * 1000));
  }

  clearTokens() {
    storageRemove(ACCESS_KEY);
    storageRemove(REFRESH_KEY);
    storageRemove(EXPIRES_KEY);
  }

  /** Sesja wygasła na dobre (refresh odrzucony) — czyścimy stan i UI. */
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
  }

  /** Logowanie po powrocie z przekierowania OAuth: /?code=... */
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
  }

  /* ------------------------------------------------------------------ */
  /* Sesja                                                               */
  /* ------------------------------------------------------------------ */

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
      if (err.status === 401) return; // auto-refresh / handleSessionExpired już zadziałały
      showToast(err.message, 'error');
    }
  }

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
  }

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
  }

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
  }

  async registerDemo() {
    const suffix = Math.random().toString(36).slice(2, 8);
    const demoLogin = `gracz_${suffix}`;
    this.setAuthBusy(true);
    try {
      await this.register(demoLogin, `${demoLogin}@mocnydrop.local`, 'demo12345');
    } finally {
      this.setAuthBusy(false);
    }
  }

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
  }

  requireLogin(message) {
    if (this.user) return true;
    showToast(message, 'info');
    this.switchAuthTab('login');
    this.openModal('auth-modal');
    return false;
  }

  setSaldo(value) {
    this.saldo = Number(value ?? 0);
    this.renderSessionState();
  }

  async refreshPlayerData() {
    await Promise.allSettled([
      this.loadInventory(),
      this.loadHistory(),
    ]);
  }

  /* ------------------------------------------------------------------ */
  /* Profil gracza (dane konta + posiadane przedmioty)                   */
  /* ------------------------------------------------------------------ */

  bindProfile() {
    document.getElementById('btn-profile')?.addEventListener('click', () => this.openProfile());
    document.getElementById('btn-profile-logout')?.addEventListener('click', () => this.logout(true));
  }

  async openProfile() {
    if (!this.requireLogin('Zaloguj się, aby zobaczyć profil.')) return;
    const content = document.getElementById('profile-content');
    if (content) content.innerHTML = '<p class="hint">Wczytywanie profilu…</p>';
    this.openModal('profile-modal');
    await this.renderProfile();
  }

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
  }

  profileTemplate(user, summary, items) {
    if (!user) {
      return '<p class="hint">Nie jesteś zalogowany.</p>';
    }

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
  }

  /* ------------------------------------------------------------------ */
  /* Nawigacja                                                           */
  /* ------------------------------------------------------------------ */

  bindNavigation() {
    document.querySelectorAll('[data-target-mode]').forEach(btn => {
      btn.addEventListener('click', () => this.switchMode(btn.dataset.targetMode));
    });

    document.getElementById('btn-nav-logout')?.addEventListener('click', () => this.logout(true));
  }

  switchMode(modeId) {
    this.currentMode = modeId;

    document.querySelectorAll('[data-target-mode]').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.targetMode === modeId);
    });

    document.querySelectorAll('.mode-arena-section').forEach(sec => {
      sec.style.display = sec.id === `arena-${modeId}` ? 'block' : 'none';
    });

    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  /* ------------------------------------------------------------------ */
  /* Łowisko                                                             */
  /* ------------------------------------------------------------------ */

  async loadRods() {
    const container = document.getElementById('rods-container');
    try {
      const data = await this.api.fetch('wedki.php', { auth: false });
      this.activeRods = data.wedki || [];
      this.renderRods();
      if (this.activeCases.length) this.renderCases();
    } catch (err) {
      if (container) container.innerHTML = `<p class="hint error-text">${esc(err.message)}</p>`;
    }
  }

  rodCasts(rodId) {
    return this.inventory
      .filter(item => Number(item.wedka_id) === Number(rodId))
      .reduce((sum, item) => sum + Number(item.zarzuty_pozostale || 0), 0);
  }

  rodForItem(przedmiotId) {
    return this.activeRods.find(r => Number(r.przedmiot_id) === Number(przedmiotId)) || null;
  }

  renderRods() {
    const container = document.getElementById('rods-container');
    if (!container) return;

    if (!this.activeRods.length) {
      container.innerHTML = '<p class="hint">Brak dostępnych wędek.</p>';
      return;
    }

    container.innerHTML = this.activeRods.map(rod => {
      const casts = this.rodCasts(rod.id);
      const owned = casts > 0;
      return `
        <div class="rod-card ${owned ? '' : 'is-locked'}">
          <div>
            <div class="rod-head">
              <span class="rod-title">
                <img class="rod-icon" src="${esc(rod.ikona || DEFAULT_ICON)}" alt="">
                <span class="rod-name">${esc(rod.nazwa)}</span>
              </span>
              <span class="rod-casts ${owned ? '' : 'is-empty'}">${owned ? castsLabel(casts) : 'Brak'}</span>
            </div>
            <p class="rod-desc">${esc(rod.opis || '')}</p>
            <div class="rod-pool">${poolIcons(rod.przedmioty)}</div>
          </div>
          ${owned
            ? `<button class="btn btn-primary btn-cast-rod" data-rod-id="${rod.id}" ${this.isCasting ? 'disabled' : ''}>Zarzuć wędkę</button>`
            : '<button class="btn btn-ghost btn-get-rod" type="button">Zdobądź w skrzynkach 📦</button>'}
        </div>
      `;
    }).join('');

    container.querySelectorAll('.btn-cast-rod').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        this.castFishingRod(Number(btn.dataset.rodId));
      });
    });
    container.querySelectorAll('.btn-get-rod').forEach(btn => {
      btn.addEventListener('click', () => this.switchMode('cases'));
    });

    if (!this.isCasting) {
      const selected = this.activeRods.find(r => Number(r.id) === Number(this.selectedRodId) && this.rodCasts(r.id) > 0)
        || this.activeRods.find(r => this.rodCasts(r.id) > 0)
        || this.activeRods[0];
      if (selected) this.selectRodInScene(selected.id);
    }
  }

  async selectRodInScene(rodId) {
    const rod = this.activeRods.find(r => Number(r.id) === Number(rodId));
    if (!rod) return;
    this.selectedRodId = rod.id;
    await customElements.whenDefined('fishing-scene');
    const scene = document.getElementById('main-fishing-scene');
    if (scene && typeof scene.select === 'function') {
      scene.select(rod.id, { nazwa: rod.nazwa, zarzuty: this.rodCasts(rod.id) });
    }
  }

  async castFishingRod(rodId) {
    if (this.isCasting) return;
    if (!this.requireLogin('Zaloguj się, aby łowić.')) return;

    if (this.rodCasts(rodId) <= 0) {
      showToast('Nie masz tej wędki — zdobądź ją w skrzynkach.', 'error');
      SoundFx.loss();
      return;
    }

    if (!(await this.ensureFreshToken())) return;

    await customElements.whenDefined('fishing-scene');
    const scene = document.getElementById('main-fishing-scene');
    if (!scene) return;

    this.isCasting = true;
    document.querySelectorAll('.btn-cast-rod').forEach(b => { b.disabled = true; });
    await this.selectRodInScene(rodId);
    scene.scrollIntoView({ behavior: 'smooth', block: 'center' });

    try {
      SoundFx.splash();
      await scene.cast(rodId);
    } catch (err) {
      showToast(err.message, 'error');
    } finally {
      this.isCasting = false;
      this.renderRods();
    }
  }

  bindSceneControls() {
    customElements.whenDefined('fishing-scene').then(() => {
      const scene = document.getElementById('main-fishing-scene');
      if (!scene) return;

      scene.addEventListener('catch', (event) => {
        this.setSaldo(event.detail.payload.saldo_po);
        SoundFx.fanfare();
        this.refreshPlayerData();
      });

      scene.addEventListener('castrequest', () => {
        const rod = this.activeRods.find(r => this.rodCasts(r.id) > 0);
        if (rod) {
          this.castFishingRod(rod.id);
        } else if (this.requireLogin('Zaloguj się, aby łowić.')) {
          showToast('Nie masz żadnej wędki — zdobądź ją w skrzynkach.', 'error');
        }
      });

      scene.addEventListener('error', async (event) => {
        const { kod, status } = event.detail || {};
        if (status !== 401 && kod !== 'unauthorized') return;

        const result = await this.refreshAccessToken();
        if (result === 'ok') {
          showToast('Sesja odświeżona — możesz zarzucić ponownie.', 'info');
        } else if (result === 'expired') {
          this.handleSessionExpired();
        } else {
          showToast('Brak połączenia z serwerem — spróbuj ponownie.', 'error');
        }
      });
    });
  }


