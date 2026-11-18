import { createApi, ApiError } from './api.js';
import { CONFIG } from './config.js';
import { EVENTS } from './contract.js';

export class FishingScene extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.api = createApi();
    this.selectedRodId = CONFIG.DEFAULT_ROD_ID;
    this.rodInfo = null;
  }

  connectedCallback() {
    this.render();
    this.bindEvents();
  }

  render() {
    this.shadowRoot.innerHTML = `
      <style>
        :host { display: block; position: relative; width: 100%; height: 400px; background: #0f172a; border-radius: 12px; overflow: hidden; font-family: sans-serif; color: #fff; }
        .scene-container { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; padding: 20px; text-align: center; }
        .water-surface { font-size: 2rem; margin-bottom: 15px; }
        button { background: #3b82f6; border: none; color: white; padding: 10px 20px; font-size: 1rem; border-radius: 6px; cursor: pointer; }
        button:hover { background: #2563eb; }
        button:disabled { background: #64748b; cursor: not-allowed; }
      </style>
      <div class="scene-container">
        <div class="water-surface">🌊 Łowisko MocnyDrop 🎣</div>
        <p id="rod-label">Wybierz wędkę i zarzuć haczyk!</p>
        <button id="cast-btn">Zarzuć wędkę</button>
      </div>
    `;
  }

  bindEvents() {
    this.shadowRoot.getElementById('cast-btn').addEventListener('click', () => {
      this.dispatchEvent(new CustomEvent(EVENTS.CAST_REQUEST, { bubbles: true, composed: true }));
    });
  }

  select(rodId, info) {
    this.selectedRodId = rodId;
    this.rodInfo = info;
    const label = this.shadowRoot.getElementById('rod-label');
    if (label && info) {
      label.textContent = `Wędka: ${info.nazwa} (Zarzuty: ${info.zarzuty})`;
    }
  }

  async cast(rodId) {
    const btn = this.shadowRoot.getElementById('cast-btn');
    if (btn) btn.disabled = true;
    try {
      const result = await this.api.cast(rodId || this.selectedRodId);
      this.dispatchEvent(new CustomEvent(EVENTS.CATCH, {
        bubbles: true,
        composed: true,
        detail: { payload: result }
      }));
      return result;
    } catch (err) {
      this.dispatchEvent(new CustomEvent(EVENTS.ERROR, {
        bubbles: true,
        composed: true,
        detail: { kod: err.kod || 'error', status: err.status || 500, message: err.message }
      }));
      throw err;
    } finally {
      if (btn) btn.disabled = false;
    }
  }
}

customElements.define('fishing-scene', FishingScene);