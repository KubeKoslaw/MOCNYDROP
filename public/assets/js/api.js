// OAuth 2.0: komponent sceny używa bieżącego access tokenu (bez refreshu —
// odświeżaniem zajmuje się ApiClient w main.js, a scena dostaje świeży token
// przez zdarzenie/atrybut `token-key`).
export const TOKEN_KEY = 'mocnydrop_access_token';

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
  } catch (error) {
    return null;
  }
}

export function createApi(options = {}) {
  const baseUrl = options.baseUrl === undefined ? 'api/' : String(options.baseUrl);
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
    } catch (error) {
      throw new ApiError(
        'Odpowiedź nie jest JSON-em (HTTP ' + response.status + ').',
        'bad_response',
        response.status
      );
    }

    if (!response.ok || !data || data.success === false) {
      throw new ApiError(
        (data && data.error) || ('Błąd HTTP ' + response.status),
        (data && data.kod) || 'error',
        response.status
      );
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
