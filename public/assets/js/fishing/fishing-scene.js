import { DEFAULT_ICON } from './config.js';
import { createApi } from './api.js';
import { SCENE_EVENTS, SoundFx, showToast, formatMoney, castsLabel, esc, poolIcons } from './contract.js';

export class FishingScene extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.api = createApi({ tokenKey: this.getAttribute('token-key') || undefined });
    this.selectedRodId = null;
  }

  connectedCallback() {
    this.shadowRoot.innerHTML = `
      <div class="fishing-scene-wrapper">
        <p id="scene-rod-info">Wybierz wędkę, aby rozpocząć łowienie</p>
        <button id="scene-cast-trigger" type="button">Zarzuć wędkę</button>
      </div>
    `;
    this.shadowRoot.getElementById('scene-cast-trigger')?.addEventListener('click', () => {
      this.dispatchEvent(new CustomEvent(SCENE_EVENTS.CAST_REQUEST, { bubbles: true, composed: true }));
    });
  }

  select(rodId, meta) {
    this.selectedRodId = rodId;
    const infoEl = this.shadowRoot.getElementById('scene-rod-info');
    if (infoEl && meta) {
      infoEl.textContent = `${meta.nazwa} (${castsLabel(meta.zarzuty)})`;
    }
  }

  async cast(rodId) {
    try {
      const payload = await this.api.cast(rodId ?? this.selectedRodId);
      this.dispatchEvent(new CustomEvent(SCENE_EVENTS.CATCH, {
        bubbles: true,
        composed: true,
        detail: { payload },
      }));
      return payload;
    } catch (err) {
      this.dispatchEvent(new CustomEvent(SCENE_EVENTS.ERROR, {
        bubbles: true,
        composed: true,
        detail: { kod: err.kod, status: err.status, message: err.message },
      }));
      throw err;
    }
  }
}

customElements.define('fishing-scene', FishingScene);

export const fishingAndCasesMixin = {
  async loadRods() {
    const container = document.getElementById('rods-container');
    try {
      const data = await this.api.fetch('wedki.php', { auth: false });
      this.activeRods = data.wedki || [];
      this.renderRods();
      if (this.activeCases.length) this.renderCases();
    } catch (err) {
      if (container) container.innerHTML = `<p class="hint error-text">${esc(err.message)}</p>`;
    }
  },

  rodCasts(rodId) {
    return this.inventory
      .filter(item => Number(item.wedka_id) === Number(rodId))
      .reduce((sum, item) => sum + Number(item.zarzuty_pozostale || 0), 0);
  },

  rodForItem(przedmiotId) {
    return this.activeRods.find(r => Number(r.przedmiot_id) === Number(przedmiotId)) || null;
  },

  renderRods() {
    const container = document.getElementById('rods-container');
    if (!container) return;

    if (!this.activeRods.length) {
      container.innerHTML = '<p class="hint">Brak dostępnych wędek.</p>';
      return;
    }

    container.innerHTML = this.activeRods.map(rod => {
      const casts = this.rodCasts(rod.id);
      const owned = casts > 0;
      return `
        <div class="rod-card ${owned ? '' : 'is-locked'}">
          <div>
            <div class="rod-head">
              <span class="rod-title">
                <img class="rod-icon" src="${esc(rod.ikona || DEFAULT_ICON)}" alt="">
                <span class="rod-name">${esc(rod.nazwa)}</span>
              </span>
              <span class="rod-casts ${owned ? '' : 'is-empty'}">${owned ? castsLabel(casts) : 'Brak'}</span>
            </div>
            <p class="rod-desc">${esc(rod.opis || '')}</p>
            <div class="rod-pool">${poolIcons(rod.przedmioty)}</div>
          </div>
          ${owned
            ? `<button class="btn btn-primary btn-cast-rod" data-rod-id="${rod.id}" ${this.isCasting ? 'disabled' : ''}>Zarzuć wędkę</button>`
            : '<button class="btn btn-ghost btn-get-rod" type="button">Zdobądź w skrzynkach 📦</button>'}
        </div>
      `;
    }).join('');

    container.querySelectorAll('.btn-cast-rod').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        this.castFishingRod(Number(btn.dataset.rodId));
      });
    });
    container.querySelectorAll('.btn-get-rod').forEach(btn => {
      btn.addEventListener('click', () => this.switchMode('cases'));
    });

    if (!this.isCasting) {
      const selected = this.activeRods.find(r => Number(r.id) === Number(this.selectedRodId) && this.rodCasts(r.id) > 0)
        || this.activeRods.find(r => this.rodCasts(r.id) > 0)
        || this.activeRods[0];
      if (selected) this.selectRodInScene(selected.id);
    }
  },

  async selectRodInScene(rodId) {
    const rod = this.activeRods.find(r => Number(r.id) === Number(rodId));
    if (!rod) return;
    this.selectedRodId = rod.id;
    await customElements.whenDefined('fishing-scene');
    const scene = document.getElementById('main-fishing-scene');
    if (scene && typeof scene.select === 'function') {
      scene.select(rod.id, { nazwa: rod.nazwa, zarzuty: this.rodCasts(rod.id) });
    }
  },

  async castFishingRod(rodId) {
    if (this.isCasting) return;
    if (!this.requireLogin('Zaloguj się, aby łowić.')) return;

    if (this.rodCasts(rodId) <= 0) {
      showToast('Nie masz tej wędki — zdobądź ją w skrzynkach.', 'error');
      SoundFx.loss();
      return;
    }

    if (!(await this.ensureFreshToken())) return;

    await customElements.whenDefined('fishing-scene');
    const scene = document.getElementById('main-fishing-scene');
    if (!scene) return;

    this.isCasting = true;
    document.querySelectorAll('.btn-cast-rod').forEach(b => { b.disabled = true; });
    await this.selectRodInScene(rodId);
    scene.scrollIntoView({ behavior: 'smooth', block: 'center' });

    try {
      SoundFx.splash();
      await scene.cast(rodId);
    } catch (err) {
      showToast(err.message, 'error');
    } finally {
      this.isCasting = false;
      this.renderRods();
    }
  },

  bindSceneControls() {
    customElements.whenDefined('fishing-scene').then(() => {
      const scene = document.getElementById('main-fishing-scene');
      if (!scene) return;

      scene.addEventListener('catch', (event) => {
        this.setSaldo(event.detail.payload.saldo_po);
        SoundFx.fanfare();
        this.refreshPlayerData();
      });

      scene.addEventListener('castrequest', () => {
        const rod = this.activeRods.find(r => this.rodCasts(r.id) > 0);
        if (rod) {
          this.castFishingRod(rod.id);
        } else if (this.requireLogin('Zaloguj się, aby łowić.')) {
          showToast('Nie masz żadnej wędki — zdobądź ją w skrzynkach.', 'error');
        }
      });

      scene.addEventListener('error', async (event) => {
        const { kod, status } = event.detail || {};
        if (status !== 401 && kod !== 'unauthorized') return;

        const result = await this.refreshAccessToken();
        if (result === 'ok') {
          showToast('Sesja odświeżona — możesz zarzucić ponownie.', 'info');
        } else if (result === 'expired') {
          this.handleSessionExpired();
        } else {
          showToast('Brak połączenia z serwerem — spróbuj ponownie.', 'error');
        }
      });
    });
  },

  async loadCases() {
    const container = document.getElementById('cases-container');
    try {
      const data = await this.api.fetch('skrzynki.php', { auth: false });
      this.activeCases = data.skrzynki || [];
      this.renderCases();
    } catch (err) {
      if (container) container.innerHTML = `<p class="hint error-text">${esc(err.message)}</p>`;
    }
  },

  renderCases() {
    const container = document.getElementById('cases-container');
    if (!container) return;

    if (!this.activeCases.length) {
      container.innerHTML = '<p class="hint">Brak dostępnych skrzynek.</p>';
      return;
    }

    const withCasts = (items) => (items || []).map(item => {
      const rod = this.rodForItem(item.przedmiot_id);
      return rod
        ? { ...item, nazwa: `${item.nazwa} · ${castsLabel(rod.limit_zarzutow)}`, ikona: item.ikona || rod.ikona }
        : item;
    });

    container.innerHTML = this.activeCases.map(caseItem => `
      <div class="case-card">
        <div>
          <div class="case-card-header">
            <div class="case-icon-box">📦</div>
            <div class="case-meta">
              <h4>${esc(caseItem.nazwa)}</h4>
              <div class="case-cost">${formatMoney(caseItem.cena)} 🪙</div>
            </div>
          </div>
          <p class="rod-desc">${esc(caseItem.opis || '')}</p>
          <div class="rod-pool">${poolIcons(withCasts(caseItem.przedmioty))}</div>
        </div>
        <div>
          <label class="case-options-row">
            <input type="checkbox" id="case-sell-check-${caseItem.id}">
            <span>Sprzedaj od razu po otwarciu</span>
          </label>
          <button class="btn btn-primary btn-open-case" data-case-id="${caseItem.id}">Otwórz skrzynkę</button>
        </div>
      </div>
    `).join('');
  },
};