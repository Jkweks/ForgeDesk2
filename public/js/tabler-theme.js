(function () {
  const storageKey = 'tablerTheme';
  const themeAttributeTarget = document.documentElement;
  const prefersDark = window.matchMedia('(prefers-color-scheme: dark)');

  const getStoredTheme = () => {
    const stored = localStorage.getItem(storageKey);
    if (stored === 'light' || stored === 'dark' || stored === 'auto') {
      return stored;
    }
    return 'auto';
  };

  const resolveTheme = (theme) => {
    if (theme === 'auto') {
      return prefersDark.matches ? 'dark' : 'light';
    }
    return theme;
  };

  const applyTheme = (theme) => {
    const resolvedTheme = resolveTheme(theme);
    themeAttributeTarget.setAttribute('data-bs-theme', resolvedTheme);
    themeAttributeTarget.setAttribute('data-pref-theme', theme);
  };

  const updateControls = (theme) => {
    document.querySelectorAll('[data-tabler-theme-value]').forEach((control) => {
      const value = control.getAttribute('data-tabler-theme-value');
      control.checked = value === theme;
    });
  };

  const setTheme = (theme) => {
    const safeTheme = theme === 'light' || theme === 'dark' || theme === 'auto' ? theme : 'auto';
    localStorage.setItem(storageKey, safeTheme);
    applyTheme(safeTheme);
    updateControls(safeTheme);
  };

  applyTheme(getStoredTheme());

  prefersDark.addEventListener('change', () => {
    if (getStoredTheme() === 'auto') {
      applyTheme('auto');
    }
  });

  window.TablerTheme = {
    setTheme,
    getTheme: getStoredTheme,
  };

  document.addEventListener('DOMContentLoaded', () => {
    updateControls(getStoredTheme());

    document.querySelectorAll('[data-tabler-theme-value]').forEach((input) => {
      input.addEventListener('change', (event) => {
        const value = event.target.getAttribute('data-tabler-theme-value');
        if (value) {
          setTheme(value);
        }
      });
    });
  });
})();
