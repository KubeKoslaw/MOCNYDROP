/**
 * =============================================================================
 * MOCNY DROP — Główny skrypt aplikacji frontendowej (public/assets/js/main.js)
 * Integruje 4 tryby gry z diagramów UML (Łowisko, Skrzynki, Upgrader, Trader),
 * konto gracza, ekwipunek, historię, monitoring RTP i panel administratora.
 * =============================================================================
 */

const TOKEN_KEY = 'mocnydrop_token';

// Syntetyzator dźwięków Web Audio API (działa bez zewnętrznych plików audio)
const SoundFx = {
  ctx: null,
  init() {
    if (!this.ctx && (window.AudioContext || window.webkitAudioContext)) {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      this.ctx = new AudioCtx();
    }
  },
  playTone(freq, type = 'sine', duration = 0.15, vol = 0.1) {
    try {
      this.init();
      if (!this.ctx || this.ctx.state === 'suspended') {
        this.ctx?.resume();
      }
      const osc = this.ctx.createOscillator();
      const gain = this.ctx.createGain();
      osc.type = type;
      osc.frequency.setValueAtTime(freq, this.ctx.currentTime);
      gain.gain.setValueAtTime(vol, this.ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, this.ctx.currentTime + duration);
      osc.connect(gain);
      gain.connect(this.ctx.destination);
      osc.start();
      osc.stop(this.ctx.currentTime + duration);
    } catch (e) {
      // Dźwięk jest opcjonalny
    }
  },
  coin() {
    this.playTone(987.77, 'triangle', 0.1, 0.12);
    setTimeout(() => this.playTone(1318.51, 'triangle', 0.2, 0.15), 80);
  },
  splash() {
    this.playTone(220, 'sine', 0.25, 0.1);
    setTimeout(() => this.playTone(330, 'sine', 0.2, 0.08), 100);
  },
  fanfare() {
    [523.25, 659.25, 783.99, 1046.50].forEach((freq, idx) => {
      setTimeout(() => this.playTone(freq, 'triangle', 0.28, 0.18), idx * 110);
    });
  },
  loss() {
    this.playTone(260, 'sawtooth', 0.2, 0.08);
    setTimeout(() => this.playTone(190, 'sawtooth', 0.35, 0.1), 160);
  },
  wheelTick(freq = 750, vol = 0.04) {
    try {
      this.init();
      if (!this.ctx || this.ctx.state === 'suspended') {
        this.ctx?.resume();
      }
      const osc = this.ctx.createOscillator();
      const gain = this.ctx.createGain();
      osc.type = 'triangle';
      osc.frequency.setValueAtTime(freq, this.ctx.currentTime);
      gain.gain.setValueAtTime(vol, this.ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.0001, this.ctx.currentTime + 0.035);
      osc.connect(gain);
      gain.connect(this.ctx.destination);
      osc.start();
      osc.stop(this.ctx.currentTime + 0.035);
    } catch (e) {}
  },
  spinStart() {
    this.playTone(320, 'sine', 0.15, 0.08);
    setTimeout(() => this.playTone(480, 'triangle', 0.2, 0.1), 80);
  }
};

// Pomocnik powiadomień Toast
function showToast(message, type = 'info') {
  const container = document.getElementById('toast-container');
  if (!container) return;

  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  toast.innerHTML = `<span>${message}</span><button style="background:transparent;border:none;color:inherit;cursor:pointer;font-weight:bold">&times;</button>`;

  toast.querySelector('button').addEventListener('click', () => toast.remove());

  container.appendChild(toast);
  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transition = 'opacity 0.3s ease';
    setTimeout(() => toast.remove(), 300);
  }, 4200);
}

// Formatowanie waluty (monet)
function formatMoney(amount) {
  return Number(amount ?? 0).toFixed(2).replace('.', ',');
}

// Bezpieczne uciekanie HTML
function esc(str) {
  const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  return String(str ?? '').replace(/[&<>"']/g, (c) => map[c]);
}

// Klient API z obsługą tokena i kodów błędów
class ApiClient {
  constructor(baseUrl = 'api/') {
    this.baseUrl = baseUrl.replace(/\/$/, '') + '/';
  }

  getToken() {
    try {
      return localStorage.getItem(TOKEN_KEY);
    } catch {
      return null;
    }
  }

  setToken(token) {
    try {
      if (token) localStorage.setItem(TOKEN_KEY, token);
      else localStorage.removeItem(TOKEN_KEY);
    } catch {}
  }

  async fetch(endpoint, options = {}) {
    const headers = { 'Content-Type': 'application/json' };
    const token = this.getToken();
    if (token) {
      headers.Authorization = `Bearer ${token}`;
    }

    const url = `${this.baseUrl}${endpoint.replace(/^\//, '')}`;
    let response;
    try {
      response = await fetch(url, {
        method: options.method || 'GET',
        headers,
        body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
      });
    } catch (netErr) {
      const err = new Error('Brak połączenia z serwerem PHP. Upewnij się, że serwer jest uruchomiony (`php -S localhost:8000`).');
      err.kod = 'network_error';
      throw err;
    }

    let data;
    try {
      data = await response.json();
    } catch (parseErr) {
      const err = new Error(`Odpowiedź nie jest poprawnym JSON-em (HTTP ${response.status})`);
      err.kod = 'bad_response';
      err.status = response.status;
      throw err;
    }

    if (!response.ok || data.success === false) {
      const err = new Error(data.error || `Błąd serwera (HTTP ${response.status})`);
      err.kod = data.kod || 'error';
      err.status = response.status;
      throw err;
    }

    return data;
  }
}

// Główny kontroler aplikacji MOCNY DROP
class MocnyDropApp {
  constructor() {
    this.api = new ApiClient('api/');
    this.user = null;
    this.saldo = 0;
    this.currentMode = 'fish'; // 'fish' | 'cases' | 'upgrade' | 'trade'
    this.activeRods = [];
    this.activeCases = [];
    this.catalog = [];
    this.inventory = [];
    this.tradeSelection = new Set();
    this.isCasting = false;
    this.cachedRarities = {};
    this.wheelCurrentRotation = 0;
    this.isUpgrading = false;
  }

  async init() {
    this.bindNavigation();
    this.bindAuthModals();
    this.bindModeCards();
    this.bindSceneControls();
    this.bindUpgraderEvents();
    this.bindTraderEvents();

    // Wczytanie danych publicznych
    await Promise.allSettled([
      this.loadRods(),
      this.loadCases(),
      this.loadCatalog(),
    ]);

    // Sprawdzenie sesji gracza
    if (this.api.getToken()) {
      await this.loadMe();
    } else {
      this.renderSessionState();
    }
  }

  // --- ZARZĄDZANIE SESJĄ I KONTEM (UC_REG, UC_LOGIN) ---

  async loadMe() {
    try {
      const data = await this.api.fetch('me.php');
      this.user = data.user;
      this.saldo = data.saldo ?? data.user.saldo;
      this.renderSessionState();
      await Promise.allSettled([
        this.loadInventory(),
        this.loadHistory()
      ]);
      if (this.user.rola === 'admin') {
        document.getElementById('nav-btn-admin')?.removeAttribute('hidden');
      }
    } catch (err) {
      if (err.status === 401) {
        this.logout(false);
      }
    }
  }

  renderSessionState() {
    const isLogged = Boolean(this.user);
    const authBox = document.getElementById('nav-auth-box');
    const guestBox = document.getElementById('nav-guest-box');
    const saldoEl = document.getElementById('nav-saldo-val');
    const userLoginEl = document.getElementById('nav-user-login');
    const userRoleEl = document.getElementById('nav-user-role');

    if (isLogged) {
      guestBox.style.display = 'none';
      authBox.style.display = 'flex';
      saldoEl.textContent = formatMoney(this.saldo);
      userLoginEl.textContent = this.user.login;
      userRoleEl.textContent = this.user.rola === 'admin' ? 'Administrator' : 'Gracz';
      userRoleEl.className = `user-role-pill ${this.user.rola === 'admin' ? 'admin' : ''}`;
    } else {
      guestBox.style.display = 'flex';
      authBox.style.display = 'none';
      document.getElementById('nav-btn-admin')?.setAttribute('hidden', '');
    }
  }

  async login(login, password) {
    try {
      const data = await this.api.fetch('login.php', {
        method: 'POST',
        body: { login, password }
      });
      this.api.setToken(data.token);
      this.user = data.user;
      this.saldo = data.user.saldo;
      this.renderSessionState();
      this.closeModal('auth-modal');
      showToast(`Witaj ponownie, ${data.user.login}! Zalogowano pomyślnie.`, 'success');
      SoundFx.coin();
      await this.loadMe();
    } catch (err) {
      showToast(err.message, 'error');
    }
  }

  async register(login, email, password) {
    try {
      const data = await this.api.fetch('register.php', {
        method: 'POST',
        body: { login, email, password }
      });
      showToast(data.message || 'Konto zarejestrowane! Otrzymujesz 50 monet na start.', 'success');
      SoundFx.fanfare();
      await this.login(login, password);
    } catch (err) {
      showToast(err.message, 'error');
    }
  }

  async registerDemo() {
    const rand = Math.random().toString(36).substring(2, 7);
    const demoLogin = `gracz_${rand}`;
    const demoPass = `demo12345`;
    const demoEmail = `${demoLogin}@mocnydrop.local`;
    showToast(`Tworzenie szybkiego konta demo: ${demoLogin}...`, 'info');
    await this.register(demoLogin, demoEmail, demoPass);
  }

  async logout(userInitiated = true) {
    if (this.api.getToken() && userInitiated) {
      try { await this.api.fetch('logout.php', { method: 'POST' }); } catch {}
    }
    this.api.setToken(null);
    this.user = null;
    this.saldo = 0;
    this.inventory = [];
    this.tradeSelection.clear();
    this.renderSessionState();
    this.renderInventory([]);
    if (userInitiated) {
      showToast('Wylogowano z konta.', 'info');
    }
  }

  // --- NAWIGACJA I PRZEŁĄCZANIE TRYBÓW GRY ---

  bindNavigation() {
    document.querySelectorAll('[data-target-mode]').forEach(btn => {
      btn.addEventListener('click', (e) => {
        const mode = btn.dataset.targetMode;
        this.switchMode(mode);
      });
    });

    document.getElementById('btn-nav-logout')?.addEventListener('click', () => this.logout(true));
  }

  bindModeCards() {
    document.querySelectorAll('.mode-card').forEach(card => {
      card.addEventListener('click', () => {
        const mode = card.dataset.mode;
        this.switchMode(mode);
      });
    });
  }

  switchMode(modeId) {
    this.currentMode = modeId;

    // Podświetlenie karty w Hubie
    document.querySelectorAll('.mode-card').forEach(c => {
      c.classList.toggle('selected', c.dataset.mode === modeId);
    });

    // Podświetlenie w pasku nawigacji
    document.querySelectorAll('[data-target-mode]').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.targetMode === modeId);
    });

    // Przełączenie widocznej areny
    document.querySelectorAll('.mode-arena-section').forEach(sec => {
      sec.style.display = 'none';
    });
    const targetSection = document.getElementById(`arena-${modeId}`);
    if (targetSection) {
      targetSection.style.display = 'block';
      targetSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  // --- TRYB 1: ŁOWISKO RYB (UC_FISH) ---

  async loadRods() {
    const container = document.getElementById('rods-container');
    try {
      const data = await this.api.fetch('wedki.php');
      this.activeRods = data.wedki || [];
      if (!this.activeRods.length) {
        container.innerHTML = '<p class="hint">Brak aktywnych wędek w bazie danych.</p>';
        return;
      }

      container.innerHTML = this.activeRods.map(rod => {
        const chips = (rod.przedmioty || []).map(fish => `
          <span class="item-chip" style="border-color:${esc(fish.kolor)}; color:${esc(fish.kolor)}">
            ${esc(fish.nazwa)} (${Number(fish.szansa_procent).toFixed(1)}%)
          </span>
        `).join('');

        return `
          <div class="rod-card">
            <div>
              <div class="rod-head">
                <span class="rod-name">${esc(rod.nazwa)}</span>
                <span class="rod-price">${formatMoney(rod.koszt_uzycia)} 🪙</span>
              </div>
              <p class="rod-desc">${esc(rod.opis || '')}</p>
              <div class="rod-pool">${chips}</div>
            </div>
            <button class="btn btn-primary btn-cast-rod" data-rod-id="${rod.id}">
              Zarzuć wędkę (${formatMoney(rod.koszt_uzycia)} 🪙)
            </button>
          </div>
        `;
      }).join('');

      container.querySelectorAll('.btn-cast-rod').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          const rodId = Number(btn.dataset.rodId);
          this.castFishingRod(rodId);
        });
      });

      // Ustawienie pierwszej wędki w scenie
      if (this.activeRods.length > 0) {
        this.selectRodInScene(this.activeRods[0].id);
      }
    } catch (err) {
      container.innerHTML = `<p class="hint" style="color:var(--accent-rose)">Błąd ładowania wędek: ${esc(err.message)}</p>`;
    }
  }

  async selectRodInScene(rodId) {
    const rod = this.activeRods.find(r => Number(r.id) === Number(rodId));
    if (!rod) return;
    await customElements.whenDefined('fishing-scene');
    const scene = document.getElementById('main-fishing-scene');
    if (scene && typeof scene.select === 'function') {
      scene.select(rod.id, { nazwa: rod.nazwa, koszt: rod.koszt_uzycia });
    }
  }

  async castFishingRod(rodId) {
    if (this.isCasting) return;
    if (!this.user) {
      showToast('Zaloguj się na konto, aby móc łowić ryby.', 'info');
      this.openModal('auth-modal');
      return;
    }

    const rod = this.activeRods.find(r => Number(r.id) === Number(rodId));
    if (rod && this.saldo < Number(rod.koszt_uzycia)) {
      showToast('Niewystarczające saldo monet na zarzut tej wędki!', 'error');
      SoundFx.loss();
      return;
    }

    await customElements.whenDefined('fishing-scene');
    const scene = document.getElementById('main-fishing-scene');
    if (!scene) return;

    this.isCasting = true;
    document.querySelectorAll('.btn-cast-rod').forEach(b => b.disabled = true);
    this.selectRodInScene(rodId);

    try {
      SoundFx.splash();
      // Kontrakt: Scena sama wykonuje zarzut przez api/fish.php,
      // przewija podwodny pasek i zatrzymuje się na wygranej rybie.
      await scene.cast(rodId);
    } catch (err) {
      showToast(err.message, 'error');
    } finally {
      this.isCasting = false;
      document.querySelectorAll('.btn-cast-rod').forEach(b => b.disabled = false);
    }
  }

  bindSceneControls() {
    customElements.whenDefined('fishing-scene').then(() => {
      const scene = document.getElementById('main-fishing-scene');
      if (!scene) return;

      // Zdarzenie 'catch' generowane przez <fishing-scene>
      scene.addEventListener('catch', async (event) => {
        const payload = event.detail.payload;
        this.saldo = payload.saldo_po;
        this.renderSessionState();
        SoundFx.fanfare();
        this.showWinModal(payload.przedmiot, payload.message, payload.rzut?.koszt);
        await Promise.allSettled([
          this.loadInventory(),
          this.loadHistory()
        ]);
      });

      // Zdarzenie 'error'
      scene.addEventListener('error', (event) => {
        showToast(event.detail.message, 'error');
      });

      document.getElementById('btn-reset-scene')?.addEventListener('click', () => {
        scene.reset();
      });

      document.getElementById('btn-toggle-lane')?.addEventListener('click', (e) => {
        const hasLane = scene.hasAttribute('show-lane');
        if (hasLane) {
          scene.removeAttribute('show-lane');
          e.currentTarget.textContent = 'Pokaż podwodną paradę ryb';
        } else {
          scene.setAttribute('show-lane', '');
          e.currentTarget.textContent = 'Ukryj podwodną paradę ryb';
        }
      });
    });
  }

  // --- TRYB 2: SKRZYNKI Z DROPEM (UC_CASE) ---

  async loadCases() {
    const container = document.getElementById('cases-container');
    try {
      const data = await this.api.fetch('skrzynki.php');
      this.activeCases = data.skrzynki || [];
      if (!this.activeCases.length) {
        container.innerHTML = '<p class="hint">Brak aktywnych skrzynek.</p>';
        return;
      }

      container.innerHTML = this.activeCases.map(caseItem => {
        const chips = (caseItem.przedmioty || []).map(item => `
          <span class="item-chip" style="border-color:${esc(item.kolor)}; color:${esc(item.kolor)}">
            ${esc(item.nazwa)} (${Number(item.szansa_procent).toFixed(1)}%)
          </span>
        `).join('');

        return `
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
              <div class="rod-pool">${chips}</div>
            </div>
            <div>
              <div class="case-options-row">
                <label style="cursor:pointer; display:flex; align-items:center; gap:8px">
                  <input type="checkbox" id="case-sell-check-${caseItem.id}">
                  <span>Sprzedaj wygraną od razu (CASE_SELL)</span>
                </label>
              </div>
              <button class="btn btn-primary btn-open-case" data-case-id="${caseItem.id}" style="width:100%; margin-top:10px">
                Otwórz skrzynkę (${formatMoney(caseItem.cena)} 🪙)
              </button>
            </div>
          </div>
        `;
      }).join('');

      container.querySelectorAll('.btn-open-case').forEach(btn => {
        btn.addEventListener('click', () => {
          const caseId = Number(btn.dataset.caseId);
          const autoSell = document.getElementById(`case-sell-check-${caseId}`)?.checked || false;
          this.openCase(caseId, autoSell, btn);
        });
      });
    } catch (err) {
      container.innerHTML = `<p class="hint" style="color:var(--accent-rose)">Błąd ładowania skrzynek: ${esc(err.message)}</p>`;
    }
  }

  async openCase(caseId, autoSell, button) {
    if (!this.user) {
      showToast('Zaloguj się, aby otwierać skrzynki.', 'info');
      this.openModal('auth-modal');
      return;
    }

    const c = this.activeCases.find(item => Number(item.id) === Number(caseId));
    if (c && this.saldo < Number(c.cena)) {
      showToast('Za mało monet na otwarcie tej skrzynki!', 'error');
      SoundFx.loss();
      return;
    }

    button.disabled = true;
    try {
      SoundFx.splash();
      const data = await this.api.fetch('open_case.php', {
        method: 'POST',
        body: { skrzynka_id: caseId, sprzedaj_natychmiast: autoSell }
      });

      this.saldo = data.saldo_po;
      this.renderSessionState();
      SoundFx.fanfare();
      this.showWinModal(data.przedmiot, data.message, data.otwarcie?.cena);
      await Promise.allSettled([
        this.loadInventory(),
        this.loadHistory()
      ]);
    } catch (err) {
      showToast(err.message, 'error');
    } finally {
      button.disabled = false;
    }
  }

  // --- TRYB 3: UPGRADER PRZEDMIOTÓW (UC_UPGRADE) ---

  async loadCatalog() {
    try {
      const data = await this.api.fetch('katalog.php');
      this.catalog = data.przedmioty || [];
      this.updateUpgraderTargetSelect();
    } catch (err) {
      console.warn('Błąd wczytywania katalogu:', err);
    }
  }

  bindUpgraderEvents() {
    const inputSelect = document.getElementById('up-input-select');
    const targetSelect = document.getElementById('up-target-select');
    const calcBtn = document.getElementById('btn-up-calc');
    const runBtn = document.getElementById('btn-up-run');

    inputSelect?.addEventListener('change', () => {
      this.updateUpgraderInputPreview();
      this.updateUpgraderTargetSelect();
      this.calculateUpgradeChance();
    });

    targetSelect?.addEventListener('change', () => {
      this.updateUpgraderTargetPreview();
      this.calculateUpgradeChance();
    });

    calcBtn?.addEventListener('click', () => this.calculateUpgradeChance());
    runBtn?.addEventListener('click', () => this.runUpgrade());
  }

  updateUpgraderInputSelect() {
    const select = document.getElementById('up-input-select');
    if (!select) return;
    const currentVal = select.value;
    select.innerHTML = '<option value="">— Wybierz rybę z ekwipunku —</option>' +
      this.inventory.map(item => `
        <option value="${item.ekwipunek_id}" data-val="${item.wartosc}" data-icon="${item.ikona}" data-name="${esc(item.nazwa)}" data-color="${item.kolor}" data-rarity="${esc(item.rzadkosc)}">
          ${esc(item.nazwa)} (${formatMoney(item.wartosc)} 🪙) [${esc(item.rzadkosc)}]
        </option>
      `).join('');

    if (currentVal) select.value = currentVal;
    this.updateUpgraderInputPreview();
    this.updateUpgraderTargetSelect();
  }

  updateUpgraderTargetSelect() {
    const targetSelect = document.getElementById('up-target-select');
    const inputOption = document.getElementById('up-input-select')?.selectedOptions[0];
    const inputVal = Number(inputOption?.dataset.val || 0);

    if (!targetSelect) return;
    // Backend odrzuca cel tańszy od wkładu (target_must_be_more_valuable)
    const validTargets = this.catalog.filter(item => Number(item.wartosc) > inputVal);

    targetSelect.innerHTML = '<option value="">— Wybierz cel z katalogu —</option>' +
      validTargets.map(item => `
        <option value="${item.przedmiot_id}" data-val="${item.wartosc}" data-icon="${item.ikona}" data-name="${esc(item.nazwa)}" data-color="${item.kolor}" data-rarity="${esc(item.rzadkosc)}">
          ${esc(item.nazwa)} (${formatMoney(item.wartosc)} 🪙) [${esc(item.rzadkosc)}]
        </option>
      `).join('');
    this.updateUpgraderTargetPreview();
  }

  updateUpgraderInputPreview() {
    const preview = document.getElementById('up-input-preview');
    const opt = document.getElementById('up-input-select')?.selectedOptions[0];
    if (opt && opt.value) {
      preview.innerHTML = `
        <img src="${opt.dataset.icon || 'assets/icons/anchovy.png'}" alt="">
        <div style="font-weight:700; color:${opt.dataset.color}">${opt.dataset.name}</div>
        <div style="font-size:0.8rem; color:var(--text-muted)">${opt.dataset.rarity}</div>
        <div style="font-weight:800; color:var(--accent-gold); margin-top:4px">${formatMoney(opt.dataset.val)} 🪙</div>
      `;
    } else {
      preview.innerHTML = '<span class="hint">Wybierz wkład z ekwipunku</span>';
    }
  }

  updateUpgraderTargetPreview() {
    const preview = document.getElementById('up-target-preview');
    const opt = document.getElementById('up-target-select')?.selectedOptions[0];
    if (opt && opt.value) {
      preview.innerHTML = `
        <img src="${opt.dataset.icon || 'assets/icons/anchovy.png'}" alt="">
        <div style="font-weight:700; color:${opt.dataset.color}">${opt.dataset.name}</div>
        <div style="font-size:0.8rem; color:var(--text-muted)">${opt.dataset.rarity}</div>
        <div style="font-weight:800; color:var(--accent-gold); margin-top:4px">${formatMoney(opt.dataset.val)} 🪙</div>
      `;
    } else {
      preview.innerHTML = '<span class="hint">Wybierz cel o wyższej wartości</span>';
    }
  }

  async calculateUpgradeChance() {
    const inputId = document.getElementById('up-input-select')?.value;
    const targetId = document.getElementById('up-target-select')?.value;
    const runBtn = document.getElementById('btn-up-run');
    const chanceValEl = document.getElementById('wheel-chance-val');
    const chanceLblEl = document.getElementById('wheel-chance-lbl');
    const gaugeValEl = document.getElementById('wheel-gauge-val');
    const gaugeContainer = document.getElementById('upgrader-wheel-gauge');
    const descEl = document.getElementById('up-calc-desc');

    // Resetowanie stanów wizualnych po poprzednim losowaniu
    if (gaugeContainer) {
      gaugeContainer.classList.remove('is-win', 'is-lose');
    }
    if (chanceValEl) {
      chanceValEl.classList.remove('win', 'lose', 'spinning');
    }
    if (chanceLblEl) {
      chanceLblEl.classList.remove('win', 'lose');
      chanceLblEl.textContent = 'Szansa';
    }

    if (!inputId || !targetId) {
      if (runBtn) runBtn.disabled = true;
      if (chanceValEl) chanceValEl.textContent = '0.0%';
      if (gaugeValEl) gaugeValEl.style.strokeDashoffset = '502.6';
      if (descEl) descEl.innerHTML = 'Wybierz wkład oraz cel o wyższej wartości.';
      return;
    }

    try {
      const data = await this.api.fetch(`upgrade.php?ekwipunek_id=${inputId}&cel_przedmiot_id=${targetId}`);
      const chance = Number(data.szansa_procent);
      if (chanceValEl) chanceValEl.textContent = `${chance.toFixed(1)}%`;

      // Obwód koła dla r=80: 2 * PI * 80 ~= 502.65
      const offset = 502.65 - (502.65 * (chance / 100));
      if (gaugeValEl) gaugeValEl.style.strokeDashoffset = String(offset);

      if (descEl) {
        descEl.innerHTML = `Szansa: <b>${chance.toFixed(2)}%</b> (Wzór: <code>(W_wkład / W_cel) × 95%</code>) • Porażka niszczy wkład!`;
      }
      if (runBtn && !this.isUpgrading) runBtn.disabled = false;
    } catch (err) {
      if (descEl) descEl.innerHTML = `<span style="color:var(--accent-rose)">${esc(err.message)}</span>`;
      if (runBtn) runBtn.disabled = true;
    }
  }

  async runUpgrade() {
    const inputSelect = document.getElementById('up-input-select');
    const targetSelect = document.getElementById('up-target-select');
    const calcBtn = document.getElementById('btn-up-calc');
    const runBtn = document.getElementById('btn-up-run');
    const pointerEl = document.getElementById('wheel-pointer');
    const wheelGauge = document.getElementById('upgrader-wheel-gauge');
    const chanceValEl = document.getElementById('wheel-chance-val');
    const chanceLblEl = document.getElementById('wheel-chance-lbl');
    const descEl = document.getElementById('up-calc-desc');

    const inputId = inputSelect?.value;
    const targetId = targetSelect?.value;

    if (!inputId || !targetId || this.isUpgrading) return;
    this.isUpgrading = true;

    // Blokada kontrolek podczas losowania
    if (runBtn) {
      runBtn.disabled = true;
      runBtn.dataset.originalText = runBtn.textContent;
      runBtn.innerHTML = '⚡ Kręcenie kołem...';
    }
    if (calcBtn) calcBtn.disabled = true;
    if (inputSelect) inputSelect.disabled = true;
    if (targetSelect) targetSelect.disabled = true;

    // Reset klas wygranej/przegranej
    if (wheelGauge) wheelGauge.classList.remove('is-win', 'is-lose');
    if (chanceValEl) {
      chanceValEl.classList.remove('win', 'lose');
      chanceValEl.classList.add('spinning');
    }
    if (chanceLblEl) {
      chanceLblEl.classList.remove('win', 'lose');
      chanceLblEl.textContent = 'LOSOWANIE...';
    }
    if (descEl) {
      descEl.innerHTML = 'Koło kręci się... trzymaj kciuki! ⚡';
    }

    // Dźwięk startu losowania
    SoundFx.spinStart();

    let animLoop = null;

    try {
      // Wysłanie żądania do API
      const fetchPromise = this.api.fetch('upgrade.php', {
        method: 'POST',
        body: { ekwipunek_id: Number(inputId), cel_przedmiot_id: Number(targetId) }
      });

      const data = await fetchPromise;

      // Kąt docelowy wirtualnego koła (0.00% – 100.00% -> 0deg – 360deg)
      const targetDeg = (Number(data.kolo) / 100) * 360;

      // Obliczenie płynnego obrotu w przód bez cofania/skoków
      const currentRot = this.wheelCurrentRotation || 0;
      const currentNorm = ((currentRot % 360) + 360) % 360;
      let delta = targetDeg - currentNorm;
      if (delta < 0) {
        delta += 360;
      }

      // Minimum 5 pełnych obrotów (1800 deg) + kąt do celu
      const totalSpin = 1800 + delta;
      const finalRotation = currentRot + totalSpin;
      this.wheelCurrentRotation = finalRotation;

      const duration = 4200; // ms czasu trwania animacji
      const startTime = performance.now();

      if (pointerEl) {
        pointerEl.style.transition = `transform ${duration}ms cubic-bezier(0.12, 0.85, 0.25, 1)`;
        pointerEl.style.transform = `rotate(${finalRotation}deg)`;
      }

      // Pętla audio tykania koła i podglądu kąta w hubie
      let lastTickAngle = currentNorm;
      let isSpinning = true;

      const tickStep = (now) => {
        if (!isSpinning) return;
        const elapsed = now - startTime;
        const progress = Math.min(1, elapsed / duration);

        if (pointerEl) {
          try {
            const transform = window.getComputedStyle(pointerEl).transform;
            if (transform && transform !== 'none') {
              const matrix = new DOMMatrix(transform);
              let angle = Math.atan2(matrix.b, matrix.a) * (180 / Math.PI);
              if (angle < 0) angle += 360;

              // Podgląd obracającego się procentu w hubie
              if (chanceValEl && progress < 0.95) {
                const livePercent = (angle / 360) * 100;
                chanceValEl.textContent = `${livePercent.toFixed(1)}%`;
              }

              // Dźwięk tykania koła przy mijaniu progów kątowych (co 18 stopni)
              let diff = angle - lastTickAngle;
              if (diff < 0) diff += 360;
              if (diff >= 18) {
                SoundFx.wheelTick(650 + Math.random() * 150);
                lastTickAngle = angle;
              }
            }
          } catch (e) {}
        }

        if (progress < 1) {
          animLoop = requestAnimationFrame(tickStep);
        }
      };
      animLoop = requestAnimationFrame(tickStep);

      // Oczekiwanie na zakończenie obrotu
      await new Promise(resolve => setTimeout(resolve, duration));
      isSpinning = false;
      if (animLoop) cancelAnimationFrame(animLoop);

      // Dokładne zatrzymanie i prezentacja wyniku
      if (chanceValEl) {
        chanceValEl.textContent = `${Number(data.kolo).toFixed(2)}%`;
        chanceValEl.classList.remove('spinning');
      }

      if (data.udane) {
        if (wheelGauge) wheelGauge.classList.add('is-win');
        if (chanceValEl) chanceValEl.classList.add('win');
        if (chanceLblEl) {
          chanceLblEl.textContent = 'WYGRANA!';
          chanceLblEl.classList.add('win');
        }
        if (descEl) {
          descEl.innerHTML = `<span class="upgrader-result-badge win">🏆 Wylosowano <b>${Number(data.kolo).toFixed(2)}%</b> (wymagane &lt; ${Number(data.szansa_procent).toFixed(2)}%) — SUKCES!</span>`;
        }
        SoundFx.fanfare();
        setTimeout(() => {
          this.showWinModal(
            data.przedmiot,
            `Sukces Upgradera! Zdobyto: ${data.przedmiot.nazwa}! (Koło: ${Number(data.kolo).toFixed(2)}% vs próg ${Number(data.szansa_procent).toFixed(2)}%)`
          );
        }, 600);
      } else {
        if (wheelGauge) wheelGauge.classList.add('is-lose');
        if (chanceValEl) chanceValEl.classList.add('lose');
        if (chanceLblEl) {
          chanceLblEl.textContent = 'PORAŻKA';
          chanceLblEl.classList.add('lose');
        }
        if (descEl) {
          descEl.innerHTML = `<span class="upgrader-result-badge lose">💥 Wylosowano <b>${Number(data.kolo).toFixed(2)}%</b> (wymagane &lt; ${Number(data.szansa_procent).toFixed(2)}%) — Porażka!</span>`;
        }
        SoundFx.loss();
        showToast(
          `Porażka! Koło zatrzymało się na ${Number(data.kolo).toFixed(2)}% (wymagane poniżej ${Number(data.szansa_procent).toFixed(2)}%). Twój wkład uległ zniszczeniu!`,
          'error'
        );
      }

      await Promise.allSettled([
        this.loadInventory(),
        this.loadHistory()
      ]);
    } catch (err) {
      if (animLoop) cancelAnimationFrame(animLoop);
      if (chanceValEl) chanceValEl.classList.remove('spinning');
      if (chanceLblEl) chanceLblEl.textContent = 'Szansa';
      showToast(err.message, 'error');
    } finally {
      this.isUpgrading = false;
      if (runBtn) {
        runBtn.disabled = false;
        runBtn.textContent = runBtn.dataset.originalText || 'Uruchom Upgrader ⚡';
      }
      if (calcBtn) calcBtn.disabled = false;
      if (inputSelect) inputSelect.disabled = false;
      if (targetSelect) targetSelect.disabled = false;
    }
  }

  // --- TRYB 4: WYMIANA W TRADERZE (UC_TRADE) ---

  bindTraderEvents() {
    document.getElementById('btn-trade-preview')?.addEventListener('click', () => this.previewTradeContract());
    document.getElementById('btn-trade-execute')?.addEventListener('click', () => this.executeTradeContract());
    document.getElementById('btn-trade-clear')?.addEventListener('click', () => {
      this.tradeSelection.clear();
      this.updateTraderState();
    });
  }

  updateTraderGrid() {
    const grid = document.getElementById('trader-inv-grid');
    if (!grid) return;

    if (!this.inventory.length) {
      grid.innerHTML = '<p class="hint">Twój ekwipunek jest pusty. Złów ryby w Łowisku lub otwórz Skrzynkę!</p>';
      return;
    }

    grid.innerHTML = this.inventory.map(item => {
      const isSelected = this.tradeSelection.has(item.ekwipunek_id);
      return `
        <div class="fish-item-card ${isSelected ? 'trade-picked' : ''}" style="border-left-color:${item.kolor}" data-eq-id="${item.ekwipunek_id}">
          <img src="${item.ikona || 'assets/icons/anchovy.png'}" alt="">
          <div class="fish-name" style="color:${item.kolor}">${esc(item.nazwa)}</div>
          <div class="fish-rarity">${esc(item.rzadkosc)}</div>
          <div class="fish-val">${formatMoney(item.wartosc)} 🪙</div>
          <label style="cursor:pointer; font-size:0.75rem; margin-top:4px">
            <input type="checkbox" class="trader-pick-checkbox" data-eq-id="${item.ekwipunek_id}" ${isSelected ? 'checked' : ''}>
            <span>Do kontraktu</span>
          </label>
        </div>
      `;
    }).join('');

    grid.querySelectorAll('.trader-pick-checkbox').forEach(cb => {
      cb.addEventListener('change', () => {
        const id = Number(cb.dataset.eqId);
        if (cb.checked) this.tradeSelection.add(id);
        else this.tradeSelection.delete(id);
        this.updateTraderState();
      });
    });
  }

  updateTraderState() {
    const count = this.tradeSelection.size;
    const selectedItems = this.inventory.filter(i => this.tradeSelection.has(i.ekwipunek_id));
    const totalVal = selectedItems.reduce((acc, i) => acc + Number(i.wartosc), 0);

    const countEl = document.getElementById('trade-count-val');
    const valEl = document.getElementById('trade-val-val');
    const previewBtn = document.getElementById('btn-trade-preview');
    const execBtn = document.getElementById('btn-trade-execute');
    const statusNotice = document.getElementById('trade-status-notice');

    if (countEl) countEl.textContent = `${count} / 10`;
    if (valEl) valEl.textContent = `${formatMoney(totalVal)} 🪙`;

    const isValidCount = count >= 3 && count <= 10;
    const isValidVal = totalVal >= 10.0;
    const isReady = isValidCount && isValidVal;

    if (previewBtn) previewBtn.disabled = !isReady;
    if (execBtn) execBtn.disabled = !isReady;

    if (statusNotice) {
      if (count < 3) {
        statusNotice.innerHTML = '<span class="gain-negative">Wymagane minimum 3 przedmioty do kontraktu.</span>';
      } else if (count > 10) {
        statusNotice.innerHTML = '<span class="gain-negative">Maksymalnie 10 przedmiotów w kontrakcie.</span>';
      } else if (!isValidVal) {
        statusNotice.innerHTML = '<span class="gain-negative">Łączna wartość wkładu poniżej 10,00 monet (błąd TRADE_ERR).</span>';
      } else {
        statusNotice.innerHTML = '<span class="gain-positive">Kontrakt spełnia kryteria! Możesz podejrzeć pulę lub wymienić.</span>';
      }
    }

    // Odświeżenie klas 'trade-picked'
    document.querySelectorAll('#trader-inv-grid .fish-item-card').forEach(c => {
      const id = Number(c.dataset.eqId);
      c.classList.toggle('trade-picked', this.tradeSelection.has(id));
    });
  }

  async previewTradeContract() {
    const ids = Array.from(this.tradeSelection);
    try {
      const data = await this.api.fetch(`trade_up.php?ekwipunek_ids=${ids.join(',')}`);
      const poolContainer = document.getElementById('trade-pool-preview');
      if (poolContainer) {
        poolContainer.innerHTML = `
          <div style="margin-bottom:8px; font-size:0.85rem">Pula wyższej rzadkości (poziom wejściowy: <b>${esc(data.poziom_wejsciowy)}</b>, łączna wartość wkładu: <b>${formatMoney(data.wartosc_wkladu)} 🪙</b>):</div>
          <div style="display:flex; flex-wrap:wrap; gap:6px">
            ${data.pula.map(p => `
              <span class="item-chip" style="border-color:${esc(p.kolor)}; color:${esc(p.kolor)}">
                ${esc(p.nazwa)} (${Number(p.szansa_procent).toFixed(1)}%) [${esc(p.rzadkosc)}]
              </span>
            `).join('')}
          </div>
        `;
      }
      showToast('Podgląd kontraktu zaktualizowany.', 'info');
    } catch (err) {
      showToast(err.message, 'error');
    }
  }

  async executeTradeContract() {
    const ids = Array.from(this.tradeSelection);
    const execBtn = document.getElementById('btn-trade-execute');
    execBtn.disabled = true;

    try {
      SoundFx.splash();
      const data = await this.api.fetch('trade_up.php', {
        method: 'POST',
        body: { ekwipunek_ids: ids }
      });

      this.tradeSelection.clear();
      SoundFx.fanfare();
      this.showWinModal(data.nagroda, `Kontrakt zrealizowany! Spalone: ${data.spalone.length} ryb. Nowa zdobycz: ${data.nagroda.nazwa}!`);

      await Promise.allSettled([
        this.loadInventory(),
        this.loadHistory()
      ]);
    } catch (err) {
      showToast(err.message, 'error');
    } finally {
      execBtn.disabled = false;
    }
  }

  // --- EKWIPUNEK I SPRZEDAŻ (POST /api/sell.php) ---

  async loadInventory() {
    if (!this.user) return;
    try {
      const data = await this.api.fetch('inventory.php');
      this.inventory = data.ekwipunek || [];
      this.renderInventory(this.inventory, data.podsumowanie);
      this.updateUpgraderInputSelect();
      this.updateTraderGrid();
      this.updateTraderState();
    } catch (err) {
      console.warn('Błąd ekwipunku:', err);
    }
  }

  renderInventory(items, summary) {
    const grid = document.getElementById('inventory-grid');
    const summaryEl = document.getElementById('inventory-summary');

    if (summaryEl && summary) {
      summaryEl.innerHTML = `Liczba ryb: <b>${summary.liczba_przedmiotow}</b> • Łączna wartość rynkowa: <b>${formatMoney(summary.laczna_wartosc)} 🪙</b>`;
    }

    if (!grid) return;
    if (!items.length) {
      grid.innerHTML = '<p class="hint" style="grid-column:1/-1; padding:20px 0; text-align:center">Twój ekwipunek jest pusty. Zarzuć wędkę w Łowisku lub otwórz Skrzynkę!</p>';
      return;
    }

    grid.innerHTML = items.map(item => `
      <div class="fish-item-card" style="border-left-color:${item.kolor}">
        <img src="${item.ikona || 'assets/icons/anchovy.png'}" alt="">
        <div class="fish-name" style="color:${item.kolor}">${esc(item.nazwa)}</div>
        <div class="fish-rarity">${esc(item.rzadkosc)}</div>
        <div class="fish-val">${formatMoney(item.wartosc)} 🪙</div>
        <button class="btn btn-secondary btn-sm btn-sell-fish" data-eq-id="${item.ekwipunek_id}" style="width:100%; margin-top:4px">
          Sprzedaj (+${formatMoney(item.wartosc)})
        </button>
      </div>
    `).join('');

    grid.querySelectorAll('.btn-sell-fish').forEach(btn => {
      btn.addEventListener('click', () => {
        const eqId = Number(btn.dataset.eqId);
        this.sellFish(eqId, btn);
      });
    });
  }

  async sellFish(eqId, button) {
    button.disabled = true;
    try {
      const data = await this.api.fetch('sell.php', {
        method: 'POST',
        body: { ekwipunek_id: eqId }
      });
      this.saldo = data.nowe_saldo;
      this.renderSessionState();
      SoundFx.coin();
      showToast(data.message, 'success');
      await Promise.allSettled([
        this.loadInventory(),
        this.loadHistory()
      ]);
    } catch (err) {
      showToast(err.message, 'error');
      button.disabled = false;
    }
  }

  // --- HISTORIA I MONITORING RTP ---

  async loadHistory() {
    if (!this.user) return;
    try {
      const data = await this.api.fetch('history.php?limit=15');
      this.renderHistoryTable(data.historia || []);
      const rtpEl = document.getElementById('history-rtp-val');
      if (rtpEl && data.rtp !== undefined) {
        rtpEl.textContent = `${(data.rtp * 100).toFixed(1)}%`;
      }
    } catch (err) {
      console.warn('Błąd historii:', err);
    }
  }

  renderHistoryTable(entries) {
    const tbody = document.getElementById('history-tbody');
    if (!tbody) return;

    if (!entries.length) {
      tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:16px" class="hint">Brak zdarzeń losowań.</td></tr>';
      return;
    }

    tbody.innerHTML = entries.map(row => {
      const gainClass = Number(row.bilans) >= 0 ? 'gain-positive' : 'gain-negative';
      const gainPrefix = Number(row.bilans) >= 0 ? '+' : '';
      return `
        <tr>
          <td>${esc(row.czas)}</td>
          <td><b>${esc(row.typ_nazwa)}</b> <span style="font-size:0.75rem; color:var(--text-muted)">(${esc(row.zrodlo || '')})</span></td>
          <td style="color:${esc(row.kolor)}; font-weight:700">
            ${esc(row.nazwa)} ${row.udane === false ? '<span class="gain-negative">(porażka)</span>' : ''}
          </td>
          <td>${formatMoney(row.koszt)} 🪙</td>
          <td class="${gainClass}">${gainPrefix}${formatMoney(row.bilans)} 🪙</td>
        </tr>
      `;
    }).join('');
  }

  // --- MODAL WYGRANEJ / ZDOBYCZY ---

  showWinModal(fish, customMessage = '', cost = 0) {
    const modal = document.getElementById('win-modal');
    if (!modal) return;

    document.getElementById('win-modal-img').src = fish.ikona || 'assets/icons/anchovy.png';
    const nameEl = document.getElementById('win-modal-name');
    nameEl.textContent = fish.nazwa;
    nameEl.style.color = fish.kolor;

    const rarityEl = document.getElementById('win-modal-rarity');
    rarityEl.textContent = fish.rzadkosc;
    rarityEl.style.background = `${fish.kolor}22`;
    rarityEl.style.color = fish.kolor;
    rarityEl.style.border = `1px solid ${fish.kolor}66`;

    document.getElementById('win-modal-val').textContent = `${formatMoney(fish.wartosc)} 🪙`;
    document.getElementById('win-modal-cost').textContent = cost ? `${formatMoney(cost)} 🪙` : '—';
    document.getElementById('win-modal-msg').textContent = customMessage;

    this.openModal('win-modal');
  }

  // --- OBSŁUGA OKIEN MODALNYCH ---

  bindAuthModals() {
    // Przyciski otwarcia
    document.getElementById('btn-nav-login')?.addEventListener('click', () => {
      this.switchAuthTab('login');
      this.openModal('auth-modal');
    });

    document.getElementById('btn-nav-register')?.addEventListener('click', () => {
      this.switchAuthTab('register');
      this.openModal('auth-modal');
    });

    document.getElementById('btn-demo-account')?.addEventListener('click', () => {
      this.registerDemo();
    });

    // Przełącznik tabów w modalu
    document.getElementById('tab-btn-login')?.addEventListener('click', () => this.switchAuthTab('login'));
    document.getElementById('tab-btn-register')?.addEventListener('click', () => this.switchAuthTab('register'));

    // Formularze
    document.getElementById('form-login')?.addEventListener('submit', (e) => {
      e.preventDefault();
      const login = document.getElementById('auth-login-val')?.value.trim();
      const pass = document.getElementById('auth-pass-val')?.value;
      if (login && pass) this.login(login, pass);
    });

    document.getElementById('form-register')?.addEventListener('submit', (e) => {
      e.preventDefault();
      const login = document.getElementById('reg-login-val')?.value.trim();
      const email = document.getElementById('reg-email-val')?.value.trim();
      const pass = document.getElementById('reg-pass-val')?.value;
      if (login && email && pass) this.register(login, email, pass);
    });

    // Zamykanie modali
    document.querySelectorAll('.modal-close-trigger').forEach(trigger => {
      trigger.addEventListener('click', () => {
        trigger.closest('.modal-backdrop')?.classList.remove('open');
      });
    });

    document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
      backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) backdrop.classList.remove('open');
      });
    });
  }

  openModal(modalId) {
    document.getElementById(modalId)?.classList.add('open');
  }

  closeModal(modalId) {
    document.getElementById(modalId)?.classList.remove('open');
  }

  switchAuthTab(tab) {
    const isLogin = tab === 'login';
    document.getElementById('tab-btn-login')?.classList.toggle('active', isLogin);
    document.getElementById('tab-btn-register')?.classList.toggle('active', !isLogin);
    document.getElementById('form-login').style.display = isLogin ? 'block' : 'none';
    document.getElementById('form-register').style.display = !isLogin ? 'block' : 'none';
  }
}

// Inicjalizacja po załadowaniu DOM
document.addEventListener('DOMContentLoaded', () => {
  const app = new MocnyDropApp();
  window.mocnyDropApp = app;
  app.init();
});
