import {
  SPLASH_MS, ApiClient, migrateLegacyStorage, getAccessToken, getRefreshToken,
  showToast, fishingAndCasesMixin, sessionAndProfileMixin
} from './fishing/index.js';

class MocnyDropApp {
  constructor() {
    this.api = new ApiClient(this);
    this.user = null;
    this.saldo = 0;
    this.currentMode = 'fish';
    this.activeRods = [];
    this.activeCases = [];
    this.inventory = [];
    this.isCasting = false;
    this.selectedRodId = null;

    this.freeCaseReadyAt = null;
    this.freeCaseTimer = null;
    this.refreshPromise = null;
    this.authBusy = false;
    this.winRollTimer = null;
    this.winRollTimeout = null;
  }

  async init() {
    migrateLegacyStorage();

    this.hideSplash();
    this.bindPoolTooltip();
    this.bindNavigation();
    this.bindAuthModals();
    this.bindSceneControls();
    this.bindFreeCase();
    this.bindProfile();

    await Promise.allSettled([
      this.loadRods(),
      this.loadCases(),
    ]);

    const params = this.readUrlParams();

    if (params.code) {
      this.clearUrlParams();
      await this.completeOAuthRedirect(params.code);
    } else if (params.loginError) {
      this.clearUrlParams();
      const message = this.loginErrorMessage(params.loginError);
      showToast(message, 'error');
      this.setAuthError(message);
      this.switchAuthTab('login');
      this.openModal('auth-modal');
    } else if (getAccessToken() || getRefreshToken()) {
      await this.loadMe();
    }

    this.renderSessionState();
  }

  hideSplash() {
    const splash = document.getElementById('splash-screen');
    if (!splash) return;
    setTimeout(() => {
      splash.classList.add('is-hidden');
      setTimeout(() => splash.remove(), 600);
    }, SPLASH_MS);
  }

  bindPoolTooltip() {
    const tip = document.createElement('div');
    tip.className = 'pool-tip';
    tip.hidden = true;
    document.body.appendChild(tip);

    const show = (icon) => {
      tip.textContent = icon.dataset.tip;
      tip.style.setProperty('--rarity', icon.style.getPropertyValue('--rarity'));
      tip.hidden = false;
      const r = icon.getBoundingClientRect();
      const w = tip.offsetWidth;
      const left = Math.min(Math.max(8, r.left + r.width / 2 - w / 2), window.innerWidth - w - 8);
      const top = r.top - tip.offsetHeight - 8;
      tip.style.left = `${left}px`;
      tip.style.top = `${top < 8 ? r.bottom + 8 : top}px`;
    };
    const hide = () => { tip.hidden = true; };

    document.addEventListener('mouseover', (e) => {
      const icon = e.target.closest?.('.pool-icon');
      if (icon) show(icon); else hide();
    });
    document.addEventListener('focusin', (e) => {
      const icon = e.target.closest?.('.pool-icon');
      if (icon) show(icon); else hide();
    });
    document.addEventListener('scroll', hide, { passive: true });
  }

  requireLogin(message) {
    if (this.user) return true;
    showToast(message, 'info');
    this.switchAuthTab('login');
    this.openModal('auth-modal');
    return false;
  }

  setSaldo(value) {
    this.saldo = Number(value ?? 0);
    this.renderSessionState();
  }

  async refreshPlayerData() {
    await Promise.allSettled([
      this.loadInventory(),
      this.loadHistory(),
    ]);
  }

  bindNavigation() {
    document.querySelectorAll('[data-target-mode]').forEach(btn => {
      btn.addEventListener('click', () => this.switchMode(btn.dataset.targetMode));
    });

    document.getElementById('btn-nav-logout')?.addEventListener('click', () => this.logout(true));
  }

  switchMode(modeId) {
    this.currentMode = modeId;

    document.querySelectorAll('[data-target-mode]').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.targetMode === modeId);
    });

    document.querySelectorAll('.mode-arena-section').forEach(sec => {
      sec.style.display = sec.id === `arena-${modeId}` ? 'block' : 'none';
    });

    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  bindAuthModals() {}
  bindFreeCase() {}
  setAuthError() {}
  switchAuthTab() {}
  openModal() {}
  closeModal() {}
  resetAuthForms() {}
  renderHistoryTable() {}
  loadInventory() { return Promise.resolve(); }
  loadHistory() { return Promise.resolve(); }
  loadFreeCase() { return Promise.resolve(); }
  renderFreeCase() {}
}

Object.assign(MocnyDropApp.prototype, sessionAndProfileMixin, fishingAndCasesMixin);

document.addEventListener('DOMContentLoaded', async () => {
  window.app = new MocnyDropApp();
  await window.app.init();
});