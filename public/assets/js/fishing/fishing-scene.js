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

