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
    } catch {}
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
  }
};

function showToast(message, type = 'info') {
  const container = document.getElementById('toast-container');
  if (!container) return;

  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  const text = document.createElement('span');
  text.textContent = message;
  const close = document.createElement('button');
  close.className = 'toast-close';
  close.type = 'button';
  close.setAttribute('aria-label', 'Zamknij powiadomienie');
  close.innerHTML = '&times;';
  close.addEventListener('click', () => toast.remove());
  toast.append(text, close);

  container.appendChild(toast);
  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transition = 'opacity 0.3s ease';
    setTimeout(() => toast.remove(), 300);
  }, 4200);
}

function bindPoolTooltip() {
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
