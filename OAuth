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
    bindPoolTooltip();
  }

  readUrlParams() {
    const params = new URLSearchParams(window.location.search);
    return { code: params.get('code'), loginError: params.get('login_error') };
  }

  clearUrlParams() {
    const url = new URL(window.location.href);
    url.searchParams.delete('code');
    url.searchParams.delete('login_error');
    if (!url.searchParams.toString()) url.search = '';
    window.history.replaceState({}, document.title, url.pathname + url.search + url.hash);
  }

  loginErrorMessage(raw) {
    const known = {
      invalid_credentials: 'Nieprawidłowy login lub hasło.',
      account_blocked: 'Konto jest zablokowane.',
      unauthorized: 'Nieprawidłowy login lub hasło.',
      access_denied: 'Autoryzacja została odrzucona.',
      invalid_grant: 'Kod autoryzacyjny jest nieprawidłowy lub wygasł.',
      invalid_request: 'Nieprawidłowe żądanie autoryzacji.',
      server_error: 'Błąd serwera — spróbuj ponownie.',
    };
    const key = String(raw ?? '').trim();
    if (!key) return 'Logowanie nie udało się.';
    return known[key] || `Logowanie nie udało się (${key}).`;
  }

  async beginOAuth(login, password) {
    const data = await this.api.fetch('login.php', {
      method: 'POST',
      body: { login, password },
      auth: false,
    });

    if (!data.code) {
      const err = new Error('Serwer nie zwrócił kodu autoryzacyjnego.');
      err.kod = 'invalid_grant';
      throw err;
    }

    return this.exchangeCode(data.code);
  }

  async exchangeCode(code) {
    const data = await tokenRequest({ grant_type: 'authorization_code', code });

    if (!data.access_token) {
      const err = new Error('Serwer nie zwrócił tokenu dostępu.');
      err.kod = 'invalid_grant';
      throw err;
    }

    this.storeTokens(data);

    if (data.user) {
      this.user = data.user;
      this.saldo = data.user.saldo ?? 0;
    }

    this.renderSessionState();
    return data;
  }

  async refreshAccessToken() {
    const refreshToken = getRefreshToken();
    if (!refreshToken) return 'expired';
    if (this.refreshPromise) return this.refreshPromise;

    this.refreshPromise = (async () => {
      try {
        const data = await tokenRequest({ grant_type: 'refresh_token', refresh_token: refreshToken });
        this.storeTokens(data);
        if (data.user) {
          this.user = data.user;
          this.saldo = data.user.saldo ?? this.saldo;
        }
        this.renderSessionState();
        return 'ok';
      } catch (err) {
        if (err.kod === 'network_error' || err.kod === 'bad_response') return 'offline';
        return 'expired';
      }
    })();

    try {
      return await this.refreshPromise;
    } finally {
      this.refreshPromise = null;
    }
  }

  async ensureFreshToken() {
    if (!getAccessToken() && !getRefreshToken()) return false;

    const expiresAt = getAccessExpiresAt();
    if (getAccessToken() && (!expiresAt || expiresAt - Date.now() > REFRESH_MARGIN_MS)) {
      return true;
    }

    if (getAccessToken() && !getRefreshToken()) return true;

    const result = await this.refreshAccessToken();
    if (result === 'ok') return true;
    if (result === 'expired') this.handleSessionExpired();
    return false;
  }

  storeTokens(data) {
    storageSet(ACCESS_KEY, data.access_token);
    if (data.refresh_token) storageSet(REFRESH_KEY, data.refresh_token);
    const seconds = Number(data.expires_in) > 0 ? Number(data.expires_in) : 3600;
    storageSet(EXPIRES_KEY, String(Date.now() + seconds * 1000));
  }

  clearTokens() {
    storageRemove(ACCESS_KEY);
    storageRemove(REFRESH_KEY);
    storageRemove(EXPIRES_KEY);
  }

  handleSessionExpired() {
    if (!this.user && !getAccessToken() && !getRefreshToken()) return;

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
    showToast('Sesja wygasła — zaloguj się ponownie.', 'error');
  }

  async completeOAuthRedirect(code) {
    try {
      await this.exchangeCode(code);
      await this.loadMe();
      showToast(`Witaj, ${this.user?.login || 'graczu'}!`, 'success');
      SoundFx.coin();
    } catch (err) {
      const message = this.loginErrorMessage(err.kod || err.message);
      this.setAuthError(message);
      showToast(message, 'error');
      this.switchAuthTab('login');
      this.openModal('auth-modal');
    }
  }
}
