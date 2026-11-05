async loadMe() {
    try {
      const data = await this.api.fetch('me.php');
      this.user = data.user;
      this.saldo = data.saldo ?? data.user?.saldo ?? 0;
      this.renderSessionState();
      await Promise.allSettled([
        this.loadInventory(),
        this.loadHistory(),
        this.loadFreeCase(),
      ]);
    } catch (err) {
      if (err.status === 401) return;
      showToast(err.message, 'error');
    }
  }

  renderSessionState() {
    const isLogged = Boolean(this.user);
    const authBox = document.getElementById('nav-auth-box');
    const guestBox = document.getElementById('nav-guest-box');

    if (isLogged) {
      if (guestBox) guestBox.style.display = 'none';
      if (authBox) authBox.style.display = 'flex';

      const saldoEl = document.getElementById('nav-saldo-val');
      const loginEl = document.getElementById('nav-user-login');
      const roleEl = document.getElementById('nav-user-role');
      if (saldoEl) saldoEl.textContent = formatMoney(this.saldo);
      if (loginEl) loginEl.textContent = this.user.login;
      if (roleEl) {
        const role = String(this.user.rola || 'user').toLowerCase();
        roleEl.textContent = role;
        roleEl.classList.toggle('admin', role === 'admin');
      }
    } else {
      if (guestBox) guestBox.style.display = 'flex';
      if (authBox) authBox.style.display = 'none';
      this.renderFreeCase();
    }
  }

  async login(login, password) {
    this.setAuthBusy(true);
    this.clearAuthError();
    try {
      await this.beginOAuth(login, password);
      await this.loadMe();
      this.closeModal('auth-modal');
      this.resetAuthForms();
      showToast(`Witaj, ${this.user?.login || login}!`, 'success');
      SoundFx.coin();
    } catch (err) {
      const message = err.message || this.loginErrorMessage(err.kod || '');
      this.setAuthError(message);
      showToast(message, 'error');
      SoundFx.loss();
    } finally {
      this.setAuthBusy(false);
    }
  }

  async register(login, email, password) {
    try {
      const data = await this.api.fetch('register.php', {
        method: 'POST',
        body: { login, email, password },
        auth: false,
      });
      showToast(data.message || 'Konto utworzone.', 'success');
      SoundFx.fanfare();
      await this.login(login, password);
    } catch (err) {
      this.setAuthError(err.message);
      showToast(err.message, 'error');
    }
  }

  async registerDemo() {
    const suffix = Math.random().toString(36).slice(2, 8);
    const demoLogin = `gracz_${suffix}`;
    this.setAuthBusy(true);
    try {
      await this.register(demoLogin, `${demoLogin}@mocnydrop.local`, 'demo12345');
    } finally {
      this.setAuthBusy(false);
    }
  }

  async logout(userInitiated = true) {
    if (getAccessToken() && userInitiated) {
      try { await this.api.fetch('logout.php', { method: 'POST' }); } catch {}
    }

    this.clearTokens();
    this.user = null;
    this.saldo = 0;
    this.inventory = [];
    this.freeCaseReadyAt = null;
    clearInterval(this.freeCaseTimer);
    this.freeCaseTimer = null;

    this.renderSessionState();
    this.renderRods();
    this.renderHistoryTable([]);
    this.closeModal('profile-modal');

    if (userInitiated) {
      showToast('Wylogowano.', 'info');
    }
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

  bindProfile() {
    document.getElementById('btn-profile')?.addEventListener('click', () => this.openProfile());
    document.getElementById('btn-profile-logout')?.addEventListener('click', () => this.logout(true));
  }

  async openProfile() {
    if (!this.requireLogin('Zaloguj się, aby zobaczyć profil.')) return;
    const content = document.getElementById('profile-content');
    if (content) content.innerHTML = '<p class="hint">Wczytywanie profilu…</p>';
    this.openModal('profile-modal');
    await this.renderProfile();
  }

  async renderProfile() {
    const content = document.getElementById('profile-content');
    if (!content) return;

    try {
      const [me, inventory] = await Promise.all([
        this.api.fetch('me.php'),
        this.api.fetch('inventory.php'),
      ]);

      if (me.user) {
        this.user = me.user;
        this.saldo = me.saldo ?? me.user.saldo ?? this.saldo;
        this.renderSessionState();
      }

      this.inventory = inventory.ekwipunek || [];
      const summary = inventory.podsumowanie || me.ekwipunek || {};

      content.innerHTML = this.profileTemplate(this.user, summary, this.inventory);
      this.renderRods();
    } catch (err) {
      content.innerHTML = `<p class="hint error-text">${esc(err.message)}</p>`;
    }
  }

  profileTemplate(user, summary, items) {
    if (!user) {
      return '<p class="hint">Nie jesteś zalogowany.</p>';
    }

    const role = String(user.rola || 'user').toLowerCase();
    const rows = [
      ['Login', esc(user.login)],
      ['E-mail', user.email ? esc(user.email) : '—'],
      ['Rola', `<span class="user-role-pill ${role === 'admin' ? 'admin' : ''}">${esc(role)}</span>`],
      ['Saldo', `<strong class="profile-saldo">${formatMoney(this.saldo)} 🪙</strong>`],
      [
        'Ekwipunek',
        `<b>${Number(summary.liczba_przedmiotow ?? items.length)}</b> szt. · wartość
         <b>${formatMoney(summary.laczna_wartosc)} 🪙</b>`,
      ],
    ];

    const cards = items.length
      ? items.map(item => `
          <div class="fish-item-card" style="border-left-color:${esc(item.kolor)}">
            <img src="${iconOf(item)}" alt="">
            <div class="fish-name" style="color:${esc(item.kolor)}">${esc(item.nazwa)}</div>
            <div class="fish-rarity">${esc(item.rzadkosc)}</div>
            ${item.zarzuty_pozostale !== null && item.zarzuty_pozostale !== undefined
              ? `<div class="fish-casts">🎣 ${castsLabel(item.zarzuty_pozostale)}</div>` : ''}
            <div class="fish-val">${formatMoney(item.wartosc)} 🪙</div>
          </div>
        `).join('')
      : '<p class="hint profile-empty">Ekwipunek jest pusty — zarzuć wędkę albo otwórz skrzynkę.</p>';

    return `
      <dl class="profile-account">
        ${rows.map(([label, value]) => `
          <div class="profile-row">
            <dt>${label}</dt>
            <dd>${value}</dd>
          </div>
        `).join('')}
      </dl>
      <h4 class="profile-heading">🎒 Posiadane przedmioty</h4>
      <div class="inv-grid">${cards}</div>
    `;
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
