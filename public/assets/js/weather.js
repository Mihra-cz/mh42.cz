// Počasí na úvodní stránce: změna místa (hledání, poloha zařízení) a zapamatovaná volba.
//
// Výchozí místo vykresluje server a funguje i bez JavaScriptu. Prohlížeč komunikuje
// jen s mh42.cz (/api/pocasi/, /api/mista/), nikdy přímo s Open-Meteo. Zvolené místo
// zůstává jen v localStorage tohoto prohlížeče (název a souřadnice zaokrouhlené na
// 2 desetinná místa). Poloha zařízení se zjišťuje jen po kliknutí na tlačítko
// a před odesláním se zaokrouhlí na 2 desetinná místa (~1 km).
(function () {
  'use strict';

  var KEY = 'mh42-pocasi';
  var GEO_LABEL = 'Moje poloha';
  var TIMEOUT = 10000;

  function round2(value) { return Math.round(value * 100) / 100; }

  function isPlace(place) {
    return !!place && typeof place.label === 'string' && place.label.length > 0 && place.label.length <= 100
      && typeof place.latitude === 'number' && typeof place.longitude === 'number'
      && Math.abs(place.latitude) <= 90 && Math.abs(place.longitude) <= 180;
  }

  // Do úložiště i na server jdou jen tyto údaje, souřadnice vždy zaokrouhlené.
  function place(label, latitude, longitude) {
    return { label: label, latitude: round2(latitude), longitude: round2(longitude) };
  }

  function loadPlace() {
    try {
      var saved = JSON.parse(localStorage.getItem(KEY));
      return isPlace(saved) ? place(saved.label, saved.latitude, saved.longitude) : null;
    } catch (e) {
      return null;
    }
  }

  function savePlace(value) {
    try { localStorage.setItem(KEY, JSON.stringify(value)); } catch (e) {}
  }

  function forgetPlace() {
    try { localStorage.removeItem(KEY); } catch (e) {}
  }

  // POST s JSON tělem na vlastní web; chyba nese stav odpovědi (0 = síť nebo timeout).
  function post(url, body) {
    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, TIMEOUT);
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(body),
      credentials: 'same-origin',
      cache: 'no-store',
      signal: controller.signal
    }).then(function (response) {
      if (!response.ok) throw { status: response.status };
      return response.json();
    }).catch(function (error) {
      throw { status: (error && error.status) || 0 };
    }).finally(function () {
      clearTimeout(timer);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var root = document.querySelector('[data-weather]');
    if (!root) return;

    function part(name) { return root.querySelector('[data-weather-' + name + ']'); }
    var locationEl = part('location');
    var dataEl = part('data');
    var statusEl = part('status');
    var toggle = part('toggle');
    var reset = part('reset');
    var form = part('form');
    var input = part('input');
    var geo = part('geo');
    var results = part('results');

    var initial = { label: locationEl.textContent, html: dataEl.innerHTML };
    var weatherRequest = 0;   // platí jen odpověď na poslední požadavek
    var searchRequest = 0;

    function say(text) { statusEl.textContent = text; }

    function clearResults() {
      results.textContent = '';
      results.hidden = true;
    }

    function closeForm(focusToggle) {
      form.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
      clearResults();
      if (focusToggle) toggle.focus();
    }

    // Načte počasí pro místo a nahradí jen údaje v sekci; výchozí místo zůstává,
    // dokud nová data nedorazí.
    function show(target, remember) {
      var id = ++weatherRequest;
      root.setAttribute('aria-busy', 'true');
      return post('/api/pocasi/', { lat: target.latitude, lon: target.longitude }).then(function (response) {
        if (id !== weatherRequest) return;
        if (!response || typeof response.html !== 'string') throw { status: 0 };
        locationEl.textContent = target.label;
        dataEl.innerHTML = response.html;
        reset.hidden = false;
        say('');
        if (remember) savePlace(target);
        if (!form.hidden) closeForm(form.contains(document.activeElement));
      }).catch(function () {
        if (id !== weatherRequest) return;
        say('Počasí pro místo ' + target.label + ' se teď nepodařilo načíst.');
        if (!remember) reset.hidden = false;   // uložené místo nejde načíst – nabídnout návrat
      }).finally(function () {
        if (id === weatherRequest) root.removeAttribute('aria-busy');
      });
    }

    toggle.addEventListener('click', function () {
      if (form.hidden) {
        form.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        input.focus();
      } else {
        closeForm(false);
      }
    });

    form.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') closeForm(true);
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var query = input.value.replace(/\s+/g, ' ').trim();
      clearResults();
      if (query.length < 2) {
        say('Zadejte aspoň dva znaky.');
        return;
      }

      var id = ++searchRequest;
      say('Hledám…');
      post('/api/mista/', { q: query }).then(function (response) {
        if (id !== searchRequest) return;
        var found = (response && Array.isArray(response.results) ? response.results : []).filter(isPlace).slice(0, 5);
        if (found.length === 0) {
          say('Místo „' + query + '“ se nenašlo.');
          return;
        }
        if (found.length === 1) {
          show(place(found[0].label, found[0].latitude, found[0].longitude), true);
          return;
        }
        found.forEach(function (item) {
          var button = document.createElement('button');
          button.type = 'button';
          button.className = 'weather__result';
          var name = document.createElement('span');
          name.className = 'weather__result-name';
          name.textContent = item.label;
          button.appendChild(name);
          if (typeof item.detail === 'string' && item.detail) {
            var detail = document.createElement('span');
            detail.className = 'weather__result-detail';
            detail.textContent = ' · ' + item.detail;
            button.appendChild(detail);
          }
          button.addEventListener('click', function () {
            show(place(item.label, item.latitude, item.longitude), true);
          });
          var li = document.createElement('li');
          li.appendChild(button);
          results.appendChild(li);
        });
        results.hidden = false;
        say('Nalezená místa: ' + found.length + '. Vyberte jedno ze seznamu.');
      }).catch(function (error) {
        if (id !== searchRequest) return;
        say(error.status === 400 ? 'Takový název nejde vyhledat.' : 'Hledání teď nefunguje. Zkuste to později.');
      });
    });

    reset.addEventListener('click', function () {
      weatherRequest++;
      forgetPlace();
      locationEl.textContent = initial.label;
      dataEl.innerHTML = initial.html;
      root.removeAttribute('aria-busy');
      reset.hidden = true;
      say('');
      toggle.focus();
    });

    // Poloha zařízení: jen po kliknutí, nikdy při načtení stránky. Po odmítnutí
    // se o ni v této stránce znovu nežádá – tlačítko zůstane vypnuté.
    if (navigator.geolocation) {
      geo.hidden = false;
      geo.addEventListener('click', function () {
        geo.disabled = true;
        clearResults();
        say('Zjišťuji polohu…');
        navigator.geolocation.getCurrentPosition(function (position) {
          geo.disabled = false;
          show(place(GEO_LABEL, position.coords.latitude, position.coords.longitude), true);
        }, function (error) {
          var denied = error && error.code === 1;
          geo.disabled = denied;
          say(denied
            ? 'Přístup k poloze není povolený. Místo zůstává beze změny.'
            : 'Polohu se nepodařilo zjistit. Místo zůstává beze změny.');
        }, { enableHighAccuracy: false, timeout: 15000, maximumAge: 600000 });
      });
    }

    part('controls').hidden = false;

    var saved = loadPlace();
    if (saved) show(saved, false);
  });
})();
