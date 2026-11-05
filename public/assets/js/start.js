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
