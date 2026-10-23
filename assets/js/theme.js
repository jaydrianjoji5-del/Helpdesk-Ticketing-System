(() => {
  const root = document.documentElement;
  const buttons = () => document.querySelectorAll('.theme-toggle');

  function setTheme(theme) {
    const dark = theme === 'dark';
    root.dataset.theme = dark ? 'dark' : 'light';
    try { localStorage.setItem('helpdesk-theme', root.dataset.theme); } catch (error) { /* Keep the current page usable if storage is disabled. */ }
    buttons().forEach(button => {
      button.setAttribute('aria-label', `Switch to ${dark ? 'light' : 'dark'} theme`);
      button.innerHTML = `<i class="mdi ${dark ? 'mdi-white-balance-sunny' : 'mdi-weather-night'}" aria-hidden="true"></i>`;
    });
  }

  setTheme(root.dataset.theme);
  document.addEventListener('click', event => {
    const button = event.target.closest('.theme-toggle');
    if (!button) return;

    const nextTheme = root.dataset.theme === 'dark' ? 'light' : 'dark';
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduceMotion) {
      setTheme(nextTheme);
      return;
    }

    root.classList.add('theme-transitioning');
    window.requestAnimationFrame(() => setTheme(nextTheme));
    window.setTimeout(() => root.classList.remove('theme-transitioning'), 600);
  });
})();
