/**
 * public/assets/js/fishing/reel-lane.js
 *
 * Podwodny pasek ruletki — ta sama dana co w starej animacji skrzynki, tylko
 * przeniesiona pod wodę. Realizuje umowę z api/fish.php:
 *
 *   reel[reel_indeks_wygranej] === przedmiot
 *
 * czyli pasek przewija się i ZATRZYMUJE na wygranej pozycji. Zatrzymanie jest
 * sygnałem dla sceny: "teraz branie". Nic tu nie losuje — kolejność i zawartość
 * paska przychodzą z serwera (backend/Reel.php).
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

import { TIMINGS } from './config.js';

/**
 * Ikona przychodzi z bazy (Config::iconUrl robi basename()), ale frontend nie
 * ma powodu ufać żadnemu ciągowi znaków z sieci — odcinamy schematy, które
 * mogłyby wykonać kod, i zostawiamy ścieżki względne oraz http(s).
 *
 * Eksportowane, bo korzysta z tego też karta wygranej (fishing-scene.js).
 */
export function safeIconUrl(value) {
  const url = String(value || '').trim();

  if (url === '') { return ''; }
  if (/^(javascript|data|vbscript):/i.test(url)) { return ''; }

  return url;
}

export class ReelLane {
  /**
   * @param {{ lane: HTMLElement, track: HTMLElement, hook: HTMLElement|null }} refs
   */
  constructor(refs) {
    this.lane = refs.lane;
    this.track = refs.track;
    this.hook = refs.hook || null;
    this.items = [];
    this.winnerIndex = -1;
  }

  /**
   * Buduje pasek z odpowiedzi `fish.php`.
   *
   * @param {Array<Record<string, any>>} reel  lista pozycji paska
   * @param {number} winnerIndex               `reel_indeks_wygranej`
   */
  build(reel, winnerIndex) {
    this.clear();

    if (!Array.isArray(reel) || reel.length === 0) {
      return this;
    }

    const fragment = document.createDocumentFragment();

    reel.forEach((fish, index) => {
      const item = document.createElement('div');

      item.className = 'fs-lane-item';
      item.style.setProperty('--i', String(index));
      item.style.setProperty('--fs-item-color', String(fish.kolor || '#94a3b8'));
      item.title = String(fish.nazwa || '');

      const icon = safeIconUrl(fish.ikona);

      if (icon !== '') {
        const img = document.createElement('img');

        img.src = icon;
        // alt="" celowo: pasek jest dekoracją, a wynik i tak trafia do karty
        // wygranej z pełną nazwą (dostępność — brak szumu dla czytników).
        img.alt = '';
        img.loading = 'lazy';
        img.draggable = false;
        item.appendChild(img);
      }

      fragment.appendChild(item);
    });

    this.track.appendChild(fragment);
    this.items = Array.from(this.track.children);
    this.winnerIndex = Number.isInteger(winnerIndex) ? winnerIndex : -1;

    return this;
  }

  /** Czyści pasek (nowy zarzut / reset sceny). */
  clear() {
    this.track.style.transition = 'none';
    this.track.style.transform = 'translate3d(0, 0, 0)';
    this.track.replaceChildren();
    this.items = [];
    this.winnerIndex = -1;
  }

  /**
   * Czas parady w ms — skaluje się z długością paska z ustawień backendu
   * (`reel_dlugosc`), ale trzyma się widełek, żeby animacja nie była ani
   * "pstryknięciem", ani nudna.
   */
  durationFor(length, reduced = false) {
    const items = Math.max(1, Number(length) || 1);
    const raw = Math.min(TIMINGS.laneMax, Math.max(TIMINGS.laneMin, items * TIMINGS.lanePerItem));

    return reduced ? Math.max(1, Math.round(raw * TIMINGS.reducedScale)) : raw;
  }

  /** Pozycja środka elementu względem lewej krawędzi pasa. */
  #centerOf(element) {
    const laneBox = this.lane.getBoundingClientRect();
    const box = element.getBoundingClientRect();

    return box.left + box.width / 2 - laneBox.left;
  }

  /** X haczyka (wskaźnika) względem pasa — mierzone, nie zakładane z góry. */
  #hookX() {
    if (this.hook) {
      const laneBox = this.lane.getBoundingClientRect();
      const box = this.hook.getBoundingClientRect();

      return box.left + box.width / 2 - laneBox.left;
    }

    return this.lane.clientWidth / 2;
  }

  /**
   * Przewija pasek tak, że wygrana pozycja zatrzymuje się na haczyku.
   * Promise rozwiązuje się DOPIERO po zatrzymaniu — to jest moment brania.
   *
   * @param {{ duration: number, token?: {cancelled:boolean}|null }} options
   * @returns {Promise<boolean>} true = dojechało, false = anulowane
   */
  scroll(options = {}) {
    const duration = Math.max(1, options.duration || 0);
    const token = options.token || null;

    return new Promise((resolve) => {
      const winner = this.items[this.winnerIndex];

      // Bez wygranej pozycji nie ma czego zatrzymywać — scena i tak idzie dalej.
      if (!winner || this.items.length < 2) {
        resolve(true);
        return;
      }

      if (token && token.cancelled) {
        resolve(false);
        return;
      }

      const hookX = this.#hookX();
      const step = this.#centerOf(this.items[1]) - this.#centerOf(this.items[0]);

      // Start: pierwsza ryba tuż za haczykiem, więc parada rusza od razu.
      const startOffset = hookX + step - this.#centerOf(this.items[0]);
      // Meta: środek wygranej ryby dokładnie na haczyku.
      const endOffset = hookX - this.#centerOf(winner);

      this.track.style.transition = 'none';
      this.track.style.transform = 'translate3d(' + startOffset + 'px, 0, 0)';

      // Wymuszony reflow — bez tego przeglądarka złączy oba zapisy transform
      // i transition w ogóle nie wystartuje (ten sam trik co w starej ruletce).
      void this.track.offsetWidth;

      let settled = false;
      let timer = 0;

      const onEnd = (event) => {
        if (event.propertyName === 'transform') { finish(true); }
      };

      const finish = (value) => {
        if (settled) { return; }

        settled = true;
        clearTimeout(timer);
        this.track.removeEventListener('transitionend', onEnd);

        // Podświetlamy zwycięzcę — wizualne potwierdzenie, że pasek stanął
        // na `reel_indeks_wygranej` (przydatne też przy przeglądaniu animacji).
        if (value) { winner.classList.add('is-winner'); }

        resolve(value);
      };

      this.track.addEventListener('transitionend', onEnd);
      // Zabezpieczenie, gdyby transitionend nie nadeszło (ukryta karta, throttling).
      timer = setTimeout(() => finish(true), duration + 600);

      if (token) { token.onCancel(() => finish(false)); }

      // Długi ogon krzywej = ryby zwalniają przy haczyku (napięcie przed braniem).
      this.track.style.transition = 'transform ' + duration + 'ms cubic-bezier(0.16, 0.74, 0.12, 1)';
      this.track.style.transform = 'translate3d(' + endOffset + 'px, 0, 0)';
    });
  }
}
