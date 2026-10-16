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
  toast.innerHTML = `<span>${esc(message)}</span><button style="background:transparent;border:none;color:inherit;cursor:pointer;font-weight:bold">&times;</button>`;

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
    this.upgradeInputId = null;
    this.upgradeTargetId = null;
    this.upgradePreviewSeq = 0;
    this.pendingCases = new Map();
    this.caseTimer = null;
    this.serverOffset = 0;
  }

  async init() {
    this.bindNavigation();
    this.bindAuthModals();
    this.bindSceneControls();
    document.getElementById('btn-use-won-rod')?.addEventListener('click', () => {
      const id = Number(document.getElementById('btn-use-won-rod').dataset.rodId);
      this.closeModal('win-modal');
      this.switchMode('fish');
      this.selectRodInScene(id);
    });
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
        this.loadHistory(), this.loadRods(), this.loadCases()
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
    this.pendingCases.clear();
    this.upgradeInputId = null;
    this.upgradeTargetId = null;
    this.tradeSelection.clear();
    this.updateUpgraderInputSelect();
    await Promise.allSettled([this.loadRods(), this.loadCases()]);
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

  switchMode(modeId) {
    this.currentMode = modeId;

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
          <button type="button" class="fish-chip" aria-label="${esc(fish.nazwa)}, szansa ${Number(fish.szansa_procent)}%">
            <img src="${esc(fish.ikona || 'assets/icons/anchovy.png')}" alt="" loading="lazy">
            <span class="fish-tooltip" role="tooltip">${esc(fish.nazwa)} · ${Number(fish.szansa_procent)}%</span>
          </button>
        `).join('');
        return `
          <div class="rod-card">
            <div>
              <div class="rod-head">
                <span class="rod-name"><img class="rod-icon" src="${esc(rod.ikona || 'assets/icons/rod.svg')}" alt="">${esc(rod.nazwa)}</span>
                <span class="rod-price">${formatMoney(rod.koszt_uzycia)} 🪙</span>
              </div>
              <p class="rod-desc">${esc(rod.opis || '')}</p>
              <div class="rod-pool">${chips}</div>
            </div>
            <button class="btn btn-primary btn-cast-rod" data-rod-id="${rod.id}" ${this.user && !rod.posiadana ? 'disabled' : ''}>
              ${this.user && !rod.posiadana ? '🔒 Zdobądź ze skrzynki' : `Zarzuć wędkę (${formatMoney(rod.koszt_uzycia)} 🪙)`}
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
      const available = this.activeRods.find(r => r.posiadana || r.czy_startowa);
      if (available) this.selectRodInScene(available.id);
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
    if (!rod?.posiadana) {
      showToast('Zdobądź tę wędkę ze skrzynki.', 'info');
      return;
    }
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
    if (!container) return;
    try {
      const data = await this.api.fetch('skrzynki.php');
      this.serverOffset = data.czas_serwera * 1000 - Date.now();
      this.activeCases = data.skrzynki || [];
      container.innerHTML = this.activeCases.map(c => `
        <article class="case-card">
          <div class="case-card-header"><div class="case-icon-box">${c.dzienna ? '🎁' : '📦'}</div>
            <div><h4>${esc(c.nazwa)}</h4><span>${c.dzienna ? 'Bezpłatnie co 24 godziny' : formatMoney(c.cena) + ' 🪙'}</span></div>
          </div>
          <p>${esc(c.opis)}</p>
          <div class="case-rod-pool">${c.wedki.map(r => `
            <div class="case-rod" style="--rarity:${esc(r.kolor)}">
              <img src="${esc(r.ikona || 'assets/icons/rod.svg')}" alt="" loading="lazy">
              <span>${esc(r.nazwa)}</span><small>${Number(r.szansa_procent)}%</small>
              <small>Duplikat: ${formatMoney(r.rekompensata)} 🪙</small>
            </div>`).join('')}</div>
          <button type="button" class="btn btn-primary btn-open-case" data-case-id="${c.id}">Otwórz skrzynkę</button>
        </article>`).join('') || '<p class="hint">Brak aktywnych skrzynek.</p>';
      container.querySelectorAll('.btn-open-case').forEach(btn => btn.addEventListener('click', () => this.openCase(Number(btn.dataset.caseId), btn)));
      clearInterval(this.caseTimer);
      this.updateCaseTimers();
      this.caseTimer = setInterval(() => this.updateCaseTimers(), 1000);
    } catch (err) {
      container.textContent = `Błąd ładowania skrzynek: ${err.message}`;
    }
  }

  updateCaseTimers() {
    const now = (Date.now() + this.serverOffset) / 1000;
    document.querySelectorAll('.btn-open-case').forEach(btn => {
      if (btn.dataset.busy === 'true') return;
      const c = this.activeCases.find(c => c.id === Number(btn.dataset.caseId));
      const remaining = Math.max(0, Math.ceil((c?.nastepny_odbior || 0) - now));
      const pending = this.user && this.pendingCases.has(`${this.user.id}:${c?.id}`);
      btn.disabled = Boolean(c?.dzienna && this.user && remaining > 0 && !pending);
      btn.textContent = pending ? 'Sprawdź wynik otwarcia' : btn.disabled
        ? `Dostępna za ${Math.floor(remaining / 3600)}h ${Math.floor(remaining % 3600 / 60)}m ${remaining % 60}s`
        : !this.user ? 'Zaloguj się, aby otworzyć' : c?.dzienna ? 'Odbierz darmową skrzynkę' : `Otwórz (${formatMoney(c?.cena)} 🪙)`;
    });
  }

  async openCase(caseId, button) {
    if (!this.user) { this.openModal('auth-modal'); return; }
    if (button.dataset.busy === 'true') return;
    const key = `${this.user.id}:${caseId}`;
    if (!this.pendingCases.has(key)) this.pendingCases.set(key, (crypto.randomUUID?.() ?? Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('')));
    button.disabled = true;
    button.dataset.busy = 'true';
    button.textContent = 'Otwieranie…';
    try {
      const data = await this.api.fetch('open_case.php', { method: 'POST', body: { skrzynka_id: caseId, request_id: this.pendingCases.get(key) } });
      this.pendingCases.delete(key);
      this.saldo = data.saldo_po;
      this.renderSessionState();
      SoundFx.fanfare();
      this.showWinModal({ ...data.nagroda, wartosc: data.rekompensata }, data.message, data.otwarcie.cena);
      const use = document.getElementById('btn-use-won-rod');
      use.hidden = false;
      use.dataset.rodId = data.nagroda.wedka_id;
      await this.loadMe();
    } catch (err) {
      if (err.status >= 400 && err.status < 500 && err.kod !== 'bad_response') this.pendingCases.delete(key);
      showToast(err.message, 'error');
      await this.loadCases();
    } finally {
      delete button.dataset.busy;
      this.updateCaseTimers();
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
    document.getElementById('btn-up-calc')?.addEventListener('click', () => this.calculateUpgradeChance());
    document.getElementById('btn-up-run')?.addEventListener('click', () => this.runUpgrade());
  }

  updateUpgraderInputSelect() {
    if (!this.inventory.some(i => i.ekwipunek_id === this.upgradeInputId)) this.upgradeInputId = null;
    this.renderUpgradePickers();
  }

  updateUpgraderTargetSelect() { this.renderUpgradePickers(); }

  renderUpgradePickers() {
    const input = this.inventory.find(i => i.ekwipunek_id === this.upgradeInputId);
    const targets = this.catalog.filter(i => input && Number(i.wartosc) > Number(input.wartosc));
    if (!targets.some(i => i.przedmiot_id === this.upgradeTargetId)) this.upgradeTargetId = null;
    const card = (item, id, selected, side) => `
      <button type="button" class="upgrade-choice ${selected ? 'selected' : ''}" data-up-${side}="${id}"
        aria-pressed="${selected}" style="--rarity:${esc(item.kolor)}">
        <img src="${esc(item.ikona || 'assets/icons/anchovy.png')}" alt="" loading="lazy">
        <span>${esc(item.nazwa)}</span><strong>${formatMoney(item.wartosc)} 🪙</strong>
      </button>`;
    const grid = document.getElementById('up-input-grid');
    const targetGrid = document.getElementById('up-target-grid');
    if (!grid || !targetGrid) return;
    grid.disabled = targetGrid.disabled = this.isUpgrading;
    grid.innerHTML = this.inventory.map(i => card(i, i.ekwipunek_id, i.ekwipunek_id === this.upgradeInputId, 'input')).join('') || '<p class="hint">Złów rybę, aby wybrać wkład.</p>';
    targetGrid.innerHTML = targets.map(i => card(i, i.przedmiot_id, i.przedmiot_id === this.upgradeTargetId, 'target')).join('') || '<p class="hint">Wybierz wkład, aby zobaczyć dostępne cele.</p>';
    grid.querySelectorAll('[data-up-input]').forEach(btn => btn.addEventListener('click', () => {
      if (this.isUpgrading) return;
      this.upgradeInputId = Number(btn.dataset.upInput);
      this.upgradeTargetId = null;
      this.renderUpgradePickers();
      this.calculateUpgradeChance();
    }));
    targetGrid.querySelectorAll('[data-up-target]').forEach(btn => btn.addEventListener('click', () => {
      if (this.isUpgrading) return;
      this.upgradeTargetId = Number(btn.dataset.upTarget);
      this.renderUpgradePickers();
      this.calculateUpgradeChance();
    }));
    const target = targets.find(i => i.przedmiot_id === this.upgradeTargetId);
    for (const [side, item] of [['input', input], ['target', target]]) {
      const preview = document.getElementById(`up-${side}-preview`);
      if (preview) preview.textContent = item ? `Wybrano: ${item.nazwa}` : 'Wybierz kartę powyżej';
    }
    if (!input || !target) document.getElementById('btn-up-run').disabled = true;
  }

  async calculateUpgradeChance() {
    if (this.isUpgrading) return;
    const seq = ++this.upgradePreviewSeq;
    const button = document.getElementById('btn-up-run');
    const value = document.getElementById('wheel-chance-val');
    const label = document.getElementById('wheel-chance-lbl');
    const gauge = document.getElementById('wheel-gauge-val');
    const desc = document.getElementById('up-calc-desc');
    button.disabled = true;
    value.className = 'wheel-chance-val';
    label.className = 'wheel-chance-lbl';
    label.textContent = 'Szansa';
    document.getElementById('upgrader-wheel-gauge').classList.remove('is-win', 'is-lose');
    if (!this.upgradeInputId || !this.upgradeTargetId) {
      value.textContent = '0%';
      gauge.style.strokeDashoffset = '502.65';
      desc.textContent = 'Wybierz wkład i cenniejszy cel.';
      return;
    }
    value.textContent = '…';
    try {
      const data = await this.api.fetch(`upgrade.php?ekwipunek_id=${this.upgradeInputId}&cel_przedmiot_id=${this.upgradeTargetId}`);
      if (seq !== this.upgradePreviewSeq || this.isUpgrading) return;
      const chance = Number(data.szansa_procent);
      value.textContent = `${chance.toFixed(2)}%`;
      gauge.style.strokeDashoffset = String(502.65 * (1 - chance / 100));
      desc.textContent = 'Porażka niszczy wybrany wkład.';
      button.disabled = false;
    } catch (err) {
      if (seq !== this.upgradePreviewSeq || this.isUpgrading) return;
      value.textContent = '—';
      desc.textContent = err.message;
    }
  }

  async runUpgrade() {
    if (this.isUpgrading || !this.upgradeInputId || !this.upgradeTargetId) return;
    this.isUpgrading = true;
    ++this.upgradePreviewSeq;
    const inputId = this.upgradeInputId, targetId = this.upgradeTargetId;
    const run = document.getElementById('btn-up-run');
    const calc = document.getElementById('btn-up-calc');
    const value = document.getElementById('wheel-chance-val');
    const label = document.getElementById('wheel-chance-lbl');
    const desc = document.getElementById('up-calc-desc');
    const gauge = document.getElementById('upgrader-wheel-gauge');
    run.disabled = calc.disabled = true;
    this.renderUpgradePickers();
    gauge.classList.remove('is-win', 'is-lose');
    value.className = 'wheel-chance-val spinning';
    value.textContent = '…';
    label.className = 'wheel-chance-lbl';
    label.textContent = 'Losowanie';
    desc.textContent = 'Oczekiwanie na wynik…';
    run.textContent = 'Losowanie…';
    try {
      const data = await this.api.fetch('upgrade.php', { method: 'POST', body: { ekwipunek_id: inputId, cel_przedmiot_id: targetId } });
      const pointer = document.getElementById('wheel-pointer');
      const target = Number(data.kolo) * 3.6;
      const norm = ((this.wheelCurrentRotation % 360) + 360) % 360;
      const rotation = this.wheelCurrentRotation + 1800 + ((target - norm + 360) % 360);
      const duration = matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 4200;
      pointer.style.transition = `transform ${duration}ms cubic-bezier(0.12, 0.85, 0.25, 1)`;
      pointer.style.transform = `rotate(${rotation}deg)`;
      this.wheelCurrentRotation = rotation;
      SoundFx.spinStart();
      desc.textContent = 'Koło kręci się…';
      // W czasie obrotu nie wyświetlamy procentu kąta ani wyniku losowania.
      await new Promise(resolve => setTimeout(resolve, duration));
      const result = data.udane ? 'win' : 'lose';
      gauge.classList.add(`is-${result}`);
      value.className = `wheel-chance-val ${result}`;
      value.textContent = data.udane ? '✓' : '×';
      label.textContent = data.udane ? 'Wygrana!' : 'Porażka';
      desc.textContent = data.udane ? `Zdobyto: ${data.przedmiot.nazwa}` : 'Wkład został utracony.';
      if (data.udane) {
        SoundFx.fanfare();
        this.showWinModal(data.przedmiot, `Udane ulepszenie: ${data.przedmiot.nazwa}!`);
      } else { SoundFx.loss(); }
      await Promise.allSettled([this.loadInventory(), this.loadHistory()]);
    } catch (err) {
      value.className = 'wheel-chance-val';
      value.textContent = '—';
      label.textContent = 'Błąd';
      desc.textContent = err.message;
      showToast(err.message, 'error');
      await this.loadInventory();
    } finally {
      this.isUpgrading = false;
      run.textContent = 'Uruchom Upgrader ⚡';
      calc.disabled = false;
      this.renderUpgradePickers();
      // Po sukcesie wkład znika; po błędzie wymagamy ponownej kalkulacji.
      run.disabled = true;
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
      grid.innerHTML = '<p class="hint">Twój ekwipunek jest pusty. Złów ryby w łowisku.</p>';
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
    document.getElementById('btn-use-won-rod').hidden = true;

    document.getElementById('win-modal-img').src = fish.ikona || 'assets/icons/anchovy.png';
    const nameEl = document.getElementById('win-modal-name');
    nameEl.textContent = fish.nazwa;
    nameEl.style.color = fish.kolor;

    const rarityEl = document.getElementById('win-modal-rarity');
    rarityEl.textContent = fish.rzadkosc;
    rarityEl.style.background = `${fish.kolor}22`;
    rarityEl.style.color = fish.kolor;
    rarityEl.style.border = `1px solid ${fish.kolor}66`;

    document.getElementById('win-modal-value-label').textContent = fish.typ === 'wedka' ? 'Rekompensata:' : 'Wartość rynkowa:';
    document.getElementById('win-modal-val').textContent = fish.typ === 'wedka' && !fish.wartosc ? 'Nowa wędka' : `${formatMoney(fish.wartosc)} 🪙`;
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
