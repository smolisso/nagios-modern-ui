(function () {
  'use strict';

  var STORAGE_KEY = 'nagios.theme';
  var LEGACY_KEY = 'theme';
  var VALID_THEMES = {
    dark: true,
    light: true
  };
  var currentTheme = 'dark';

  function normalizeTheme(theme) {
    return VALID_THEMES[theme] ? theme : 'dark';
  }

  function readStoredTheme() {
    try {
      var stored = window.localStorage.getItem(STORAGE_KEY) || window.localStorage.getItem(LEGACY_KEY);
      return normalizeTheme(stored);
    } catch (e) {
      return 'dark';
    }
  }

  function applyThemeToRoot(root, theme) {
    if (!root) {
      return;
    }

    root.classList.remove('dark', 'light');
    root.classList.add(theme);
    root.setAttribute('data-theme', theme);
  }

  function notifyThemeChange(theme) {
    try {
      window.dispatchEvent(new CustomEvent('nagios-theme-change', { detail: { theme: theme } }));
    } catch (e) {
      // Ignore event dispatch issues on older browsers.
    }
  }

  function applyTheme(theme, options) {
    options = options || {};
    var persist = options.persist !== false;
    var notify = options.notify !== false;
    currentTheme = normalizeTheme(theme);

    applyThemeToRoot(document.documentElement, currentTheme);

    if (persist) {
      try {
        window.localStorage.setItem(STORAGE_KEY, currentTheme);
        window.localStorage.setItem(LEGACY_KEY, currentTheme);
      } catch (e) {
        // Storage might be unavailable (privacy mode, policy restrictions).
      }
    }

    if (notify) {
      notifyThemeChange(currentTheme);
    }

    return currentTheme;
  }

  currentTheme = readStoredTheme();
  applyThemeToRoot(document.documentElement, currentTheme);

  window.NagiosTheme = {
    getTheme: function () {
      return currentTheme;
    },
    setTheme: function (theme) {
      return applyTheme(theme, { persist: true, notify: true });
    },
    applyTheme: function (theme) {
      return applyTheme(theme, { persist: false, notify: false });
    },
    toggleTheme: function () {
      var nextTheme = currentTheme === 'dark' ? 'light' : 'dark';
      return applyTheme(nextTheme, { persist: true, notify: true });
    }
  };

  window.addEventListener('storage', function (event) {
    if (event.key !== STORAGE_KEY && event.key !== LEGACY_KEY) {
      return;
    }

    applyTheme(event.newValue, { persist: false, notify: true });
  });
})();
