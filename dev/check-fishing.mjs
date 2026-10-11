/**
 * dev/check-fishing.mjs — weryfikacja logiki komponentu <fishing-scene> bez przeglądarki.
 *
 * Uruchamianie:
 *   node dev/check-fishing.mjs                 testy czystej logiki
 *   node dev/check-fishing.mjs /tmp/cast.json  + walidacja PRAWDZIWEJ odpowiedzi api/cast.php
 *
 * Jak zdobyć payload do drugiego wariantu (serwer musi działać):
 *   curl -s -X POST http://localhost:8000/api/cast.php \
 *        -H "Authorization: Bearer <token>" -H 'Content-Type: application/json' \
 *        -d '{"lowisko_id":1}' > /tmp/cast.json
 *
 * Co jest sprawdzane (moduły bez DOM — dlatego logika siedzi w osobnych plikach):
 *   config.js    fazy, czasy, kąty wędziska, kolejność rzadkości
 *   timeline.js  lerp / lerpStops / easing / scaleTime / token anulowania / tween
 *   reel-lane.js skalowanie czasu parady (widełki laneMin..laneMax)
 *   api.js       kształt żądania POST cast.php i mapowanie błędów na `kod`
 *   contract.js  umowa reel[reel_indeks_wygranej] === zlowiona_ryba
 *
 * Kod wyjścia: 0 = zielone, 1 = coś nie przeszło.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

import { readFileSync } from 'node:fs';

import {
  BUTTON_TEXT,
  HARD_BITE_FROM,
  PHASES,
  PHASE_ORDER,
  RARITY_ORDER,
  ROD_ANGLES,
  SCENE,
  STATUS_TEXT,
  TIMINGS,
} from '../public/assets/js/fishing/config.js';
import {
  Easing,
  createToken,
  lerp,
  lerpStops,
  scaleTime,
  tween,
  wait,
} from '../public/assets/js/fishing/timeline.js';
import { ReelLane } from '../public/assets/js/fishing/reel-lane.js';
import { ApiError, createApi } from '../public/assets/js/fishing/api.js';
import {
  REQUIRED_FISH_FIELDS,
  isContractValid,
  reelWinnerMismatch,
  validateCastPayload,
} from '../public/assets/js/fishing/contract.js';

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

/** Uruchamia `fn` i sprawdza, czy rzuciło błędem o zadanym kodzie. */
async function throwsWith(name, fn, kod) {
  try {
    await fn();
    check(name, false, 'oczekiwano wyjątku, nic nie rzucono');
  } catch (error) {
    check(name, error.kod === kod,
      'kod=' + (error.kod || '?') + ' (oczekiwano ' + kod + ') msg=' + error.message);
  }
}

/* --- 1. config.js -------------------------------------------------------- */

console.log('\nconfig.js — fazy, czasy, geometria');

const phaseValues = Object.values(PHASES);

check('fazy są unikalne', new Set(phaseValues).size === phaseValues.length, phaseValues.join(','));
check('PHASE_ORDER to podzbiór PHASES i ma 7 kroków',
  PHASE_ORDER.length === 7 && PHASE_ORDER.every((phase) => phaseValues.includes(phase)));
check('każda faza ma etykietę przycisku',
  phaseValues.every((phase) => typeof BUTTON_TEXT[phase] === 'string' && BUTTON_TEXT[phase] !== ''));
check('status aria-live dla faz aktywnych',
  ['requesting', 'casting', 'waiting', 'bite', 'reeling']
    .every((phase) => String(STATUS_TEXT[phase] || '').length > 0));
check('kąty wędziska są liczbami', Object.values(ROD_ANGLES).every((angle) => Number.isFinite(angle)));
check('wędzisko: lift wyżej niż idle, bite niżej niż waiting',
  ROD_ANGLES.lift > ROD_ANGLES.idle && ROD_ANGLES.bite < ROD_ANGLES.waiting);
check('czasy faz dodatnie', Object.values(TIMINGS).every((value) => value > 0));
check('widełki paska spójne (laneMin < laneMax)', TIMINGS.laneMin < TIMINGS.laneMax);
check('rzadkości w kolejności z database/schema.sql',
  RARITY_ORDER.join(',') === 'pospolita,przecietna,niezla,mityczna,niesamowita,ultra');
check('próg mocnego brania istnieje w słowniku', RARITY_ORDER.includes(HARD_BITE_FROM));
check('geometria w zakresach 0..1',
  [SCENE.idleHang, SCENE.castArc, SCENE.heroLift, SCENE.bitePlunge]
    .every((value) => value > 0 && value < 1));
check('obiekty config są zamrożone',
  [PHASES, TIMINGS, ROD_ANGLES, SCENE, RARITY_ORDER].every((object) => Object.isFrozen(object)));

/* --- 2. timeline.js ------------------------------------------------------ */

console.log('\ntimeline.js — interpolacje, easing, tokeny');

check('lerp(0,10,0.5) = 5', lerp(0, 10, 0.5) === 5);
check('lerpStops: brzegi 0 i 20', lerpStops([0, 10, 20], 0) === 0 && lerpStops([0, 10, 20], 1) === 20);
check('lerpStops: środek = 10', lerpStops([0, 10, 20], 0.5) === 10);
check('lerpStops z pustej listy = 0', lerpStops([], 0.5) === 0);
check('easing: f(0)=0 i f(1)=1',
  Object.values(Easing).every((fn) => Math.abs(fn(0)) < 1e-9 && Math.abs(fn(1) - 1) < 1e-9));
check('easing monotoniczny w [0,1] (poza outBack)', Object.entries(Easing)
  .filter(([name]) => name !== 'outBack')
  .every(([, fn]) => {
    let previous = -Infinity;

    for (let i = 0; i <= 20; i += 1) {
      const value = fn(i / 20);

      if (value < previous - 1e-9) { return false; }

      previous = value;
    }

    return true;
  }));
check('scaleTime skraca przy reduced (x0.12)', scaleTime(1000, true) === 120 && scaleTime(1000, false) === 1000);
check('scaleTime nie daje wartości ujemnych', scaleTime(-50, false) === 0);

const preCancelled = createToken();

preCancelled.cancel();
check('token po cancel() ma cancelled=true', preCancelled.cancelled === true);
check('wait() na anulowanym tokenie zwraca false', (await wait(10, preCancelled)) === false);
check('wait(0) zwraca true', (await wait(0)) === true);

const interrupted = createToken();

setTimeout(() => interrupted.cancel(), 20);
check('wait() przerwany w trakcie zwraca false', (await wait(5000, interrupted)) === false);

// tween korzysta z requestAnimationFrame — w Node dokładamy minimalny stub.
globalThis.requestAnimationFrame = (callback) => setTimeout(() => callback(performance.now()), 0);
globalThis.cancelAnimationFrame = (handle) => clearTimeout(handle);

let lastT = -1;
const tweenOk = await tween(40, (t) => { lastT = t; });

check('tween dojeżdża do t=1', tweenOk === true && Math.abs(lastT - 1) < 1e-9, 'lastT=' + lastT);

let zeroValue = -1;

await tween(0, (t) => { zeroValue = t; });
check('tween(0) woła onUpdate(1)', zeroValue === 1, 'zeroValue=' + zeroValue);

const tweenToken = createToken();

setTimeout(() => tweenToken.cancel(), 15);
check('tween przerwany tokenem zwraca false', (await tween(3000, () => {}, { token: tweenToken })) === false);

const doubleToken = createToken();

doubleToken.onCancel(() => {});
doubleToken.cancel();
let afterCancel = false;

doubleToken.onCancel(() => { afterCancel = true; });
check('onCancel po anulowaniu odpala od razu', afterCancel === true);

/* --- 3. reel-lane.js ----------------------------------------------------- */

console.log('\nreel-lane.js — czas podwodnej parady');

const lane = new ReelLane({ lane: {}, track: {}, hook: null });

check('60 pozycji -> 5700 ms (w widełkach)', lane.durationFor(60) === 5700, String(lane.durationFor(60)));
check('4 pozycje -> dociągnięte do laneMin', lane.durationFor(4) === TIMINGS.laneMin);
check('200 pozycji -> przycięte do laneMax', lane.durationFor(200) === TIMINGS.laneMax);
check('reduced skraca paradę', lane.durationFor(60, true) === Math.round(5700 * TIMINGS.reducedScale));
check('brak długości -> wartość domyślna z widełek', lane.durationFor(undefined) >= TIMINGS.laneMin);

/* --- 4. api.js ----------------------------------------------------------- */

console.log('\napi.js — kształt żądania i mapowanie błędów');

let captured = null;

const fakeOk = (data) => async (...args) => {
  captured = { url: args[0], init: args[1] };

  return { ok: true, status: 200, json: async () => data };
};

const okApi = createApi({
  baseUrl: 'api/',
  getToken: () => 'TOK3N',
  fetchImpl: fakeOk({ success: true, zlowiona_ryba: { nazwa: 'x' } }),
});

await okApi.cast(6);

check('URL = api/cast.php', captured.url === 'api/cast.php', captured.url);
check('metoda POST', captured.init.method === 'POST');
check('nagłówek Authorization: Bearer', captured.init.headers.Authorization === 'Bearer TOK3N');
check('Content-Type: application/json', captured.init.headers['Content-Type'] === 'application/json');
check('body = {"lowisko_id":6}', captured.init.body === '{"lowisko_id":6}', captured.init.body);

const slashApi = createApi({
  baseUrl: '/api',
  getToken: () => null,
  fetchImpl: fakeOk({ success: true }),
});

await slashApi.spots();
check('baseUrl bez "/" i bez tokenu: URL=/api/spots.php, brak Authorization',
  captured.url === '/api/spots.php' && captured.init.headers.Authorization === undefined, captured.url);

const failApi = createApi({
  baseUrl: 'api/',
  getToken: () => 'T',
  fetchImpl: async () => ({
    ok: false,
    status: 400,
    json: async () => ({ success: false, error: 'Za mało monet.', kod: 'insufficient_funds' }),
  }),
});

await throwsWith('błąd API -> ApiError z kodem insufficient_funds', () => failApi.cast(1), 'insufficient_funds');

const badJsonApi = createApi({
  baseUrl: 'api/',
  getToken: () => 'T',
  fetchImpl: async () => ({ ok: false, status: 500, json: async () => { throw new Error('not json'); } }),
});

await throwsWith('nie-JSON -> kod bad_response', () => badJsonApi.cast(1), 'bad_response');

check('ApiError to Error z polem status', (() => {
  const error = new ApiError('x', 'kod', 401);

  return error instanceof Error && error.status === 401 && error.kod === 'kod';
})());

/* --- 5. contract.js ------------------------------------------------------ */

console.log('\ncontract.js — umowa reel[indeks] === zlowiona_ryba');

/** Poprawny payload w kształcie odpowiedzi api/cast.php. */
function fakePayload(overrides = {}) {
  const fish = {
    ekwipunek_id: 11,
    ryba_id: 7,
    nazwa: 'Jesiotr',
    rzadkosc: 'Niesamowita',
    rzadkosc_kod: 'niesamowita',
    kolor: '#eab308',
    wartosc: 400,
    ikona: 'assets/icons/sturgeon.png',
    szansa_procent: 1.0,
  };
  const reel = Array.from({ length: 60 }, (unused, index) => (index === 54
    ? { ryba_id: 7, nazwa: 'Jesiotr', ikona: fish.ikona, rzadkosc: 'Niesamowita',
      rzadkosc_kod: 'niesamowita', kolor: '#eab308', wartosc: 400 }
    : { ryba_id: 1, nazwa: 'Anchois', ikona: 'assets/icons/anchovy.png', rzadkosc: 'Pospolita',
      rzadkosc_kod: 'pospolita', kolor: '#94a3b8', wartosc: 3 }));

  return {
    success: true,
    rzut: { id: 5, koszt: 150 },
    lowisko: { id: 5, nazwa: 'Głębia', koszt_zarzutu: 150 },
    zlowiona_ryba: fish,
    saldo_przed: 500,
    saldo_po: 350,
    reel,
    reel_dlugosc: 60,
    reel_indeks_wygranej: 54,
    message: 'Złowiono: Jesiotr (Niesamowita)!',
    ...overrides,
  };
}

check('poprawny payload przechodzi walidację', validateCastPayload(fakePayload()).ryba_id === 7);
check('isContractValid = true dla poprawnego payloadu', isContractValid(fakePayload()) === true);
check('reelWinnerMismatch = null gdy zgodne', reelWinnerMismatch(fakePayload()) === null);

const mismatched = fakePayload();

mismatched.reel[54] = { ...mismatched.reel[54], ryba_id: 999 };
check('rozjazd ryba_id jest wykrywany', typeof reelWinnerMismatch(mismatched) === 'string');
check('rozjazd => isContractValid = false', isContractValid(mismatched) === false);

await throwsWith('brak payloadu -> bad_response', () => validateCastPayload(null), 'bad_response');
await throwsWith('brak zlowiona_ryba -> bad_response',
  () => validateCastPayload({ reel: [{}], reel_indeks_wygranej: 0 }), 'bad_response');

const noFields = fakePayload();

noFields.zlowiona_ryba = { ryba_id: 1 };
await throwsWith('niepełna ryba -> bad_response', () => validateCastPayload(noFields), 'bad_response');
check('lista wymaganych pól ryby jest pełna', REQUIRED_FISH_FIELDS.length === 7);
await throwsWith('pusty reel -> bad_response', () => validateCastPayload(fakePayload({ reel: [] })), 'bad_response');
await throwsWith('indeks poza zakresem -> bad_response',
  () => validateCastPayload(fakePayload({ reel_indeks_wygranej: 60 })), 'bad_response');
await throwsWith('indeks ujemny -> bad_response',
  () => validateCastPayload(fakePayload({ reel_indeks_wygranej: -1 })), 'bad_response');
await throwsWith('indeks niecałkowity -> bad_response',
  () => validateCastPayload(fakePayload({ reel_indeks_wygranej: 1.5 })), 'bad_response');

/* --- 6. prawdziwa odpowiedź api/cast.php (opcjonalnie) -------------------- */

const payloadPath = process.argv[2];

if (payloadPath) {
  console.log('\ncast.php — walidacja prawdziwej odpowiedzi: ' + payloadPath);

  let real = null;

  try {
    real = JSON.parse(readFileSync(payloadPath, 'utf8'));
  } catch (error) {
    check('plik daje się przeczytać jako JSON', false, error.message);
  }

  if (real) {
    check('success === true', real.success === true);

    try {
      validateCastPayload(real);
      check('validateCastPayload przechodzi', true);
    } catch (error) {
      check('validateCastPayload przechodzi', false, error.message);
    }

    check('reel[reel_indeks_wygranej] === zlowiona_ryba', reelWinnerMismatch(real) === null,
      String(reelWinnerMismatch(real)));
    check('reel.length === reel_dlugosc', Array.isArray(real.reel)
      && real.reel.length === Number(real.reel_dlugosc),
      (real.reel || []).length + ' vs ' + real.reel_dlugosc);
    check('indeks wygranej mieści się w pasku', Number.isInteger(real.reel_indeks_wygranej)
      && real.reel_indeks_wygranej >= 0 && real.reel_indeks_wygranej < (real.reel || []).length);
    check('ikona to ścieżka względna assets/icons/ albo pusta',
      /^(assets\/icons\/[\w.-]+\.png)?$/.test(String(real.zlowiona_ryba.ikona || '')),
      String(real.zlowiona_ryba.ikona));
    check('kolor rzadkości to hex', /^#[0-9a-fA-F]{6}$/.test(String(real.zlowiona_ryba.kolor || '')),
      String(real.zlowiona_ryba.kolor));
    check('rzadkosc_kod jest w słowniku frontendu',
      RARITY_ORDER.includes(String(real.zlowiona_ryba.rzadkosc_kod).toLowerCase()),
      String(real.zlowiona_ryba.rzadkosc_kod));
    check('saldo_przed i saldo_po są liczbami',
      typeof real.saldo_przed === 'number' && typeof real.saldo_po === 'number');
    check('czas parady dla tej długości mieści się w widełkach',
      lane.durationFor(Number(real.reel_dlugosc)) >= TIMINGS.laneMin
      && lane.durationFor(Number(real.reel_dlugosc)) <= TIMINGS.laneMax,
      lane.durationFor(Number(real.reel_dlugosc)) + ' ms');
    check('isContractValid = true', isContractValid(real) === true);

    const caught = real.zlowiona_ryba;

    console.log('\n  złowiono: ' + caught.nazwa + ' [' + caught.rzadkosc_kod + '] '
      + caught.wartosc + ' monet, szansa ' + Number(caught.szansa_procent).toFixed(3) + '%');
    console.log('  łowisko: ' + (real.lowisko ? real.lowisko.nazwa : '?')
      + ', koszt ' + (real.rzut ? real.rzut.koszt : '?')
      + ', saldo ' + real.saldo_przed + ' -> ' + real.saldo_po);
  }
} else {
  console.log('\n(pomięto walidację prawdziwej odpowiedzi — podaj ścieżkę JSON jako argument)');
}

/* --- podsumowanie -------------------------------------------------------- */

console.log('\n' + '='.repeat(64));

if (failed === 0) {
  console.log('ZIELONE: ' + passed + ' asercji, 0 błędów.');
  process.exit(0);
}

console.log('CZERWONE: ' + passed + ' asercji OK, ' + failed + ' błędów.');
process.exit(1);
