import { 
  API_BASE, ACCESS_KEY, REFRESH_KEY, EXPIRES_KEY, REFRESH_MARGIN_MS,
  getAccessToken, getRefreshToken, getAccessExpiresAt, storageSet, storageRemove,
  showToast, SoundFx, formatMoney, esc, iconOf, castsLabel 
} from './Multimedia.js';

export async function tokenRequest(body) {
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

export class ApiClient {
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