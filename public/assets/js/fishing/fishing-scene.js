/**
 * public/assets/js/fishing/fishing-scene.js
 *
 * <fishing-scene> — komponent sceny łowienia zastępujący animację otwierania
 * skrzynki (poziomy pasek ruletki).
 *
 * Przebieg stanów:
 *   idle -> requesting -> casting -> waiting -> bite -> reeling -> reveal
 *
 * Kontrakt z backendem (bez zmian w logice losowania):
 *   1. najpierw POST api/fish.php i CZEKAMY na odpowiedź,
 *   2. potem animacja: pod wodą przewija się `reel` i zatrzymuje dokładnie na
 *      `reel_indeks_wygranej` — to jest moment brania,
 *   3. dopiero po braniu wyciągamy `przedmiot` i pokazujemy kartę,
 *   4. saldo aktualizujemy z `saldo_po`.
 *
 * W przeglądarce nie ma ani jednego losowania wyniku — `Math.random()` używamy
 * wyłącznie do kropel plusku i iskier (czysta dekoracja).
 *
 * Użycie:
 *   <fishing-scene api-base="api/" show-lane></fishing-scene>
 *   scene.select(3, { nazwa: 'Głębia', koszt: 150 });
 *   await scene.cast();               // albo scene.play(payload) z mocka
 *   scene.addEventListener('catch', (e) => pokazSaldo(e.detail.saldo_po));
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

import {
  BUTTON_TEXT,
  HARD_BITE_FROM,
  PHASES,
  RARITY_ORDER,
  ROD_ANGLES,
  SCENE,
  STATUS_TEXT,
  TIMINGS,
} from './config.js';
import {
  Easing,
  createToken,
  lerp,
  lerpStops,
  prefersReducedMotion,
  scaleTime,
  tween,
  wait,
} from './timeline.js';
import { ReelLane, safeIconUrl } from './reel-lane.js';
import { ApiError, createApi } from './api.js';
import { reelWinnerMismatch, validateFishPayload } from './contract.js';

/**
 * Szablon sceny. Celowo jako string + innerHTML: komponent nie ma zależności
 * ani builda, a struktura jest stała. Dane z API nigdy nie trafiają do tego
 * szablonu — nazwy ryb ustawiamy przez textContent (odporność na XSS).
 *
 * `data-ref` to kotwice, po które sięga JS (querySelector w `#$refs()`).
 */
const TEMPLATE = `
<div class="fs-stage" data-phase="idle">
  <div class="fs-sky"></div>
  <div class="fs-sun"></div>
  <div class="fs-hills"></div>

  <div class="fs-water">
    <div class="fs-wave fs-wave--a"></div>
    <div class="fs-wave fs-wave--b"></div>

    <!-- podwodny pasek ruletki: pole "reel" z api/fish.php (w tym komentarzu
         nie uzywamy backtickow - jestesmy wewnatrz template literal) -->
    <div class="fs-lane" data-ref="lane" aria-hidden="true">
      <div class="fs-lane-track" data-ref="lane-track"></div>
      <div class="fs-lane-hook" data-ref="lane-hook"></div>
      <div class="fs-lane-mask fs-lane-mask--left"></div>
      <div class="fs-lane-mask fs-lane-mask--right"></div>
    </div>

    <div class="fs-bubbles" data-ref="bubbles"></div>
  </div>

  <div class="fs-waterline"></div>
  <div class="fs-bank" aria-hidden="true"></div>

  <!-- żyłka: SVG w procentach sceny (viewBox 0 0 100 100) -->
  <svg class="fs-line-svg" viewBox="0 0 100 100" preserveAspectRatio="none"
       aria-hidden="true" focusable="false">
    <path class="fs-line" data-ref="line" d="" vector-effect="non-scaling-stroke"></path>
  </svg>

  <div class="fs-angler" aria-hidden="true">
    <svg viewBox="0 0 100 130" focusable="false">
      <path d="M52 44 C40 52 38 74 44 92 L70 92 C76 72 74 52 64 44 Z" fill="#0e1f33"></path>
      <circle cx="58" cy="30" r="12" fill="#0e1f33"></circle>
      <path d="M41 25 Q58 7 75 25 Z" fill="#16334f"></path>
      <rect x="33" y="24" width="50" height="4.6" rx="2.3" fill="#16334f"></rect>
      <path d="M54 50 L34 16" stroke="#0e1f33" stroke-width="8" stroke-linecap="round"></path>
      <path d="M63 52 L45 27" stroke="#0b1a2b" stroke-width="7" stroke-linecap="round"></path>
      <path d="M47 90 L41 122" stroke="#0e1f33" stroke-width="11" stroke-linecap="round"></path>
      <path d="M66 90 L72 122" stroke="#0b1a2b" stroke-width="11" stroke-linecap="round"></path>
      <rect x="29" y="118" width="23" height="8.5" rx="4.2" fill="#08111d"></rect>
      <rect x="61" y="118" width="23" height="8.5" rx="4.2" fill="#08111d"></rect>
    </svg>
  </div>

  <div class="fs-rod" data-ref="rod" aria-hidden="true"></div>
  <div class="fs-splash" data-ref="splash" aria-hidden="true"></div>

  <div class="fs-float" data-ref="float">
    <div class="fs-float-bob">
      <span class="fs-bobber-antenna"></span>
      <span class="fs-bobber-body"></span>
      <span class="fs-bobber-keel"></span>
    </div>
  </div>

  <div class="fs-catch" data-ref="catch" aria-hidden="true">
    <div class="fs-catch-glow"></div>
    <img class="fs-catch-img" data-ref="catch-img" alt="">
    <div class="fs-drips" data-ref="drips"></div>
  </div>

  <p class="fs-status" data-ref="status" role="status" aria-live="polite"></p>

  <div class="fs-controls">
    <button type="button" class="fs-btn fs-btn--cast" data-ref="cast-btn">Zarzuć wędkę</button>
    <span class="fs-cost" data-ref="cost"></span>
  </div>
</div>

<div class="fs-reveal" data-ref="reveal" role="dialog" aria-modal="false"
     aria-label="Złowiona nagroda" hidden>
  <div class="fs-reveal-backdrop" data-ref="reveal-backdrop"></div>
  <div class="fs-sparkles" data-ref="sparkles" aria-hidden="true"></div>

  <div class="fs-card" data-ref="card">
    <p class="fs-card-kicker" data-ref="card-kicker">Złowiono</p>
    <img class="fs-card-icon" data-ref="card-icon" alt="">
    <h3 class="fs-card-name" data-ref="card-name"></h3>
    <p class="fs-card-rarity" data-ref="card-rarity"></p>
    <dl class="fs-card-stats">
      <div><dt>Wartość</dt><dd data-ref="card-value"></dd></div>
      <div><dt>Szansa</dt><dd data-ref="card-chance"></dd></div>
      <div><dt>Koszt zarzutu</dt><dd data-ref="card-cost"></dd></div>
      <div><dt>Saldo</dt><dd data-ref="card-balance"></dd></div>
    </dl>
    <div class="fs-card-actions">
      <button type="button" class="fs-btn" data-ref="again-btn">Zarzuć ponownie</button>
      <button type="button" class="fs-btn fs-btn--ghost" data-ref="close-btn">Zamknij</button>
    </div>
  </div>

  <div class="fs-error" data-ref="error" hidden></div>
</div>
`;

/** Ścieżka CSS względem TEGO modułu — działa z dowolnego docroota i z <base>. */
const STYLE_URL = new URL('../../css/fishing-scene.css', import.meta.url);

let styleInjected = false;

/**
 * Dokłada arkusz stylu raz na dokument. Strona może też podpiąć CSS samodzielnie
 * (<link href="public/assets/css/fishing-scene.css">) — wtedy nic nie dublujemy.
 */
function injectStyles() {
  if (styleInjected || typeof document === 'undefined') { return; }

  const alreadyLinked = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
    .some((link) => String(link.getAttribute('href') || '').includes('fishing-scene.css'));

  if (!alreadyLinked) {
    const link = document.createElement('link');

    link.rel = 'stylesheet';
    link.href = STYLE_URL.href;
    link.dataset.fishingScene = '';
    document.head.appendChild(link);
  }

  styleInjected = true;
}

/** Format kwot jak w reszcie projektu: 1234.5 -> "1234,50". */
function money(value) {
  return Number(value || 0).toFixed(2).replace('.', ',');
}

/** Szansa z API ma dużo miejsc po przecinku (wagi ultra to 0.001). */
function percent(value) {
  return Number(value || 0).toFixed(3) + '%';
}

/**
 * Komponent sceny łowienia.
 *
 * Zdarzenia:
 *   `phasechange` { phase }         — każda zmiana stanu (HUD / debugowanie)
 *   `catch`       { payload, fish } — po wyłowieniu (tu strona aktualizuje saldo)
 *   `error`       { kod, message }  — błąd API albo walidacji payloadu
 *   `castrequest` { rodId }        — klik w przycisk bez wybranej wędki
 */
export class FishingScene extends HTMLElement {
  static get observedAttributes() {
    return ['rod-id', 'rod-name', 'cost', 'label', 'reduced', 'show-lane', 'api-base', 'token-key'];
  }

  /* Pola prywatne muszą być zadeklarowane — przypisanie do niezadeklarowanego
     `#pole` skończyłoby się TypeErrorem w runtime. */
  #token = null;
  #busy = false;
  #runPromise = null;
  #phase = PHASES.IDLE;
  #rodId = null;
  #closeWaiter = null;
  #resizeObserver = null;
  #reduced = false;

  constructor() {
    super();

    this.refs = null;
    this.stage = null;
    this.lane = null;
    this.api = null;
    this.geom = null;
  }

  /* --- cykl życia ------------------------------------------------------ */

  connectedCallback() {
    if (!this.refs) {
      this.innerHTML = TEMPLATE;
      this.stage = this.querySelector('.fs-stage');
      this.refs = FishingScene.#collectRefs(this);
      this.#bindEvents();
    }

    injectStyles();

    this.#reduced = this.hasAttribute('reduced') || prefersReducedMotion();
    this.api = this.#createApi();

    this.lane = new ReelLane({
      lane: this.refs.lane,
      track: this.refs.laneTrack,
      hook: this.refs.laneHook,
    });

    this.#buildBubbles();
    this.#measure();
    this.#syncLabels();
    this.#setPhase(PHASES.IDLE);

    // Scena musi przeliczać geometrię po zmianie rozmiaru (responsywność,
    // obrót telefonu) — inaczej żyłka odjechałaby od końca wędziska.
    if (typeof ResizeObserver === 'function') {
      this.#resizeObserver = new ResizeObserver(this.#onResize);
      this.#resizeObserver.observe(this);
    } else {
      window.addEventListener('resize', this.#onResize);
    }
  }

  disconnectedCallback() {
    this.#cancel();

    if (this.#resizeObserver) {
      this.#resizeObserver.disconnect();
      this.#resizeObserver = null;
    } else {
      window.removeEventListener('resize', this.#onResize);
    }
  }

  attributeChangedCallback(name, oldValue, newValue) {
    if (oldValue === newValue || !this.refs) { return; }

    if (name === 'reduced') {
      this.#reduced = this.hasAttribute('reduced') || prefersReducedMotion();
    }

    if (name === 'api-base' || name === 'token-key') {
      this.api = this.#createApi();
    }

    this.#syncLabels();
  }

  #createApi() {
    const base = this.getAttribute('api-base');

    return createApi({
      baseUrl: base === null ? 'api/' : base,
      tokenKey: this.getAttribute('token-key') || undefined,
    });
  }

  /** `data-ref="lane-track"` -> refs.laneTrack (camelCase, bez szukania w locie). */
  static #collectRefs(root) {
    const refs = {};

    root.querySelectorAll('[data-ref]').forEach((element) => {
      const key = element.dataset.ref.replace(/-([a-z])/g, (match, letter) => letter.toUpperCase());
      refs[key] = element;
    });

    return refs;
  }

  #onResize = () => {
    this.#measure();

    // Po zmianie rozmiaru odtwarzamy bieżącą pozycję statyczną (idle/reveal),
    // żeby żyłka i spławik nie zostały w starych pikselach.
    if (!this.#busy) { this.#applyPose(this.#idlePose()); }
  };

  /* --- geometria ------------------------------------------------------- */

  /**
   * Mierzy scenę. Wszystko bierzemy z layoutu (offsetLeft/offsetWidth), więc
   * geometria jest w całości sterowana z CSS przez @THOPA174:
   *   --fs-pivot-x/--fs-pivot-y -> dłonie wędkarza (koniec wędziska),
   *   --fs-rod-len              -> długość wędziska,
   *   --fs-cast-x/--fs-water-y  -> gdzie ląduje spławik (= gdzie stoi haczyk).
   */
  #measure() {
    if (!this.stage) { return; }

    const rod = this.refs.rod;
    const floatElement = this.refs.float;
    const width = this.stage.clientWidth;
    const height = this.stage.clientHeight;

    // Scena ukryta (display:none) — pomiar wróci z ResizeObserver.
    if (width === 0 || height === 0) { return; }

    this.geom = {
      w: width,
      h: height,
      // prawy koniec wędziska = dłonie wędkarza (transform-origin: right center)
      pivotX: rod.offsetLeft + rod.offsetWidth,
      pivotY: rod.offsetTop + rod.offsetHeight / 2,
      rodLen: rod.offsetWidth,
      // "home" spławika: miejsce wodowania, jednocześnie oś haczyka i linia wody
      homeX: floatElement.offsetLeft,
      homeY: floatElement.offsetTop,
    };
  }

  /**
   * Pozycja przelotki (końca wędziska) dla kąta w stopniach.
   * Wędzisko ma `transform-origin: right center`, więc jego koniec leży
   * w kierunku (-cos(a), -sin(a)) od dłoni — patrz ROD_ANGLES w config.js.
   */
  #tip(angleDeg) {
    const g = this.geom;
    const rad = (angleDeg * Math.PI) / 180;

    return {
      x: g.pivotX - Math.cos(rad) * g.rodLen,
      y: g.pivotY - Math.sin(rad) * g.rodLen,
    };
  }

  /** Rysuje żyłkę jako krzywą z zwisem (sag), we współrzędnych SVG 0..100. */
  #drawLine(from, to, sag) {
    const g = this.geom;

    if (!g) { return; }

    const ux = (value) => ((value / g.w) * 100).toFixed(3);
    const uy = (value) => ((value / g.h) * 100).toFixed(3);
    const distance = Math.hypot(to.x - from.x, to.y - from.y);
    const cx = (from.x + to.x) / 2;
    const cy = (from.y + to.y) / 2 + distance * sag;

    this.refs.line.setAttribute(
      'd',
      'M ' + ux(from.x) + ' ' + uy(from.y)
      + ' Q ' + ux(cx) + ' ' + uy(cy)
      + ' ' + ux(to.x) + ' ' + uy(to.y)
    );
  }

  /* --- pozy sceny --------------------------------------------------------
     "Poza" to jeden obiekt opisujący wszystko, co zmienia się w klatce:
     kąt wędziska, offset spławika, zwis żyłki i pozycję/skalę ryby.
     Dzięki temu tween między dwiema fazami jest interpolacją jednego obiektu
     i żyłka NIGDY nie odjeżdża od wędziska ani od spławika.                 */

  /** Spławik wisi pod przelotką (stan przed zarzutem / po resecie). */
  #hangPose(angle, sag = SCENE.sag.idle) {
    const g = this.geom;
    const tip = this.#tip(angle);
    const hangY = tip.y + g.h * SCENE.idleHang;

    return {
      angle,
      floatX: tip.x - g.homeX,
      floatY: hangY - g.homeY,
      sag,
      attach: 'float',
      catchX: tip.x,
      catchY: hangY,
      catchScale: 0.6,
      catchOpacity: 0,
    };
  }

  #idlePose() {
    return this.#hangPose(ROD_ANGLES.idle);
  }

  /** Spławik na wodzie, wędzisko w górze — faza oczekiwania na branie. */
  #waitingPose() {
    const g = this.geom;

    return {
      angle: ROD_ANGLES.waiting,
      floatX: 0,
      floatY: 0,
      sag: SCENE.sag.waiting,
      attach: 'float',
      catchX: g.homeX,
      catchY: g.homeY,
      catchScale: 0.55,
      catchOpacity: 0,
    };
  }

  /** Punkt, w którym ryba zatrzymuje się nad wodą (na żyłce, pod przelotką). */
  #heroPoint(angle, lift = SCENE.heroLift) {
    const g = this.geom;
    const tip = this.#tip(angle);

    return {
      x: lerp(g.homeX, tip.x, lift),
      y: lerp(g.homeY, tip.y, lift),
    };
  }

  #applyPose(pose) {
    const g = this.geom;

    if (!g) { return; }

    this.refs.rod.style.setProperty('--fs-rod-angle', pose.angle.toFixed(2) + 'deg');
    this.refs.float.style.transform =
      'translate3d(' + pose.floatX.toFixed(2) + 'px,' + pose.floatY.toFixed(2) + 'px,0)';

    const tip = this.#tip(pose.angle);
    const end = pose.attach === 'catch'
      ? { x: pose.catchX, y: pose.catchY }
      : { x: g.homeX + pose.floatX, y: g.homeY + pose.floatY };

    this.#drawLine(tip, end, pose.sag);

    const catchElement = this.refs.catch;

    catchElement.style.transform = 'translate3d(' + pose.catchX.toFixed(2) + 'px,'
      + pose.catchY.toFixed(2) + 'px,0) scale(' + pose.catchScale.toFixed(3) + ')';
    catchElement.style.opacity = pose.catchOpacity.toFixed(3);
    catchElement.classList.toggle('is-visible', pose.catchOpacity > 0.02);
  }

  /** Interpolacja dwóch póz — serce wszystkich przejść między fazami. */
  #lerpPose(a, b, t) {
    return {
      angle: lerp(a.angle, b.angle, t),
      floatX: lerp(a.floatX, b.floatX, t),
      floatY: lerp(a.floatY, b.floatY, t),
      sag: lerp(a.sag, b.sag, t),
      // przełączenie żyłki na rybę w połowie fazy (np. przy wyciąganiu)
      attach: t < 0.5 ? a.attach : b.attach,
      catchX: lerp(a.catchX, b.catchX, t),
      catchY: lerp(a.catchY, b.catchY, t),
      catchScale: lerp(a.catchScale, b.catchScale, t),
      catchOpacity: lerp(a.catchOpacity, b.catchOpacity, t),
    };
  }

  #tweenPose(from, to, duration, token, easing = Easing.inOutCubic) {
    return tween(scaleTime(duration, this.#reduced), (t) => {
      this.#applyPose(this.#lerpPose(from, to, t));
    }, { easing, token });
  }

  /* --- zdarzenia i etykiety -------------------------------------------- */

  #bindEvents() {
    this.refs.castBtn.addEventListener('click', () => this.#onCastClick());
    this.refs.closeBtn.addEventListener('click', () => this.#closeReveal());
    this.refs.revealBackdrop.addEventListener('click', () => this.#closeReveal());
    this.refs.againBtn.addEventListener('click', () => this.#again());

    // Esc zamyka kartę — standard dla okien dialogowych.
    this.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') { this.#closeReveal(); }
    });
  }

  #syncLabels() {
    if (!this.refs) { return; }

    const label = this.getAttribute('label');

    if (label && this.#phase === PHASES.IDLE) {
      this.refs.castBtn.textContent = label;
    }

    const name = this.getAttribute('rod-name');
    const cost = this.getAttribute('cost');

    this.refs.cost.textContent = '';

    if (name) {
      const strong = document.createElement('strong');
      strong.textContent = name;
      this.refs.cost.appendChild(strong);
    }

    if (cost) {
      this.refs.cost.appendChild(document.createTextNode(
        (name ? ' • ' : '') + money(cost) + ' monet za zarzut'
      ));
    }
  }

  #onCastClick() {
    if (this.#busy || this.#phase !== PHASES.IDLE) { return; }

    if (this.#rodId === null) {
      // Strona nie wybrała jeszcze wędki — niech sama zdecyduje (np. listą kafli).
      this.dispatchEvent(new CustomEvent('castrequest', {
        detail: { rodId: null },
        bubbles: true,
      }));

      return;
    }

    this.cast(this.#rodId);
  }

  /* --- fazy ------------------------------------------------------------- */

  /** Ustawia fazę: `data-phase` (CSS), status (aria-live), przycisk i zdarzenie. */
  #setPhase(phase) {
    this.#phase = phase;
    this.stage.dataset.phase = phase;

    const status = STATUS_TEXT[phase] || '';

    this.refs.status.textContent = status;
    this.refs.status.classList.toggle('is-visible', status !== '');

    const label = this.getAttribute('label');

    this.refs.castBtn.textContent = (phase === PHASES.IDLE && label)
      ? label
      : (BUTTON_TEXT[phase] || BUTTON_TEXT.idle);
    this.refs.castBtn.disabled = phase !== PHASES.IDLE;

    this.dispatchEvent(new CustomEvent('phasechange', {
      detail: { phase },
      bubbles: true,
    }));
  }

  get phase() { return this.#phase; }

  get busy() { return this.#busy; }

  get rodId() { return this.#rodId; }

  get reduced() { return this.#reduced; }

  /* --- efekty wodne i świetlne ------------------------------------------
     Wszystkie losowe wartości poniżej (kąty kropel, pozycje iskier) są
     WYŁĄCZNIE dekoracją. Wynik połowu pochodzi z serwera i nie zależy od nich. */

  /** Bąbelki w toni. */
  #buildBubbles() {
    const box = this.refs.bubbles;

    if (box.childElementCount > 0) { return; }

    for (let i = 0; i < 7; i += 1) {
      const bubble = document.createElement('span');

      bubble.className = 'fs-bubble';
      bubble.style.setProperty('--i', String(i));
      bubble.style.setProperty('--x', (6 + Math.random() * 88).toFixed(2) + '%');
      box.appendChild(bubble);
    }
  }

  /** Plusk: kręgi na tafli + krople. `intensity` > 1 dla rzadkich ryb. */
  #splash(intensity = 1) {
    const box = this.refs.splash;

    for (let i = 0; i < 3; i += 1) {
      const ripple = document.createElement('span');

      ripple.className = 'fs-ripple';
      ripple.style.setProperty('--delay', (i * 0.14).toFixed(2) + 's');
      box.appendChild(ripple);
    }

    const drops = Math.round(9 * intensity);

    for (let i = 0; i < drops; i += 1) {
      const drop = document.createElement('span');
      const angle = Math.PI * (0.18 + Math.random() * 0.64);
      const distance = (16 + Math.random() * 44) * intensity;
      const direction = Math.random() < 0.5 ? -1 : 1;

      drop.className = 'fs-drop';
      // --dx/--dy to lot w górę i na boki, --dy2 powrót w dół (łuk plusku)
      drop.style.setProperty('--dx', (Math.cos(angle) * distance * direction).toFixed(1) + 'px');
      drop.style.setProperty('--dy', (-Math.abs(Math.sin(angle) * distance)).toFixed(1) + 'px');
      drop.style.setProperty('--dy2', (10 + Math.random() * 26).toFixed(1) + 'px');
      box.appendChild(drop);
    }

    // Sprzątamy po wybrzmieniu, żeby DOM nie rósł przy kolejnych zarzutach.
    setTimeout(() => {
      if (!this.#busy) { box.replaceChildren(); }
    }, 1500);
  }

  #clearSplash() {
    this.refs.splash.replaceChildren();
  }

  /** Krople ściekające z ryby podczas wyciągania z wody. */
  #addDrips() {
    const box = this.refs.drips;

    box.replaceChildren();

    for (let i = 0; i < 3; i += 1) {
      const drip = document.createElement('span');

      drip.className = 'fs-drip';
      drip.style.setProperty('--x', (34 + i * 16) + '%');
      drip.style.setProperty('--delay', (i * 0.28).toFixed(2) + 's');
      box.appendChild(drip);
    }
  }

  /** Iskry w karcie wygranej — tylko dla rzadszych poziomów. */
  #addSparkles(count) {
    const box = this.refs.sparkles;

    box.replaceChildren();

    for (let i = 0; i < count; i += 1) {
      const sparkle = document.createElement('span');

      sparkle.className = 'fs-sparkle';
      sparkle.style.setProperty('--i', String(i));
      sparkle.style.setProperty('--x', (10 + Math.random() * 80).toFixed(2) + '%');
      sparkle.style.setProperty('--y', (20 + Math.random() * 70).toFixed(2) + '%');
      sparkle.style.setProperty('--dx', ((Math.random() - 0.5) * 40).toFixed(1) + 'px');
      box.appendChild(sparkle);
    }
  }

  /**
   * Ranga rzadkości wg kolejności z `rzadkosci.kolejnosc` (database/schema.sql).
   * Służy wyłącznie do efektów (mocniejsze branie, iskry) — nigdy do szans.
   */
  #rarityRank(code) {
    return RARITY_ORDER.indexOf(String(code || '').toLowerCase());
  }

  #isRare(code) {
    const rank = this.#rarityRank(code);
    const threshold = RARITY_ORDER.indexOf(HARD_BITE_FROM);

    return rank >= 0 && threshold >= 0 && rank >= threshold;
  }

  /* --- API publiczne komponentu ----------------------------------------- */

  /**
   * Wybiera wędkę (bez zarzutu) — np. po kliknięciu kafla z listy wędek.
   * Dzięki temu przycisk w scenie wie, gdzie rzucać, a pod spodem widać koszt.
   *
   * @param {number|string|null} rodId
   * @param {{ nazwa?: string, koszt?: number|string }} [meta]
   */
  select(rodId, meta = {}) {
    this.#rodId = (rodId === null || rodId === undefined || rodId === '')
      ? null
      : Number(rodId);

    if (meta.nazwa !== undefined) { this.setAttribute('rod-name', String(meta.nazwa)); }
    if (meta.koszt !== undefined) { this.setAttribute('cost', String(meta.koszt)); }
    if (this.#rodId !== null) { this.setAttribute('rod-id', String(this.#rodId)); }

    this.#syncLabels();

    return this;
  }

  /**
   * Pełny obieg: POST api/fish.php -> animacja -> karta wygranej.
   * Promise rozwiązuje się po zamknięciu karty (albo zwraca null po błędzie).
   *
   * Kolejność jest wymuszona przez kontrakt backendu: NAJPIERW odpowiedź,
   * POTEM animacja. Przerwanie animacji nie zmienia wyniku — ryba jest już
   * w ekwipunku i w historii połowów.
   */
  async cast(rodId = this.#rodId) {
    const id = Number(rodId);

    if (!Number.isFinite(id)) {
      this.#fail(new ApiError('Nie wybrano wędki.', 'missing_field', 0));
      return null;
    }

    return this.#start(async (token) => {
      this.#setPhase(PHASES.REQUESTING);

      // Delikatne uniesienie wędziska jako informacja zwrotna po kliknięciu.
      // Nie zdradza wyniku — ten jeszcze nie istnieje po stronie przeglądarki.
      await this.#tweenPose(
        this.#idlePose(),
        this.#hangPose(ROD_ANGLES.lift),
        TIMINGS.rodLift,
        token,
        Easing.outCubic
      );

      if (token.cancelled) { return null; }

      const payload = await this.api.cast(id);

      if (token.cancelled) { return null; }

      return this.#runSequence(payload, token);
    });
  }

  /**
   * Animacja z gotowym payloadem — do testów, demu offline (dev/fishing-demo.html)
   * i do sytuacji, gdy strona sama woła API (własny klient, cache, kolejka).
   */
  async play(payload) {
    return this.#start(async (token) => {
      this.#setPhase(PHASES.REQUESTING);

      await this.#tweenPose(
        this.#idlePose(),
        this.#hangPose(ROD_ANGLES.lift),
        TIMINGS.rodLift,
        token,
        Easing.outCubic
      );

      if (token.cancelled) { return null; }

      return this.#runSequence(payload, token);
    });
  }

  /** Przerywa animację i wraca do idle. Wyniku połowu NIE cofa (ten jest w bazie). */
  reset() {
    this.#cancel();
    this.#busy = false;
    this.#token = null;
    this.#runPromise = null;
    this.#resetToIdle();
  }

  /** Wspólne rusztowanie dla cast()/play(): zajętość, token, sprzątanie. */
  #start(runner) {
    if (this.#busy) { return Promise.resolve(null); }

    this.#busy = true;

    const token = createToken();

    this.#token = token;
    // Geometria mogła się zmienić od ostatniej animacji (resize, zwinięte menu).
    this.#measure();

    const run = async () => {
      try {
        return await runner(token);
      } catch (error) {
        if (!token.cancelled) { this.#fail(error); }
        return null;
      } finally {
        // Sprzątamy tylko wtedy, gdy to nadal NASZ bieg — inaczej reset()/nowy
        // zarzut nadpisałby stan świeższej animacji.
        if (this.#token === token) {
          this.#busy = false;
          this.#token = null;
          this.#runPromise = null;
        }
      }
    };

    this.#runPromise = run();

    return this.#runPromise;
  }

  #cancel() {
    if (this.#token) { this.#token.cancel(); }

    const waiter = this.#closeWaiter;

    this.#closeWaiter = null;

    if (waiter) { waiter(); }
  }

  /** Powrót do stanu początkowego: tafla wody, spławik w dłoni, przycisk aktywny. */
  #resetToIdle() {
    this.lane.clear();
    this.refs.reveal.hidden = true;
    this.refs.error.hidden = true;
    this.refs.card.hidden = false;
    this.refs.sparkles.replaceChildren();
    this.refs.drips.replaceChildren();
    this.refs.catch.classList.remove('is-visible');
    this.refs.catch.style.opacity = '0';
    this.refs.float.classList.remove('is-hidden');
    this.stage.classList.remove('is-hard-bite');
    this.#clearSplash();
    this.#setPhase(PHASES.IDLE);
    this.#applyPose(this.#idlePose());
  }

  /* --- karta wygranej i błędy ------------------------------------------- */

  /**
   * Wypełnia kartę danymi z `przedmiot`. Kolory rzadkości bierzemy z API
   * (pole `kolor`), a nie z CSS — zmiana w bazie trafia na stronę bez redeployu.
   */
  #fillCard(payload) {
    const fish = payload.przedmiot;
    const refs = this.refs;
    const color = String(fish.kolor || '#22d3ee');

    refs.card.style.setProperty('--fs-rarity', color);
    refs.cardKicker.textContent = payload.wedka && payload.wedka.nazwa
      ? 'Złowiono • ' + payload.wedka.nazwa
      : 'Złowiono';

    const icon = safeIconUrl(fish.ikona);

    refs.cardIcon.src = icon || '';
    refs.cardIcon.hidden = icon === '';
    refs.cardIcon.alt = icon ? String(fish.nazwa || '') : '';

    refs.cardName.textContent = String(fish.nazwa || '');
    refs.cardRarity.textContent = String(fish.rzadkosc || '');
    refs.cardValue.textContent = money(fish.wartosc) + ' monet';
    refs.cardChance.textContent = percent(fish.szansa_procent);

    const cost = payload.rzut && payload.rzut.koszt !== undefined
      ? payload.rzut.koszt
      : (payload.wedka ? payload.wedka.koszt_uzycia : 0);

    refs.cardCost.textContent = money(cost) + ' monet';

    // Saldo: `saldo_przed` -> `saldo_po` (po opłacie za zarzut).
    const before = Number(payload.saldo_przed || 0);
    const after = Number(payload.saldo_po || 0);

    refs.cardBalance.textContent = money(before) + ' → ';

    const afterElement = document.createElement('strong');

    afterElement.textContent = money(after);
    afterElement.className = after >= before ? 'fs-pos' : 'fs-neg';
    refs.cardBalance.appendChild(afterElement);
  }

  /** Pokazuje błąd w tym samym overlayu co karta wygranej + zdarzenie `error`. */
  #fail(error) {
    const kod = (error && error.kod) || 'error';
    const message = (error && error.message) || 'Nieznany błąd.';

    this.#setPhase(PHASES.ERROR);

    const box = this.refs.error;

    box.replaceChildren();
    box.appendChild(document.createTextNode(message + ' '));

    const code = document.createElement('code');

    code.textContent = kod;
    box.appendChild(code);

    // Karta błędu musi dać się zamknąć także klawiaturą (nie tylko klik w tło).
    const close = document.createElement('button');

    close.type = 'button';
    close.className = 'fs-btn fs-btn--ghost fs-error-close';
    close.textContent = 'Zamknij';
    close.addEventListener('click', () => this.#closeReveal());
    box.appendChild(close);

    this.refs.card.hidden = true;
    box.hidden = false;
    this.refs.reveal.hidden = false;
    close.focus();

    this.dispatchEvent(new CustomEvent('error', {
      detail: { kod, message, status: (error && error.status) || 0 },
      bubbles: true,
    }));
  }

  /** Czeka na zamknięcie karty (albo na anulowanie biegu). */
  #waitClose(token) {
    return new Promise((resolve) => {
      this.#closeWaiter = () => {
        this.#closeWaiter = null;
        resolve(true);
      };

      if (token) { token.onCancel(() => this.#closeWaiter && this.#closeWaiter()); }
    });
  }

  #closeReveal() {
    this.refs.reveal.hidden = true;
    this.refs.sparkles.replaceChildren();

    const waiter = this.#closeWaiter;

    this.#closeWaiter = null;

    // Sekwencja animacji sama wróci do idle po zamknięciu karty.
    if (waiter) {
      waiter();
      return;
    }

    // Zamknięcie karty błędu (poza sekwencją) też musi odblokować przycisk.
    if (!this.#busy) { this.#resetToIdle(); }
  }

  /** "Zarzuć ponownie" z karty wygranej — najpierw domykamy bieżący bieg. */
  async #again() {
    const rodId = this.#rodId;
    const run = this.#runPromise;

    this.#closeReveal();

    if (run) {
      try {
        await run;
      } catch (error) {
        // Błąd już obsłużył #fail() — tu tylko nie przerywamy ponownego zarzutu.
      }
    }

    if (rodId !== null) {
      await this.cast(rodId);
    } else {
      this.#onCastClick();
    }
  }

  /* --- sekwencja animacji -------------------------------------------------
     casting -> waiting (pasek `reel`) -> bite -> reeling -> reveal.
     Każda faza zwraca false, gdy bieg został anulowany (reset()/odpięcie
     komponentu) — wtedy sekwencja przerywa bez dotykania DOM.               */

  /**
   * Pilnuje kontraktu z api/fish.php — reguły mieszkają w contract.js, żeby dało
   * się je uruchomić w Node (dev/check-fishing.mjs) na prawdziwej odpowiedzi API.
   * Gdyby backend zwrócił pasek bez wygranej pozycji, animacja nie miałaby czego
   * pokazać — wolimy czytelny błąd niż ciche "wylosujmy coś sobie sami".
   */
  #validate(payload) {
    const fish = validateFishPayload(payload);
    const mismatch = reelWinnerMismatch(payload);

    // Miękka kontrola spójności: wygrana pozycja paska ma być tą samą rybą.
    // Nie rzucamy błędu (wynik i tak leży już w bazie), ale logujemy — to
    // wczesne ostrzeżenie, gdyby ktoś zmienił backend/Reel.php albo ustawienie
    // `reel_indeks_wygranej` bez aktualizacji frontendu.
    if (mismatch !== null) {
      console.warn('[fishing-scene] ' + mismatch);
    }

    return fish;
  }

  async #runSequence(payload, token) {
    const fish = this.#validate(payload);
    const rare = this.#isRare(fish.rzadkosc_kod);

    if (!(await this.#runCast(token))) { return null; }
    if (!(await this.#runWaiting(payload, token))) { return null; }
    if (!(await this.#runBite(rare, token))) { return null; }
    if (!(await this.#runReeling(fish, rare, token))) { return null; }
    if (!(await this.#runReveal(payload, fish, rare, token))) { return null; }

    // Karta zamknięta — wracamy do tafli wody i aktywnego przycisku.
    this.#resetToIdle();

    return payload;
  }

  /** Zamach, lot spławika po łuku, plusk i uniesienie wędziska do oczekiwania. */
  async #runCast(token) {
    this.#setPhase(PHASES.CASTING);

    const g = this.geom;
    const start = this.#hangPose(ROD_ANGLES.lift);
    const startX = g.homeX + start.floatX;
    const startY = g.homeY + start.floatY;
    const arc = g.h * SCENE.castArc;

    const flown = await tween(scaleTime(TIMINGS.castArc, this.#reduced), (t) => {
      // Wędzisko robi zamach w pierwszej części lotu, potem już tylko trzyma.
      const angle = lerp(ROD_ANGLES.lift, ROD_ANGLES.cast, Math.min(1, t * 1.6));
      const x = lerp(startX, g.homeX, t);
      // Łuk: liniowo w dół + sinusoidalne uniesienie — spławik LECI, nie spada.
      const y = lerp(startY, g.homeY, t) - Math.sin(Math.PI * t) * arc;

      this.#applyPose({
        angle,
        floatX: x - g.homeX,
        floatY: y - g.homeY,
        sag: lerp(SCENE.sag.idle, SCENE.sag.cast, t),
        attach: 'float',
        catchX: x,
        catchY: y,
        catchScale: 0.6,
        catchOpacity: 0,
      });
    }, { easing: Easing.outCubic, token });

    if (!flown) { return false; }

    this.#splash(1);

    const landed = {
      angle: ROD_ANGLES.cast,
      floatX: 0,
      floatY: 0,
      sag: SCENE.sag.cast,
      attach: 'float',
      catchX: g.homeX,
      catchY: g.homeY,
      catchScale: 0.55,
      catchOpacity: 0,
    };

    return this.#tweenPose(
      landed,
      this.#waitingPose(),
      TIMINGS.splashSettle,
      token,
      Easing.outCubic
    );
  }

  /**
   * Oczekiwanie na branie = przewijanie paska `reel` pod wodą.
   * Pasek zatrzymuje się na `reel_indeks_wygranej` i TO jest sygnał brania —
   * dokładnie tak, jak umówione w backend/Reel.php i api/fish.php.
   */
  async #runWaiting(payload, token) {
    this.#setPhase(PHASES.WAITING);

    this.refs.float.classList.remove('is-hidden');
    this.lane.build(payload.reel, payload.reel_indeks_wygranej);

    const length = Number(payload.reel_dlugosc) || payload.reel.length;
    const duration = this.lane.durationFor(length, this.#reduced);

    if (!(await this.lane.scroll({ duration, token }))) { return false; }

    // Chwila z wygraną rybą na haczyku — widać CO wzięło, zanim zatniemy.
    await wait(scaleTime(TIMINGS.laneSettle, this.#reduced), token);

    return !token.cancelled;
  }

  /** Branie: spławik nurkuje, żyłka się napina, wędzisko idzie w dół, plusk. */
  async #runBite(rare, token) {
    this.#setPhase(PHASES.BITE);

    const g = this.geom;
    const plunge = g.h * SCENE.bitePlunge * (rare ? 1.4 : 1);
    const base = this.#waitingPose();

    this.stage.classList.toggle('is-hard-bite', rare);
    this.#splash(rare ? 1.6 : 1.1);

    // Kształt ruchu siedzi w lerpStops (nurkowanie -> odbicie -> szarpnięcie),
    // dlatego easing tweena zostaje liniowy.
    return tween(scaleTime(TIMINGS.bite, this.#reduced), (t) => {
      const angle = lerpStops([ROD_ANGLES.waiting, ROD_ANGLES.bite, ROD_ANGLES.bite + 8], t);
      const floatY = lerpStops([0, plunge, plunge * 0.45, plunge * 0.9], t);

      this.#applyPose({
        ...base,
        angle,
        floatY,
        sag: lerp(SCENE.sag.waiting, SCENE.sag.bite, Math.min(1, t * 2.2)),
      });
    }, { token });
  }

  /** Wyciąganie żyłki: z wody wyłania się nagroda i wędruje w górę po żyłce. */
  async #runReeling(fish, rare, token) {
    this.#setPhase(PHASES.REELING);

    const g = this.geom;
    const plunge = g.h * SCENE.bitePlunge * (rare ? 1.4 : 1);
    const icon = safeIconUrl(fish.ikona);

    this.refs.catchImg.src = icon || '';
    this.refs.catchImg.hidden = icon === '';
    this.refs.catchImg.alt = icon ? String(fish.nazwa || '') : '';
    this.refs.catch.style.setProperty('--fs-rarity', String(fish.kolor || '#22d3ee'));
    this.#addDrips();

    // Spławik chowa się pod wodę — na żyłce zostaje już tylko zdobycz.
    this.refs.float.classList.add('is-hidden');

    const pulled = await tween(scaleTime(TIMINGS.reelIn, this.#reduced), (t) => {
      const angle = lerp(ROD_ANGLES.bite, ROD_ANGLES.reel, Easing.inOutCubic(t));
      const tip = this.#tip(angle);
      // Ryba idzie w górę po żyłce, w stronę UNOSZONEJ właśnie przelotki.
      const lift = SCENE.heroLift * Easing.outCubic(t);

      this.#applyPose({
        angle,
        floatX: 0,
        floatY: lerp(plunge, 0, t),
        sag: lerp(SCENE.sag.bite, SCENE.sag.reel, t),
        attach: 'catch',
        catchX: lerp(g.homeX, tip.x, lift),
        catchY: lerp(g.homeY + plunge * 0.35, tip.y, lift),
        catchScale: lerp(0.5, 1, Easing.outBack(Math.min(1, t * 1.15))),
        catchOpacity: Math.min(1, t * 4),
      });
    }, { token });

    if (!pulled) { return false; }

    await wait(scaleTime(TIMINGS.revealIn, this.#reduced), token);

    return !token.cancelled;
  }

  /** Karta wygranej + zdarzenie `catch` (strona aktualizuje tu saldo i listy). */
  async #runReveal(payload, fish, rare, token) {
    this.#setPhase(PHASES.REVEAL);
    this.#fillCard(payload);

    if (rare) {
      const rank = this.#rarityRank(fish.rzadkosc_kod);
      const ultra = RARITY_ORDER.indexOf('ultra');

      this.#addSparkles(ultra >= 0 && rank >= ultra ? 26 : 15);
    } else {
      this.refs.sparkles.replaceChildren();
    }

    this.refs.error.hidden = true;
    this.refs.card.hidden = false;
    this.refs.reveal.hidden = false;
    this.refs.closeBtn.focus();

    // Strona dostaje pełny payload: saldo_po, ekwipunek_id, message itd.
    this.dispatchEvent(new CustomEvent('catch', {
      detail: { payload, fish },
      bubbles: true,
    }));

    await this.#waitClose(token);

    return !token.cancelled;
  }
}

if (typeof customElements !== 'undefined' && !customElements.get('fishing-scene')) {
  customElements.define('fishing-scene', FishingScene);
}
