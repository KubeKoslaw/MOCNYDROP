/**
 * public/assets/js/fishing/api.js
 *
 * Minimalny klient API dla sceny łowienia. Trzyma się kształtu odpowiedzi
 * zdefiniowanego w backend/Json.php:
 *
 *   sukces: { "success": true, ...dane }
 *   błąd:   { "success": false, "error": "...", "kod": "..." }
 *
 * Frontend rozróżnia błędy po `kod`, nie po tekście — tekst może się zmienić,
 * kod jest częścią kontraktu (insufficient_funds, rod_empty, rod_inactive,
 * already_sold, invalid_token...).
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

/** Domyślny klucz tokena — ten sam co w dev/playground.html. */
export const TOKEN_KEY = 'mocnydrop_token';

/** Błąd z kodem kontraktu, żeby UI mogło reagować na przyczynę, nie na tekst. */
export class ApiError extends Error {
  /**
   * @param {string} message komunikat z backendu (lub zastępczy)
   * @param {string} kod     kod błędu z pola `kod`
   * @param {number} status  kod HTTP
   */
  constructor(message, kod = 'error', status = 0) {
    super(message);

    this.name = 'ApiError';
    this.kod = kod;
    this.status = status;
  }
}

/** Czyta token z localStorage (odporność na brak localStorage, np. tryb prywatny). */
export function readToken(key = TOKEN_KEY) {
  try {
    return window.localStorage.getItem(key);
  } catch (error) {
    return null;
  }
}

/**
 * @param {{
 *   baseUrl?: string,
 *   tokenKey?: string,
 *   getToken?: () => (string|null),
 *   fetchImpl?: typeof fetch,
 * }} [options]
 */
export function createApi(options = {}) {
  const baseUrl = options.baseUrl === undefined ? 'api/' : String(options.baseUrl);
  const tokenKey = options.tokenKey || TOKEN_KEY;
  const getToken = options.getToken || (() => readToken(tokenKey));
  const fetchImpl = options.fetchImpl || ((...args) => fetch(...args));

  const url = (path) => baseUrl.replace(/\/$/, '') + '/' + String(path).replace(/^\//, '');

  /**
   * @param {string} path                            np. 'fish.php'
   * @param {{ method?: string, body?: unknown }} [init]
   */
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

    /**
     * POST fish.php — zarzut wędką.
     * Zwraca pełny payload (przedmiot, reel, reel_indeks_wygranej, saldo_po).
     */
    cast(rodId) {
      return request('fish.php', { method: 'POST', body: { wedka_id: Number(rodId) } });
    },

    /** GET wedki.php — wędki z pulami przedmiotów i słownikiem rzadkości. */
    rods() {
      return request('wedki.php');
    },
  };
}
