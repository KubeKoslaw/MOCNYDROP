/**
 * Animacja v1 (celowo prosta): panel „łowisko” z poziomym paskiem ikon rybek.
 * Losuje serwer — pasek (`payload.reel`) tylko przewija się i zatrzymuje na
 * `payload.reel_indeks_wygranej` (wygrana ląduje na środku), po czym pokazuje
 * się karta zdobyczy.
 *
 * Jak rozwijać do v2:
 *  - JS zna tylko fazy i timery (config.js) oraz pozycję paska (indeks, nie
 *    piksele); cały wygląd/animację opisuje CSS (`--fs-index`, `[data-phase]`),
 *  - nowy efekt = reguła CSS, nie kod JS; nie dodawaj liczenia geometrii,
 *  - trzymaj kontrakt: `<fishing-scene>`, select/cast/reset/play oraz zdarzenia
 *    phasechange/castrequest/catch/error (używają ich main.js i playground).
 */
import { BUTTON_TEXT, PHASES, STATUS_TEXT, TIMINGS } from './config.js';
import { ApiError, createApi } from './api.js';
import { buildStrip, stripTargetIndex, validateFishPayload } from './contract.js';

const STYLE_URL = new URL('../../css/fishing-scene.css', import.meta.url);
const TEMPLATE = `
<div class="fs-stage" data-phase="idle">
  <div class="fs-water"></div>
  <div class="fs-wave fs-wave--a"></div>
  <div class="fs-wave fs-wave--b"></div>
  <div class="fs-reel">
    <div class="fs-strip" data-ref="strip"></div>
    <div class="fs-marker" aria-hidden="true"></div>
  </div>
  <p class="fs-status" data-ref="status" role="status" aria-live="polite"></p>
  <div class="fs-controls">
    <button type="button" class="fs-btn fs-btn--cast" data-ref="castBtn">Zarzuć wędkę</button>
    <span class="fs-cost" data-ref="cost"></span>
  </div>
</div>
<div class="fs-reveal" data-ref="reveal" role="dialog" aria-modal="false" aria-label="Złowiona nagroda" hidden>
  <div class="fs-reveal-backdrop" data-ref="revealBackdrop"></div>
  <div class="fs-card" data-ref="card">
    <p class="fs-card-kicker" data-ref="cardKicker">Złowiono</p>
    <img class="fs-card-icon" data-ref="cardIcon" alt="">
    <h3 class="fs-card-name" data-ref="cardName"></h3>
    <p class="fs-card-rarity" data-ref="cardRarity"></p>
    <dl class="fs-card-stats">
      <div><dt>Wartość</dt><dd data-ref="cardValue"></dd></div>
      <div><dt>Pozostałe zarzuty</dt><dd data-ref="cardCasts"></dd></div>
    </dl>
    <div class="fs-card-actions">
      <button type="button" class="fs-btn" data-ref="againBtn">Zarzuć ponownie</button>
      <button type="button" class="fs-btn fs-btn--ghost" data-ref="closeBtn">Zamknij</button>
    </div>
  </div>
  <div class="fs-error" data-ref="error" hidden></div>
</div>
`;

/** Offline pula dla trybu `demo` (atrybut `demo`; używana w testach/prezentacjach). */
export const DEMO_FISH = Object.freeze([
  { przedmiot_id: 1, nazwa: 'Zielony Glon', rzadkosc: 'Pospolita', rzadkosc_kod: 'pospolita', kolor: '#94a3b8', wartosc: 1.0, ikona: 'assets/icons/green_seaweed.png', szansa_procent: 40 },
  { przedmiot_id: 2, nazwa: 'Anchois', rzadkosc: 'Pospolita', rzadkosc_kod: 'pospolita', kolor: '#94a3b8', wartosc: 3.0, ikona: 'assets/icons/anchovy.png', szansa_procent: 30 },
  { przedmiot_id: 3, nazwa: 'Płoć', rzadkosc: 'Przeciętna', rzadkosc_kod: 'przecietna', kolor: '#22c55e', wartosc: 8.0, ikona: 'assets/icons/roach.png', szansa_procent: 14 },
  { przedmiot_id: 4, nazwa: 'Jaź', rzadkosc: 'Przeciętna', rzadkosc_kod: 'przecietna', kolor: '#22c55e', wartosc: 11.0, ikona: 'assets/icons/chub.png', szansa_procent: 10 },
  { przedmiot_id: 5, nazwa: 'Okoń', rzadkosc: 'Niezła', rzadkosc_kod: 'niezla', kolor: '#3b82f6', wartosc: 22.0, ikona: 'assets/icons/largemouth_bass.png', szansa_procent: 9 },
  { przedmiot_id: 6, nazwa: 'Pstrąg Tęczowy', rzadkosc: 'Niezła', rzadkosc_kod: 'niezla', kolor: '#3b82f6', wartosc: 34.0, ikona: 'assets/icons/rainbow_trout.png', szansa_procent: 5 },
  { przedmiot_id: 7, nazwa: 'Złoty Karp', rzadkosc: 'Mityczna', rzadkosc_kod: 'mityczna', kolor: '#a855f7', wartosc: 120.0, ikona: 'assets/icons/golden_carp.png', szansa_procent: 4 },
  { przedmiot_id: 8, nazwa: 'Jesiotr', rzadkosc: 'Niesamowita', rzadkosc_kod: 'niesamowita', kolor: '#eab308', wartosc: 400.0, ikona: 'assets/icons/sturgeon.png', szansa_procent: 1 },
  { przedmiot_id: 9, nazwa: 'Ryba Otchłani', rzadkosc: 'ULTRA MEGA NIESAMOWITA', rzadkosc_kod: 'ultra', kolor: '#22d3ee', wartosc: 25000.0, ikona: 'assets/icons/void_fish.png', szansa_procent: 0.001 },
  { przedmiot_id: 10, nazwa: 'Cukierkowa Rybka', rzadkosc: 'Mityczna', rzadkosc_kod: 'mityczna', kolor: '#a855f7', wartosc: 90.0, ikona: 'assets/icons/candy_fish.png', szansa_procent: 3 },
]);

const HEX_COLOR = /^#[0-9a-f]{3,8}$/i;

let styleInjected = false;

function injectStyles() {
  if (styleInjected || typeof document === 'undefined') { return; }
  const linked = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
    .some((link) => String(link.getAttribute('href') || '').includes('fishing-scene.css'));

  if (!linked) {
    const link = document.createElement('link');
    link.rel = 'stylesheet'; link.href = STYLE_URL.href; link.dataset.fishingScene = '';
    document.head.appendChild(link);
  }
  styleInjected = true;
}

const money = (value) => Number(value || 0).toFixed(2).replace('.', ',');
const moneyOrDash = (value) => (value === undefined || value === null ? '—' : money(value) + ' monet');
const safeColor = (value) => (HEX_COLOR.test(String(value || '')) ? String(value) : '#22d3ee');

function castsLabel(count) {
  const n = Math.abs(Number(count) || 0);
  const last = n % 10;
  const lastTwo = n % 100;

  if (n === 1) { return '1 zarzut'; }
  if (last >= 2 && last <= 4 && (lastTwo < 12 || lastTwo > 14)) { return n + ' zarzuty'; }
  return n + ' zarzutów';
}

/** Ikona tylko ze znanego katalogu — payload z serwera nie wstrzyknie URL-a. */
function safeIcon(item) {
  const raw = String((item && item.ikona) || '');
  return /^assets\/icons\/[\w.-]+\.(png|svg)$/.test(raw) ? raw : '';
}

function reelItem(fish) {
  return {
    przedmiot_id: Number(fish.przedmiot_id),
    nazwa: String(fish.nazwa || ''),
    rzadkosc: String(fish.rzadkosc || ''),
    rzadkosc_kod: String(fish.rzadkosc_kod || ''),
    kolor: safeColor(fish.kolor),
    wartosc: Number(fish.wartosc || 0),
    ikona: String(fish.ikona || ''),
  };
}

/** Payload w kształcie api/fish.php (z lokalnym `reel`) — tryb demo/offline. */
export function buildDemoPayload(fish = null) {
  const winner = fish || DEMO_FISH[Math.floor(Math.random() * DEMO_FISH.length)];
  const length = 40;
  const winnerIndex = 8 + Math.floor(Math.random() * 24);
  const reel = Array.from({ length }, () => reelItem(
    DEMO_FISH[Math.floor(Math.random() * (DEMO_FISH.length - 1))] || DEMO_FISH[0]
  ));

  reel[winnerIndex] = reelItem(winner);

  return {
    success: true,
    rzut: { id: 0, koszt: 10 },
    wedka: { id: 0, nazwa: 'Wędka demo', koszt_uzycia: 10, zarzuty_pozostale: 9 },
    przedmiot: { ekwipunek_id: 0, ...winner },
    saldo_przed: 1000,
    saldo_po: 990,
    reel,
    reel_dlugosc: length,
    reel_indeks_wygranej: winnerIndex,
    message: 'Złowiono: ' + winner.nazwa + ' (' + winner.rzadkosc + ')!',
  };
}

export class FishingScene extends HTMLElement {
  static get observedAttributes() {
    return ['rod-id', 'rod-name', 'cost', 'casts', 'label', 'demo', 'reduced', 'api-base', 'token-key'];
  }

  #api = null; #busy = false; #phase = PHASES.IDLE; #rodId = null; #reduced = false;
  #run = null; #runPromise = null; #timer = null; #timerResolve = null;
  #spinTimer = null; #spinDone = null; #closeWaiter = null;

  constructor() {
    super();
    this.refs = null;
    this.stage = null;
  }

  connectedCallback() {
    if (!this.refs) {
      this.innerHTML = TEMPLATE;
      this.stage = this.querySelector('.fs-stage');
      this.refs = FishingScene.#collectRefs(this);
      this.#bindEvents();
    }
    injectStyles();
    this.#reduced = this.hasAttribute('reduced')
      || Boolean(typeof window !== 'undefined' && window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    this.#api = this.#createApi(); this.#syncLabels(); this.#fillIdlePreview(); this.#setPhase(PHASES.IDLE);
  }

  disconnectedCallback() {
    this.#abort(); this.#busy = false; this.#run = null; this.#runPromise = null;
  }

  attributeChangedCallback(name, oldValue, newValue) {
    if (oldValue === newValue || !this.refs) { return; }
    if (name === 'reduced') { this.#reduced = this.hasAttribute('reduced'); }
    if (name === 'api-base' || name === 'token-key') { this.#api = this.#createApi(); }
    this.#syncLabels();
  }

  #createApi() {
    const base = this.getAttribute('api-base');
    return createApi({ baseUrl: base === null ? 'api/' : base, tokenKey: this.getAttribute('token-key') || undefined });
  }

  static #collectRefs(root) {
    const refs = {};
    root.querySelectorAll('[data-ref]').forEach((element) => {
      refs[element.dataset.ref.replace(/-([a-z])/g, (m, letter) => letter.toUpperCase())] = element;
    }); return refs;
  }

  #bindEvents() {
    this.refs.castBtn.addEventListener('click', () => this.#onCastClick());
    this.refs.closeBtn.addEventListener('click', () => this.#closeReveal());
    this.refs.revealBackdrop.addEventListener('click', () => this.#closeReveal());
    this.refs.againBtn.addEventListener('click', () => this.#again());
    this.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') { this.#closeReveal(); }
    });
  }

  #syncLabels() {
    if (!this.refs) { return; }
    const label = this.getAttribute('label');
    const name = this.getAttribute('rod-name');
    const cost = this.getAttribute('cost');
    const casts = this.getAttribute('casts');

    if (label && this.#phase === PHASES.IDLE) { this.refs.castBtn.textContent = label; }
    this.refs.cost.replaceChildren();

    if (name) {
      const strong = document.createElement('strong');
      strong.textContent = name;
      this.refs.cost.appendChild(strong);
    }
    if (casts !== null) {
      this.refs.cost.appendChild(document.createTextNode((name ? ' • ' : '') + castsLabel(casts)));
    } else if (cost) {
      this.refs.cost.appendChild(document.createTextNode((name ? ' • ' : '') + money(cost) + ' monet za zarzut'));
    }
  }

  #setPhase(phase) {
    this.#phase = phase;
    this.stage.dataset.phase = phase;
    this.refs.status.textContent = STATUS_TEXT[phase] || '';

    const label = this.getAttribute('label');

    this.refs.castBtn.textContent = (phase === PHASES.IDLE && label)
      ? label
      : (BUTTON_TEXT[phase] || BUTTON_TEXT.idle);
    this.refs.castBtn.disabled = phase !== PHASES.IDLE;
    this.dispatchEvent(new CustomEvent('phasechange', { detail: { phase }, bubbles: true }));
  }

  get phase() { return this.#phase; }
  get busy() { return this.#busy; }
  get rodId() { return this.#rodId; }
  get reduced() { return this.#reduced; }
  get demoMode() { return this.hasAttribute('demo'); }

  select(rodId, meta = {}) {
    this.#rodId = (rodId === null || rodId === undefined || rodId === '') ? null : Number(rodId);
    if (meta.nazwa !== undefined) { this.setAttribute('rod-name', String(meta.nazwa)); }
    if (meta.koszt !== undefined) { this.setAttribute('cost', String(meta.koszt)); }
    if (meta.zarzuty !== undefined) { this.setAttribute('casts', String(meta.zarzuty)); }
    if (this.#rodId !== null) { this.setAttribute('rod-id', String(this.#rodId)); }
    this.#syncLabels();
    return this;
  }

  /** Losowanie z serwera: POST api/fish.php, potem pasek i karta zdobyczy. */
  async cast(rodId = this.#rodId) {
    const id = Number(rodId);

    if (!Number.isFinite(id)) {
      const error = new ApiError('Nie wybrano wędki.', 'missing_field', 0);
      this.#fail(error);
      throw error;
    }

    return this.#start(async (run) => {
      this.#setPhase(PHASES.REQUESTING);
      const payload = await this.#api.cast(id);
      if (run.cancelled) { return null; }
      return this.#runSequence(payload, run);
    });
  }

  /** Tryb demo/offline: payload z zewnątrz, bez backendu. */
  async play(payload) {
    return this.#start(async (run) => {
      this.#setPhase(PHASES.REQUESTING);
      return this.#runSequence(payload, run);
    });
  }

  reset() {
    this.#abort(); this.#busy = false; this.#run = null; this.#runPromise = null; this.#resetToIdle();
  }

  #start(runner) {
    if (this.#busy) { return Promise.resolve(null); }
    const run = { cancelled: false };
    this.#run = run; this.#busy = true;
    this.#runPromise = (async () => {
      try {
        return await runner(run);
      } catch (error) {
        if (!run.cancelled) { this.#fail(error); }
        throw error;
      } finally {
        if (this.#run === run) {
          this.#busy = false; this.#run = null; this.#runPromise = null; this.#clearTimer();
        }
      }
    })();
    return this.#runPromise;
  }

  #abort() {
    if (this.#run) { this.#run.cancelled = true; }
    this.#clearTimer();
    if (this.#spinDone) { this.#spinDone(); }
    const waiter = this.#closeWaiter;
    this.#closeWaiter = null;
    if (waiter) { waiter(); }
  }

  #clearTimer() {
    if (this.#timer !== null) { clearTimeout(this.#timer); this.#timer = null; }
    const cancel = this.#timerResolve;
    this.#timerResolve = null;
    if (cancel) { cancel(); }
    if (this.#spinTimer !== null) { clearTimeout(this.#spinTimer); this.#spinTimer = null; }
  }

  #scale(ms) {
    return Math.round(ms * (this.#reduced ? TIMINGS.reducedScale : 1));
  }

  #delay(ms) {
    return new Promise((resolve) => {
      const run = this.#run;
      if (!run || run.cancelled) { resolve(false); return; }
      this.#timerResolve = () => resolve(false);
      this.#timer = setTimeout(() => {
        this.#timer = null; this.#timerResolve = null; resolve(!run.cancelled);
      }, this.#scale(ms));
    });
  }

  /**
   * Statyczne rybki w pasku — scena nie jest pusta przed pierwszym rzutem
   * (prosta dekoracja; prawdziwy pasek podmienia się przy losowaniu).
   */
  #fillIdlePreview() {
    this.#buildStrip(DEMO_FISH.slice(0, 8));
    this.refs.strip.classList.add('is-idle');
  }

  #spinDuration() {
    const base = TIMINGS.spinMin + Math.random() * (TIMINGS.spinMax - TIMINGS.spinMin);
    return Math.round(base * (this.#reduced ? TIMINGS.reducedScale : 1));
  }

  #buildStrip(reel) {
    const strip = this.refs.strip;

    strip.replaceChildren();
    strip.classList.remove('is-spinning');
    strip.classList.remove('is-idle');
    strip.style.setProperty('--fs-index', '0');

    buildStrip(reel).forEach((item) => {
      const cell = document.createElement('div');
      const icon = safeIcon(item);
      const name = document.createElement('span');

      cell.className = 'fs-item';
      cell.style.setProperty('--item-color', safeColor(item.kolor));
      if (icon !== '') {
        const img = document.createElement('img');
        img.src = icon;
        img.alt = '';
        cell.appendChild(img);
      }
      name.textContent = String(item.nazwa || '');
      cell.appendChild(name);
      strip.appendChild(cell);
    });
  }

  #startSpin(reelLength, winnerIndex, duration) {
    const strip = this.refs.strip;

    strip.style.setProperty('--fs-spin-duration', duration + 'ms');
    void strip.offsetWidth;
    strip.classList.add('is-spinning');
    strip.style.setProperty('--fs-index', String(stripTargetIndex(reelLength, winnerIndex)));
  }

  #waitSpin(run, duration) {
    return new Promise((resolve) => {
      const strip = this.refs.strip;
      const done = () => {
        if (this.#spinDone !== done) { return; }
        this.#spinDone = null;
        if (this.#spinTimer !== null) { clearTimeout(this.#spinTimer); this.#spinTimer = null; }
        strip.removeEventListener('transitionend', onEnd);
        resolve();
      };
      const onEnd = (event) => {
        if (event.target === strip && event.propertyName === 'transform') { done(); }
      };

      if (run.cancelled) { resolve(); return; }
      this.#spinDone = done;
      strip.addEventListener('transitionend', onEnd);
      this.#spinTimer = setTimeout(done, duration + 160);
    });
  }

  async #runSequence(payload, run) {
    const fish = validateFishPayload(payload);

    // W trybie demo nie ma żądania do API, więc pomijamy sztuczne „requesting”
    // (dzięki temu `?auto=1` od razu pokazuje kręcący się pasek).
    if (!this.demoMode) {
      if (!(await this.#delay(TIMINGS.requesting))) { return null; }
      if (run.cancelled) { return null; }
    }

    this.#buildStrip(payload.reel);
    this.#setPhase(PHASES.SPINNING);

    const duration = this.#spinDuration();

    this.#startSpin(payload.reel.length, payload.reel_indeks_wygranej, duration);

    await this.#waitSpin(run, duration);

    if (run.cancelled) { return null; }

    return this.#reveal(payload, fish, run);
  }

  async #reveal(payload, fish, run) {
    this.#fillCard(payload, fish);
    this.refs.error.hidden = true;
    this.refs.card.hidden = false;
    this.refs.reveal.hidden = false;
    this.#setPhase(PHASES.REVEAL);
    this.refs.closeBtn.focus();
    this.dispatchEvent(new CustomEvent('catch', { detail: { payload, fish }, bubbles: true }));

    await this.#waitClose(run);
    if (run.cancelled) { return null; }

    this.#resetToIdle();
    return payload;
  }

  #waitClose(run) {
    return new Promise((resolve) => {
      if (run.cancelled || !this.#busy) { resolve(); return; }
      this.#closeWaiter = () => {
        this.#closeWaiter = null;
        resolve();
      };
    });
  }

  #closeReveal() {
    this.refs.reveal.hidden = true;
    const waiter = this.#closeWaiter;
    this.#closeWaiter = null;
    if (waiter) { waiter(); return; }
    if (!this.#busy) { this.#resetToIdle(); }
  }

  async #again() {
    const running = this.#runPromise;
    const rodId = this.#rodId;

    this.#closeReveal();
    if (running) {
      try { await running; } catch (error) { /* wynik pokazał się w karcie */ }
    }

    if (this.demoMode) { this.play(buildDemoPayload()).catch(() => {}); return; }
    if (rodId === null) { this.#onCastClick(); return; }
    this.cast(rodId).catch(() => {});
  }

  #onCastClick() {
    if (this.#busy || this.#phase !== PHASES.IDLE) { return; }
    if (this.demoMode) { this.play(buildDemoPayload()).catch(() => {}); return; }

    if (this.#rodId === null) {
      this.dispatchEvent(new CustomEvent('castrequest', { detail: { rodId: null }, bubbles: true }));
      return;
    }

    this.cast(this.#rodId).catch(() => {});
  }

  #resetToIdle() {
    this.refs.reveal.hidden = true;
    this.refs.error.hidden = true;
    this.refs.card.hidden = false;
    this.#fillIdlePreview();
    this.#setPhase(PHASES.IDLE);
  }

  #fillCard(payload, fish) {
    const refs = this.refs;
    const color = safeColor(fish.kolor);
    const icon = safeIcon(fish);
    const casts = payload.wedka ? payload.wedka.zarzuty_pozostale : undefined;

    refs.card.style.setProperty('--fs-rarity', color);
    refs.cardKicker.textContent = payload.wedka && payload.wedka.nazwa ? 'Złowiono • ' + payload.wedka.nazwa : 'Złowiono';
    refs.cardIcon.src = icon;
    refs.cardIcon.hidden = icon === '';
    refs.cardIcon.alt = icon === '' ? '' : String(fish.nazwa || '');
    refs.cardName.textContent = String(fish.nazwa || '');
    refs.cardRarity.textContent = String(fish.rzadkosc || '');
    refs.cardValue.textContent = moneyOrDash(fish.wartosc);
    refs.cardCasts.textContent = casts === undefined ? '—' : castsLabel(casts);

    if (casts !== undefined) { this.setAttribute('casts', String(casts)); }
  }

  #fail(error) {
    const kod = (error && error.kod) || 'error';
    const message = (error && error.message) || 'Nieznany błąd.';
    const box = this.refs.error;
    const close = document.createElement('button');

    this.#setPhase(PHASES.ERROR);
    box.replaceChildren(document.createTextNode(message + ' '));
    close.type = 'button'; close.className = 'fs-btn fs-btn--ghost'; close.textContent = 'Zamknij';
    close.addEventListener('click', () => this.#closeReveal());
    box.appendChild(close);
    this.refs.card.hidden = true;
    box.hidden = false; this.refs.reveal.hidden = false;
    this.dispatchEvent(new CustomEvent('error', {
      detail: { kod, message, status: (error && error.status) || 0 },
      bubbles: true,
    }));
  }
}

if (typeof customElements !== 'undefined' && !customElements.get('fishing-scene')) {
  customElements.define('fishing-scene', FishingScene);
}
