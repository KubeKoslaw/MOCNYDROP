import { API_BASE, ACCESS_KEY } from './config.js';
import { getAccessToken, getRefreshToken } from './contract.js';

export const TOKEN_KEY = ACCESS_KEY;

export class ApiError extends Error {
  constructor(message, kod = 'error', status = 0) {
    super(message);
    this.name = 'ApiError';
    this.kod = kod;
    this.status = status;
  }
}

export function readToken(key = TOKEN_KEY) {
  try {
    return window.localStorage.getItem(key);
  } catch {
    return null;
  }
}

export function createApi(options = {}) {
  const baseUrl = options.baseUrl === undefined ? API_BASE : String(options.baseUrl);
  const tokenKey = options.tokenKey || TOKEN_KEY;
  const getToken = options.getToken || (() => readToken(tokenKey));
  const fetchImpl = options.fetchImpl || ((...args) => fetch(...args));

  const url = (path) => baseUrl.replace(/\/$/, '') + '/' + String(path).replace(/^\//, '');

  async function request(path, init = {}) {
    const headers = { 'Content-Type': 'application/json' };
    const token = getToken();
    if (token) { headers.Authorization = 'Bearer ' + token; }

    const response = await fetchImpl(url(path), {
      method: init.method || 'GET',
      headers,
      body: init.body === undefined ? undefined : JSON.stringify(init.body),
    });

    let data = null;
    try {
      data = await response.json();
    } catch {
      throw new ApiError('Odpowiedź nie jest JSON-em (HTTP ' + response.status + ').', 'bad_response', response.status);
    }

    if (!response.ok || !data || data.success === false) {
      throw new ApiError((data && data.error) || ('Błąd HTTP ' + response.status), (data && data.kod) || 'error', response.status);
    }

    return data;
  }

  return {
    request,
    cast(rodId) {
      return request('fish.php', { method: 'POST', body: { wedka_id: Number(rodId) } });
    },
    rods() {
      return request('wedki.php');
    },
  };
}

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