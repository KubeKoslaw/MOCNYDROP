import { MocnyDropApp } from './start.js';

document.addEventListener('DOMContentLoaded', async () => {
  window.app = new MocnyDropApp();
  await window.app.init();
});