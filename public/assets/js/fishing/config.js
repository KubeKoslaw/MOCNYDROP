/**
 * Animacja v1 — konfiguracja faz i czasów.
 * Scena to prosty panel-pasek (ruletka ikon); wyniki i `reel` pochodzą z API.
 * v2: nowe fazy/czasy dopisuje się tutaj; wygląd opisuje CSS (`[data-phase]`).
 */

/**
 * Adres API (względny — strona żyje w katalogu głównym projektu) i klucz,
 * pod którym main.js trzyma access token w localStorage. Muszą być zgodne
 * z `main.js`; `<fishing-scene token-key="...">` może je nadpisać.
 */
export const API_BASE = 'api/';
export const ACCESS_KEY = 'mocnydrop_access_token';
/** Refresh token zapisuje wyłącznie main.js (scena go nie używa). */
export const REFRESH_KEY = 'mocnydrop_refresh_token';

export const PHASES = Object.freeze({
  IDLE: 'idle',
  REQUESTING: 'requesting',
  SPINNING: 'spinning',
  REVEAL: 'reveal',
  ERROR: 'error',
});

export const PHASE_ORDER = Object.freeze([
  PHASES.IDLE,
  PHASES.REQUESTING,
  PHASES.SPINNING,
  PHASES.REVEAL,
]);

/** Czasy w ms; `spinMin`/`spinMax` to widełki kręcenia paska. */
export const TIMINGS = Object.freeze({
  requesting: 250,
  spinMin: 1600,
  spinMax: 2200,
  reducedScale: 0.2,
});

export const STATUS_TEXT = Object.freeze({
  [PHASES.IDLE]: 'Gotowy? Zakręć łowiskiem.',
  [PHASES.REQUESTING]: 'Losowanie…',
  [PHASES.SPINNING]: 'Pasek się kręci…',
  [PHASES.REVEAL]: '',
  [PHASES.ERROR]: '',
});

export const BUTTON_TEXT = Object.freeze({
  idle: 'Zarzuć wędkę',
  requesting: 'Losowanie…',
  spinning: 'Kręci się…',
  reveal: 'Złowiono!',
  error: 'Zarzuć wędkę',
});

export const RARITY_ORDER = Object.freeze([
  'pospolita', 'przecietna', 'niezla', 'mityczna', 'niesamowita', 'ultra',
]);
