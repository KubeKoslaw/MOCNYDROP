/**
 * public/assets/js/fishing/config.js
 *
 * Wszystkie stałe animacji w jednym miejscu: fazy, czasy, kąty wędziska
 * i progi rzadkości. Dzięki temu @THOPA174 i @jgpostrach mogą stroić
 * "czucie" łowienia bez dotykania logiki komponentu.
 *
 * WAŻNE: nie ma tu żadnego losowania wyniku. Wynik przychodzi z serwera
 * (api/cast.php), a te stałe opisują wyłącznie prezentację.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

/** Fazy sceny — trafiają do `data-phase` na `.fs-stage`. */
export const PHASES = Object.freeze({
  IDLE: 'idle',             // tafl wody, spławik wisi, przycisk aktywny
  REQUESTING: 'requesting', // klik -> czekamy na odpowiedź api/cast.php
  CASTING: 'casting',       // zamach, lot spławika, plusk
  WAITING: 'waiting',       // fale + podwodna parada ryb (pasek `reel`)
  BITE: 'bite',             // gwałtowne zanurzenie spławika i zacięcie
  REELING: 'reeling',       // wyciąganie żyłki, z wody wyłania się nagroda
  REVEAL: 'reveal',         // karta z dropem, rzadkością i statystykami
  ERROR: 'error',           // komunikat błędu (np. brak monet)
});

/** Kolejność faz "głównych" — używana przez harness w dev/fishing-demo.html. */
export const PHASE_ORDER = Object.freeze([
  PHASES.IDLE,
  PHASES.REQUESTING,
  PHASES.CASTING,
  PHASES.WAITING,
  PHASES.BITE,
  PHASES.REELING,
  PHASES.REVEAL,
]);

/** Czasy w ms. `lane*` skaluje się z długością paska zwróconą przez backend. */
export const TIMINGS = Object.freeze({
  rodLift: 380,        // uniesienie wędziska przed zamachem
  castArc: 950,        // lot spławika po łuku
  splashSettle: 360,   // chwila na wybrzmienie plusku
  lanePerItem: 95,     // ms na jedną pozycję paska `reel`
  laneMin: 3600,       // dolny limit oczekiwania (żeby nie było "pstryk")
  laneMax: 7200,       // górny limit (żeby nie nudzić)
  laneSettle: 620,     // pauza z wygraną rybą na haczyku przed zacięciem
  bite: 900,           // branie: zanurzenie + szarpnięcie wędziska
  reelIn: 1500,        // wyciąganie ryby z wody
  revealIn: 240,       // domknięcie przejścia do karty
  reducedScale: 0.12,  // mnożnik czasów przy prefers-reduced-motion
});

/**
 * Kąty wędziska w stopniach (wartość `rotate()` w CSS).
 *
 * Konwencja: wędzisko ma `transform-origin: right center`, więc jego koniec
 * (przelotka) leży w kierunku (-cos(a), -sin(a)) od dłoni wędkarza.
 *   a =  34 -> koniec w lewo-górę (spokojne trzymanie)
 *   a =  54 -> wędzisko pionowo w górze (zamach)
 *   a = -16 -> koniec w lewo-dół (wyrzut / zacięcie)
 *
 * `tip()` w fishing-scene.js liczy z tych kątów pozycję przelotki w pikselach,
 * więc żyłka zawsze trafia w koniec wędziska — również po zmianie geometrii
 * w CSS (wszystko jest mierzone, nic nie jest wpisane na sztywno).
 */
export const ROD_ANGLES = Object.freeze({
  idle: 34,
  lift: 54,
  cast: -16,
  waiting: 28,
  bite: -10,
  reel: 46,
  reveal: 50,
});

/**
 * Geometria uzupełniająca — wartości względne (ułamek szerokości/wysokości
 * sceny). Punkty kotwiczenia (dłonie, linia wody, miejsce zarzutu) są w CSS
 * i JS je mierzy; tu jest tylko to, czego w CSS wyrazić się nie da.
 */
export const SCENE = Object.freeze({
  idleHang: 0.17,      // jak nisko pod przelotką wisi spławik w idle
  castArc: 0.22,       // wysokość łuku lotu spławika (ułamek wysokości sceny)
  heroLift: 0.62,      // 0 = woda, 1 = przelotka: gdzie zatrzymuje się ryba
  bitePlunge: 0.055,   // o ile wysokości sceny nurkuje spławik przy braniu
  sag: Object.freeze({ // zwis żyłki (ułamek odległości między końcami)
    idle: 0.05,
    cast: 0.14,
    waiting: 0.1,
    bite: 0.0,
    reel: 0.03,
  }),
});

/**
 * Rzadkości rosnąco — dokładnie ta sama kolejność co `rzadkosci.kolejnosc`
 * w database/schema.sql. Służy TYLKO do efektów wizualnych (mocniejsze
 * branie i iskry od `mityczna` w górę), nigdy do liczenia szans.
 */
export const RARITY_ORDER = Object.freeze([
  'pospolita',
  'przecietna',
  'niezla',
  'mityczna',
  'niesamowita',
  'ultra',
]);

/** Od którego poziomu rzadkości gramy "mocnym" braniem i iskrami. */
export const HARD_BITE_FROM = 'mityczna';

/** Komunikaty statusu (aria-live) — krótkie, czytane też przez czytniki. */
export const STATUS_TEXT = Object.freeze({
  [PHASES.IDLE]: 'Spokojna woda. Zarzuć wędkę, kiedy będziesz gotowy.',
  [PHASES.REQUESTING]: 'Zarzucam…',
  [PHASES.CASTING]: 'Spławik leci do wody…',
  [PHASES.WAITING]: 'Czekamy na branie…',
  [PHASES.BITE]: 'Bierze! Zacinamy!',
  [PHASES.REELING]: 'Wyciągamy zdobycz…',
  [PHASES.REVEAL]: '',
  [PHASES.ERROR]: '',
});

/** Etykiety przycisku akcji w zależności od fazy. */
export const BUTTON_TEXT = Object.freeze({
  idle: 'Zarzuć wędkę',
  requesting: 'Zarzucam…',
  casting: '…',
  waiting: 'Czekaj na branie',
  bite: 'Zacięcie!',
  reeling: 'Wyciągamy…',
  reveal: 'Złowiono!',
  error: 'Zarzuć wędkę',
});
