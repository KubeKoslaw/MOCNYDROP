/**
 * Kontrakt v1: wynik połowu ZAWSZE pochodzi z serwera (api/fish.php).
 * Scena pokazuje pasek ikon z pola `reel` i zatrzymuje go na
 * `reel_indeks_wygranej` — dlatego `reel` i ten indeks są WYMAGANE,
 * a zwycięzca musi być tym samym przedmiotem co `payload.przedmiot`.
 *
 * v2: jeśli zmieni się kształt odpowiedzi backendu, zmienia się tylko ten plik
 * (komponent dostaje `payload.przedmiot` i pasek przez helpery niżej).
 */

export class ContractError extends Error {
  constructor(message, kod = 'bad_response') {
    super(message);
    this.name = 'ContractError';
    this.kod = kod;
    this.status = 0;
  }
}

export const REQUIRED_PRZEDMIOT_FIELDS = Object.freeze([
  'przedmiot_id', 'nazwa', 'rzadkosc', 'rzadkosc_kod', 'kolor', 'wartosc', 'szansa_procent',
]);

/** Ile kopii paska budujemy, żeby kręcenie wyglądało jak ruletka. */
export const STRIP_COPIES = 3;

/**
 * Pozycja zwycięzcy w zbudowanym pasku: środkowa kopia `reel`.
 * Dzięki temu pasek przewija się o wiele pozycji, a wygrana ląduje na środku.
 */
export function stripTargetIndex(reelLength, winnerIndex) {
  return Number(reelLength) + Number(winnerIndex);
}

/** Pasek = `copies` kopii `reel` pod rząd (zwycięzca w środkowej kopii). */
export function buildStrip(reel, copies = STRIP_COPIES) {
  const items = Array.isArray(reel) ? reel : [];
  const out = [];
  for (let i = 0; i < copies; i += 1) { out.push(...items); }
  return out;
}

/** Zwraca `null`, gdy wygrana zgadza się z `przedmiot`, albo opis rozjazdu. */
export function reelWinnerMismatch(payload) {
  if (!payload || !Array.isArray(payload.reel)) { return 'brak tablicy `reel`'; }

  const index = payload.reel_indeks_wygranej;

  if (!Number.isInteger(index) || index < 0 || index >= payload.reel.length) {
    return '`reel_indeks_wygranej` (' + String(index) + ') poza zakresem paska';
  }

  const winner = payload.reel[index];
  const fish = payload.przedmiot;

  if (!winner || typeof winner !== 'object' || !fish || typeof fish !== 'object') {
    return 'brak obiektu zwycięzcy lub `przedmiot`';
  }

  if (Number(winner.przedmiot_id) !== Number(fish.przedmiot_id)) {
    return 'reel[' + index + '].przedmiot_id (' + winner.przedmiot_id + ') != przedmiot.przedmiot_id ('
      + fish.przedmiot_id + ')';
  }

  return null;
}

/** Zwraca `payload.przedmiot` albo rzuca ContractError (kod `bad_response`). */
export function validateFishPayload(payload) {
  if (!payload || typeof payload !== 'object') {
    throw new ContractError('Brak payloadu z wynikiem połowu.');
  }

  const fish = payload.przedmiot;

  if (!fish || typeof fish !== 'object') {
    throw new ContractError('Odpowiedź nie zawiera obiektu `przedmiot`.');
  }

  const missing = REQUIRED_PRZEDMIOT_FIELDS.filter(
    (field) => fish[field] === undefined || fish[field] === null
  );

  if (missing.length > 0) {
    throw new ContractError('W `przedmiot` brakuje pól: ' + missing.join(', ') + '.');
  }

  if (!Array.isArray(payload.reel) || payload.reel.length === 0) {
    throw new ContractError('Odpowiedź nie zawiera niepustej tablicy `reel`.');
  }

  const mismatch = reelWinnerMismatch(payload);

  if (mismatch !== null) {
    throw new ContractError('Pasek `reel` nie zgadza się z wynikiem: ' + mismatch + '.');
  }

  return fish;
}

export function isContractValid(payload) {
  try {
    validateFishPayload(payload);
  } catch (error) {
    return false;
  }

  return true;
}
