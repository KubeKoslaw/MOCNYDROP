/**
 * public/assets/js/fishing/contract.js
 *
 * Walidacja odpowiedzi `api/cast.php` po stronie frontendu.
 *
 * Dlaczego osobny moduł (a nie metoda komponentu): to czysta funkcja bez DOM,
 * więc można ją uruchomić w Node (`dev/check-fishing.mjs`) i sprawdzić na
 * prawdziwej odpowiedzi API, że animacja dostanie to, czego oczekuje.
 *
 * Kontrakt (backend/Reel.php + backend/FishingService.php):
 *   - `reel` ma `reel_dlugosc` pozycji,
 *   - wygrana ryba leży pod `reel_indeks_wygranej`,
 *   - `reel[reel_indeks_wygranej]` to TA SAMA ryba co `zlowiona_ryba`,
 *   - wynik jest ustalony na serwerze, więc frontend nic nie losuje.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

/** Błąd kontraktu — z kodem w tym samym stylu co kody błędów z backend/Json.php. */
export class ContractError extends Error {
  constructor(message, kod = 'bad_response') {
    super(message);

    this.name = 'ContractError';
    this.kod = kod;
    this.status = 0;
  }
}

/** Pola `zlowiona_ryba`, bez których karta wygranej nie miałaby czego pokazać. */
export const REQUIRED_FISH_FIELDS = Object.freeze([
  'ryba_id',
  'nazwa',
  'rzadkosc',
  'rzadkosc_kod',
  'kolor',
  'wartosc',
  'szansa_procent',
]);

/**
 * Sprawdza payload z cast.php i zwraca `zlowiona_ryba`.
 * Rzuca ContractError, gdy odpowiedź nie nadaje się do animacji — wolimy
 * czytelny błąd niż ciche "animujmy cokolwiek".
 *
 * @param {any} payload
 * @returns {Record<string, any>}
 */
export function validateCastPayload(payload) {
  if (!payload || typeof payload !== 'object') {
    throw new ContractError('Brak payloadu z wynikiem połowu.');
  }

  const fish = payload.zlowiona_ryba;

  if (!fish || typeof fish !== 'object') {
    throw new ContractError('Odpowiedź nie zawiera obiektu `zlowiona_ryba`.');
  }

  const missing = REQUIRED_FISH_FIELDS.filter((field) => fish[field] === undefined || fish[field] === null);

  if (missing.length > 0) {
    throw new ContractError('W `zlowiona_ryba` brakuje pól: ' + missing.join(', ') + '.');
  }

  if (!Array.isArray(payload.reel) || payload.reel.length === 0) {
    throw new ContractError('Odpowiedź nie zawiera niepustej tablicy `reel`.');
  }

  const index = payload.reel_indeks_wygranej;

  if (!Number.isInteger(index) || index < 0 || index >= payload.reel.length) {
    throw new ContractError(
      '`reel_indeks_wygranej` (' + String(index) + ') poza zakresem paska o długości '
      + payload.reel.length + '.'
    );
  }

  const winner = payload.reel[index];

  if (!winner || typeof winner !== 'object') {
    throw new ContractError('Pozycja `reel[' + index + ']` nie jest obiektem.');
  }

  // Salda i koszt są potrzebne karcie wygranej; bez nich karta pokaże zera,
  // więc tylko ostrzegamy (wynik połowu i tak jest już zapisany w bazie).
  ['saldo_przed', 'saldo_po'].forEach((field) => {
    if (typeof payload[field] !== 'number') {
      console.warn('[fishing-contract] brak liczbowego `' + field + '` w odpowiedzi cast.php');
    }
  });

  return fish;
}

/**
 * Miękka kontrola spójności: wygrana pozycja paska ma być tą samą rybą co
 * `zlowiona_ryba`. Zwraca komunikat ostrzeżenia albo null, gdy jest zgodne.
 *
 * To wczesny alarm, gdyby ktoś zmienił backend/Reel.php albo ustawienia
 * `reel_indeks_wygranej` bez aktualizacji frontendu.
 */
export function reelWinnerMismatch(payload) {
  if (!payload || !Array.isArray(payload.reel)) { return null; }

  const fish = payload.zlowiona_ryba;
  const index = payload.reel_indeks_wygranej;

  if (!fish || !Number.isInteger(index)) { return null; }

  const winner = payload.reel[index];

  if (!winner || winner.ryba_id === undefined || fish.ryba_id === undefined) { return null; }

  if (Number(winner.ryba_id) === Number(fish.ryba_id)) { return null; }

  return 'reel[' + index + '].ryba_id (' + winner.ryba_id + ') != zlowiona_ryba.ryba_id ('
    + fish.ryba_id + ')';
}

/**
 * Czy payload jest w pełni zgodny z kontraktem (bez rzucania) — wygodne
 * w testach i w harnessie dev/fishing-demo.html.
 */
export function isContractValid(payload) {
  try {
    validateCastPayload(payload);
  } catch (error) {
    return false;
  }

  return reelWinnerMismatch(payload) === null;
}
