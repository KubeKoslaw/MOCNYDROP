import {
  BUTTON_TEXT,
  PHASES,
  PHASE_ORDER,
  RARITY_ORDER,
  STATUS_TEXT,
  TIMINGS,
} from '../public/assets/js/fishing/config.js';
import { ApiError, createApi } from '../public/assets/js/fishing/api.js';
import {
  REQUIRED_PRZEDMIOT_FIELDS,
  STRIP_COPIES,
  buildStrip,
  isContractValid,
  reelWinnerMismatch,
  stripTargetIndex,
  validateFishPayload,
} from '../public/assets/js/fishing/contract.js';

/**
 * tests/check-fishing.mjs — testy modułów animacji v1 (bez DOM, bez backendu).
 * Sprawdza config, kontrakt paska `reel` (ruletka) i klienta API.
 * Uruchomienie: `node tests/check-fishing.mjs` (exit 0 = ZIELONE, 1 = CZERWONE).
 */

let passed = 0;
let failed = 0;

function check(name, condition, details = '') {
  if (condition) {
    passed += 1;
    console.log('  OK    ' + name);
  } else {
    failed += 1;
    console.log('  FAIL  ' + name + (details === '' ? '' : ' — ' + details));
  }
}

function throwsWith(name, fn, kod) {
  try {
    fn();
    check(name, false, 'oczekiwano wyjątku, nic nie rzucono');
  } catch (error) {
    check(name, error.kod === kod, 'kod=' + (error.kod || '?') + ' (oczekiwano ' + kod + ')');
  }
}

console.log('\nconfig.js — fazy, statusy, czasy');

const phaseValues = Object.values(PHASES);

check('fazy są unikalne', new Set(phaseValues).size === phaseValues.length);
check('PHASE_ORDER ma 4 kroki: idle -> requesting -> spinning -> reveal',
  PHASE_ORDER.join(',') === 'idle,requesting,spinning,reveal',
  PHASE_ORDER.join(','));
check('każda faza ma etykietę przycisku',
  phaseValues.every((phase) => typeof BUTTON_TEXT[phase] === 'string' && BUTTON_TEXT[phase] !== ''));
check('fazy aktywne mają status aria-live',
  [PHASES.REQUESTING, PHASES.SPINNING]
    .every((phase) => String(STATUS_TEXT[phase] || '').length > 0));
check('czasy dodatnie', Object.values(TIMINGS).every((value) => Number.isFinite(value) && value > 0));
check('widełki kręcenia paska spójne (spinMin < spinMax)',
  TIMINGS.spinMin < TIMINGS.spinMax, TIMINGS.spinMin + ' vs ' + TIMINGS.spinMax);
check('reducedScale w (0,1) — skraca animację',
  TIMINGS.reducedScale > 0 && TIMINGS.reducedScale < 1);
check('kolejność rzadkości jak w bazie',
  RARITY_ORDER.join(',') === 'pospolita,przecietna,niezla,mityczna,niesamowita,ultra');
check('obiekty config są zamrożone',
  [PHASES, PHASE_ORDER, TIMINGS, STATUS_TEXT, BUTTON_TEXT, RARITY_ORDER].every((object) => Object.isFrozen(object)));

console.log('\ncontract.js — payload z `reel` (pasek ruletki)');

function fishItem(overrides = {}) {
  return {
    ekwipunek_id: 11,
    przedmiot_id: 7,
    nazwa: 'Jesiotr',
    rzadkosc: 'Niesamowita',
    rzadkosc_kod: 'niesamowita',
    kolor: '#eab308',
    wartosc: 400,
    ikona: 'assets/icons/sturgeon.png',
    szansa_procent: 1.0,
    ...overrides,
  };
}

const WINNER_INDEX = 14;
const REEL_LENGTH = 40;

function fakePayload(overrides = {}) {
  const fish = fishItem();
  const reel = Array.from({ length: REEL_LENGTH }, (unused, index) => ({
    przedmiot_id: index === WINNER_INDEX ? 7 : 1,
    nazwa: index === WINNER_INDEX ? 'Jesiotr' : 'Anchois',
    rzadkosc: index === WINNER_INDEX ? 'Niesamowita' : 'Pospolita',
    rzadkosc_kod: index === WINNER_INDEX ? 'niesamowita' : 'pospolita',
    kolor: index === WINNER_INDEX ? '#eab308' : '#94a3b8',
    wartosc: index === WINNER_INDEX ? 400 : 3,
    ikona: index === WINNER_INDEX ? 'assets/icons/sturgeon.png' : 'assets/icons/anchovy.png',
  }));

  return {
    success: true,
    rzut: { id: 5, koszt: 150 },
    wedka: { id: 5, nazwa: 'Głębia', koszt_uzycia: 150, zarzuty_pozostale: 9 },
    przedmiot: fish,
    saldo_przed: 500,
    saldo_po: 350,
    reel,
    reel_dlugosc: REEL_LENGTH,
    reel_indeks_wygranej: WINNER_INDEX,
    message: 'Złowiono: Jesiotr (Niesamowita)!',
    ...overrides,
  };
}

check('poprawny payload przechodzi walidację', validateFishPayload(fakePayload()).przedmiot_id === 7);
check('lista wymaganych pól przedmiotu ma 7 pozycji', REQUIRED_PRZEDMIOT_FIELDS.length === 7);
check('reelWinnerMismatch = null gdy wygrana zgodna', reelWinnerMismatch(fakePayload()) === null);
check('isContractValid = true dla poprawnego payloadu', isContractValid(fakePayload()) === true);

throwsWith('brak payloadu -> bad_response', () => validateFishPayload(null), 'bad_response');
throwsWith('brak `reel` -> bad_response',
  () => validateFishPayload(fakePayload({ reel: undefined })), 'bad_response');
throwsWith('pusty `reel` -> bad_response',
  () => validateFishPayload(fakePayload({ reel: [] })), 'bad_response');
throwsWith('indeks wygranej poza zakresem -> bad_response',
  () => validateFishPayload(fakePayload({ reel_indeks_wygranej: REEL_LENGTH })), 'bad_response');
throwsWith('indeks wygranej ujemny -> bad_response',
  () => validateFishPayload(fakePayload({ reel_indeks_wygranej: -1 })), 'bad_response');
throwsWith('indeks wygranej niecałkowity -> bad_response',
  () => validateFishPayload(fakePayload({ reel_indeks_wygranej: 1.5 })), 'bad_response');

const mismatched = fakePayload();

mismatched.reel[WINNER_INDEX] = { ...mismatched.reel[WINNER_INDEX], przedmiot_id: 999 };
check('rozjazd zwycięzcy paska jest wykrywany (string)', typeof reelWinnerMismatch(mismatched) === 'string');
throwsWith('rozjazd zwycięzcy -> bad_response', () => validateFishPayload(mismatched), 'bad_response');
check('rozjazd => isContractValid = false', isContractValid(mismatched) === false);

console.log('\ncontract.js — pozycjonowanie paska (bez geometrii)');

check('STRIP_COPIES = 3 (środkowa kopia jako cel)', STRIP_COPIES === 3);
check('stripTargetIndex(40, 14) = 54 (środkowa kopia)',
  stripTargetIndex(REEL_LENGTH, WINNER_INDEX) === 54, String(stripTargetIndex(REEL_LENGTH, WINNER_INDEX)));

const payload = fakePayload();
const strip = buildStrip(payload.reel);
const target = stripTargetIndex(payload.reel.length, payload.reel_indeks_wygranej);

check('buildStrip: pasek ma 3 * długość reel', strip.length === REEL_LENGTH * STRIP_COPIES,
  String(strip.length));
check('buildStrip: zwycięzca ląduje na pozycji docelowej',
  Number(strip[target].przedmiot_id) === Number(payload.przedmiot.przedmiot_id),
  'idx=' + target + ' przedmiot_id=' + (strip[target] && strip[target].przedmiot_id));
check('buildStrip: pozycja docelowa jest na środku 3-częściowego paska',
  target >= REEL_LENGTH && target < REEL_LENGTH * 2);
check('buildStrip nie mutuje oryginalnego reel', payload.reel.length === REEL_LENGTH);

console.log('\napi.js — createApi (bez DOM, wstrzyknięty fetch)');

let captured = null;

const fakeOk = (data) => async (...args) => {
  captured = { url: args[0], init: args[1] };

  return { ok: true, status: 200, json: async () => data };
};

const okApi = createApi({
  baseUrl: 'api/',
  getToken: () => 'TOK3N',
  fetchImpl: fakeOk({ success: true, przedmiot: { nazwa: 'x' } }),
});

await okApi.cast(6);

check('URL = api/fish.php', captured.url === 'api/fish.php', captured.url);
check('metoda POST', captured.init.method === 'POST');
check('nagłówek Authorization: Bearer', captured.init.headers.Authorization === 'Bearer TOK3N');
check('Content-Type: application/json', captured.init.headers['Content-Type'] === 'application/json');
check('body = {"wedka_id":6}', captured.init.body === '{"wedka_id":6}', captured.init.body);

const noTokenApi = createApi({
  baseUrl: '/api',
  getToken: () => null,
  fetchImpl: fakeOk({ success: true }),
});

await noTokenApi.rods();
check('baseUrl bez "/" i bez tokenu: URL=/api/wedki.php, brak Authorization',
  captured.url === '/api/wedki.php' && captured.init.headers.Authorization === undefined, captured.url);

const failApi = createApi({
  baseUrl: 'api/',
  getToken: () => 'T',
  fetchImpl: async () => ({
    ok: false,
    status: 400,
    json: async () => ({ success: false, error: 'Za mało monet.', kod: 'insufficient_funds' }),
  }),
});

try {
  await failApi.cast(1);
  check('błąd API -> ApiError z kodem insufficient_funds', false, 'brak wyjątku');
} catch (error) {
  check('błąd API -> ApiError z kodem insufficient_funds',
    error instanceof ApiError && error.kod === 'insufficient_funds' && error.status === 400);
}

const badJsonApi = createApi({
  baseUrl: 'api/',
  getToken: () => 'T',
  fetchImpl: async () => ({ ok: false, status: 500, json: async () => { throw new Error('not json'); } }),
});

try {
  await badJsonApi.cast(1);
  check('nie-JSON -> kod bad_response', false, 'brak wyjątku');
} catch (error) {
  check('nie-JSON -> kod bad_response', error.kod === 'bad_response' && error.status === 500);
}

console.log('\n' + '='.repeat(64));

if (failed === 0) {
  console.log('ZIELONE: ' + passed + ' asercji, 0 błędów.');
  process.exit(0);
}

console.log('CZERWONE: ' + passed + ' asercji OK, ' + failed + ' błędów.');
process.exit(1);
