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
