/**
 * public/assets/js/fishing/index.js — punkt wejścia modułu łowienia.
 *
 * Sam import rejestruje element <fishing-scene>; arkusz CSS dopina się przy
 * pierwszym wpięciu komponentu do dokumentu (albo można go podpiąć ręcznie:
 * <link rel="stylesheet" href="public/assets/css/fishing-scene.css">).
 *
 * Użycie na stronie:
 *   <script type="module" src="public/assets/js/fishing/index.js"></script>
 *   <fishing-scene api-base="api/" show-lane></fishing-scene>
 *
 * albo z własnego modułu:
 *   import { FishingScene } from './public/assets/js/fishing/index.js';
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */

export { FishingScene, FishingScene as default } from './fishing-scene.js';
export { ReelLane, safeIconUrl } from './reel-lane.js';
export { ApiError, TOKEN_KEY, createApi, readToken } from './api.js';

export {
  ContractError,
  REQUIRED_PRZEDMIOT_FIELDS,
  isContractValid,
  reelWinnerMismatch,
  validateFishPayload,
} from './contract.js';

export {
  BUTTON_TEXT,
  HARD_BITE_FROM,
  PHASES,
  PHASE_ORDER,
  RARITY_ORDER,
  ROD_ANGLES,
  SCENE,
  STATUS_TEXT,
  TIMINGS,
} from './config.js';

export {
  Easing,
  createToken,
  lerp,
  lerpStops,
  prefersReducedMotion,
  scaleTime,
  tween,
  wait,
} from './timeline.js';
