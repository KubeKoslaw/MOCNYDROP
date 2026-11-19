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


