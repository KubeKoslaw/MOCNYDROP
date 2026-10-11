/**
 * public/assets/js/fishing/timeline.js
 *
 * Mikronarzędzia czasowe animacji: `wait`, `tween` (rAF) i krzywe easing.
 * Bez zależności — projekt nie ma bundlera ani biblioteki animacji.
 *
 * Wszystko da się anulować tokenem (`createToken()`), bo gracz może zamknąć
 * kartę albo kliknąć ponownie w trakcie animacji. Anulowanie NIGDY nie zmienia
 * wyniku połowu — ten leży już w bazie (patrz backend/FishingService.php).
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

/**
 * Zapas czasu dla watchdogów (ms). Animacja ma się domknąć nawet wtedy, gdy
 * przeglądarka nie produkuje klatek (karta w tle, headless bez kompozytora).
 */
const TICK_GRACE_MS = 250;

/** Czy użytkownik prosi o ograniczony ruch (ustawienie systemu/OS). */
export function prefersReducedMotion() {
  return typeof window !== 'undefined'
    && typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/** Skraca czasy przy ograniczonym ruchu — przebieg faz zostaje ten sam. */
export function scaleTime(ms, reduced) {
  return reduced ? Math.max(1, Math.round(ms * 0.12)) : Math.max(0, Math.round(ms));
}

/**
 * Token anulowania z rejestrowanym sprzątaniem.
 *
 * Dlaczego nie AbortController: potrzebujemy tylko "czy przerwane" + hooki
 * do clearTimeout/cancelAnimationFrame, a token bez zależności działa też
 * w starych przeglądarkach i daje się łatwo przetestować.
 */
export function createToken() {
  const cleanups = new Set();

  return {
    cancelled: false,

    cancel() {
      this.cancelled = true;
      cleanups.forEach((fn) => fn());
      cleanups.clear();
    },

    /**
     * Rejestruje funkcję sprzątającą. Zwraca funkcję wyrejestrowującą.
     * Gdy token jest już anulowany, sprzątanie odpala od razu.
     */
    onCancel(fn) {
      if (this.cancelled) {
        fn();
        return () => {};
      }

      cleanups.add(fn);
      return () => cleanups.delete(fn);
    },
  };
}

/**
 * Pauza. Rozwiązuje się po `ms` albo od razu, gdy token zostanie anulowany.
 * @returns {Promise<boolean>} true = dokończone, false = anulowane
 */
export function wait(ms, token = null) {
  return new Promise((resolve) => {
    if (token && token.cancelled) {
      resolve(false);
      return;
    }

    if (ms <= 0) {
      resolve(true);
      return;
    }

    let settled = false;
    let timer = 0;

    const done = (value) => {
      if (settled) { return; }
      settled = true;
      clearTimeout(timer);
      resolve(value);
    };

    timer = setTimeout(() => done(true), ms);

    if (token) { token.onCancel(() => done(false)); }
  });
}

/** Krzywe easing — nazwy mówią, do czego ich używamy w scenie. */
export const Easing = Object.freeze({
  linear: (t) => t,
  /** dojazd spławika do wody i finisz paska ryb (długi ogon = napięcie) */
  outCubic: (t) => 1 - Math.pow(1 - t, 3),
  outQuint: (t) => 1 - Math.pow(1 - t, 5),
  /** zamach wędziskiem: rozpędza się i wyhamowuje */
  inOutCubic: (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2),
  /** zacięcie — szybki start */
  inQuad: (t) => t * t,
  /** "pop" karty wygranej z lekkim przetoczeniem */
  outBack: (t) => {
    const c1 = 1.70158;
    const c3 = c1 + 1;
    return 1 + c3 * Math.pow(t - 1, 3) + c1 * Math.pow(t - 1, 2);
  },
});

/**
 * Interpolacja liczbowa napędzana requestAnimationFrame.
 *
 * Używamy jej zamiast CSS transitions tam, gdzie jedna klatka musi zaktualizować
 * kilka rzeczy naraz (kąt wędziska + przebieg żyłki + pozycja ryby) — inaczej
 * animacje rozjechałyby się o klatkę albo dwie.
 *
 * @param {number} duration                       czas w ms
 * @param {(t: number, elapsed: number) => void} onUpdate t = postęp 0..1 (już z easingiem)
 * @param {{ easing?: (t:number)=>number, token?: {cancelled:boolean}|null }} [options]
 * @returns {Promise<boolean>} true = dokończone, false = anulowane
 */
export function tween(duration, onUpdate, options = {}) {
  const easing = options.easing || Easing.linear;
  const token = options.token || null;

  return new Promise((resolve) => {
    if (token && token.cancelled) {
      resolve(false);
      return;
    }

    if (duration <= 0) {
      onUpdate(1, 0);
      resolve(true);
      return;
    }

    let settled = false;
    let frame = 0;
    let watchdog = 0;

    const done = (value) => {
      if (settled) { return; }

      settled = true;
      cancelAnimationFrame(frame);
      clearTimeout(watchdog);
      resolve(value);
    };

    const startedAt = performance.now();

    const step = (now) => {
      const raw = Math.min(1, (now - startedAt) / duration);

      onUpdate(easing(raw), raw * duration);

      if (raw < 1) {
        frame = requestAnimationFrame(step);
      } else {
        done(true);
      }
    };

    frame = requestAnimationFrame(step);

    // Watchdog: requestAnimationFrame nie tyka, gdy karta jest w tle (throttling
    // przeglądarki) albo gdy kompozytor nie produkuje klatek. Bez tego gracz
    // utknąłby w połowie animacji z ZABLOKOWANYM przyciskiem i już wydaną
    // monetą — a wynik i tak jest zapisany w bazie. Dlatego po czasie
    // domykamy tween, przeskakując do stanu końcowego.
    watchdog = setTimeout(() => {
      onUpdate(1, duration);
      done(true);
    }, duration + TICK_GRACE_MS);

    if (token) { token.onCancel(() => done(false)); }
  });
}

/** Liniowa interpolacja — czytelniej niż `a + (b - a) * t` w kodzie animacji. */
export function lerp(a, b, t) {
  return a + (b - a) * t;
}

/**
 * Interpolacja w wielu punktach (ścieżka z "przystankami").
 * `stops` = [v0, v1, ...]; t = 0..1 rozkłada się równo między nie.
 * Przydaje się do brania: spławik nurkuje, odbija i znów idzie w dół.
 */
export function lerpStops(stops, t) {
  if (stops.length === 0) { return 0; }
  if (stops.length === 1) { return stops[0]; }

  const clamped = Math.min(1, Math.max(0, t));
  const segment = clamped * (stops.length - 1);
  const index = Math.min(stops.length - 2, Math.floor(segment));

  return lerp(stops[index], stops[index + 1], segment - index);
}
