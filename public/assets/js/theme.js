// Světlý / tmavý motiv.
// Bez uložené volby se web řídí nastavením systému. Skript se načítá v <head>,
// aby se uložená volba použila ještě před vykreslením stránky (bez probliknutí).
(function () {
  var KEY = 'mh42-theme';
  var root = document.documentElement;
  var media = window.matchMedia('(prefers-color-scheme: dark)');

  try {
    var saved = localStorage.getItem(KEY);
    if (saved === 'light' || saved === 'dark') root.setAttribute('data-theme', saved);
  } catch (e) {}

  function systemTheme() { return media.matches ? 'dark' : 'light'; }
  function currentTheme() { return root.getAttribute('data-theme') || systemTheme(); }

  // Barva lišty prohlížeče (meta theme-color) podle ručně zvoleného motivu;
  // bez ruční volby platí výchozí hodnoty z <head> podle nastavení systému.
  function syncThemeColor() {
    var bg = getComputedStyle(root).getPropertyValue('--bg').trim();
    document.querySelectorAll('meta[name="theme-color"]').forEach(function (meta) {
      if (!meta.hasAttribute('data-default')) meta.setAttribute('data-default', meta.content);
      meta.content = root.hasAttribute('data-theme') ? bg : meta.getAttribute('data-default');
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var button = document.querySelector('.theme-toggle');
    if (!button) return;

    function sync() {
      button.setAttribute('aria-pressed', currentTheme() === 'dark' ? 'true' : 'false');
      syncThemeColor();
    }

    // Když volba odpovídá systému, uložená hodnota se smaže a web se zase řídí systémem.
    button.addEventListener('click', function () {
      var next = currentTheme() === 'dark' ? 'light' : 'dark';
      var followSystem = next === systemTheme();

      if (followSystem) root.removeAttribute('data-theme');
      else root.setAttribute('data-theme', next);

      try {
        if (followSystem) localStorage.removeItem(KEY);
        else localStorage.setItem(KEY, next);
      } catch (e) {}

      sync();
    });

    media.addEventListener('change', sync);
    button.hidden = false;
    sync();
  });
})();
