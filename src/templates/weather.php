<?php
/** @var array $weather model z weather_home() */

// Ovládání změny místa je skryté; zobrazí ho až public/assets/js/weather.js.
// Bez JavaScriptu zůstane jen počasí pro výchozí místo.
?>
<section class="section weather" aria-labelledby="pocasi" data-weather>
  <div class="section__head">
    <h2 id="pocasi" class="section__title">Počasí</h2>
  </div>
  <div class="section__body">
    <div class="weather__place">
      <p class="weather__location" data-weather-location><?= e($weather['location']) ?></p>
      <p class="weather__controls" data-weather-controls hidden>
        <button class="weather__link" type="button" aria-expanded="false" aria-controls="pocasi-zmena" data-weather-toggle>změnit místo</button>
        <button class="weather__link" type="button" data-weather-reset hidden>zpět na <?= e($weather['location']) ?></button>
      </p>
    </div>
    <form id="pocasi-zmena" class="weather__form" data-weather-form hidden>
      <label class="weather__label" for="pocasi-misto">Místo</label>
      <div class="weather__search">
        <input id="pocasi-misto" class="weather__input" type="search" name="misto" maxlength="60" autocomplete="off" spellcheck="false" enterkeyhint="search" data-weather-input>
        <button class="weather__button" type="submit">Hledat</button>
      </div>
      <p class="weather__geo"><button class="weather__button" type="button" data-weather-geo hidden>Použít moji polohu</button></p>
      <ul class="weather__results" data-weather-results hidden></ul>
    </form>
    <p class="weather__status" role="status" data-weather-status></p>
    <div class="weather__data" data-weather-data>
<?php template('weather-data', ['weather' => $weather]); ?>
    </div>
    <p class="weather__source">Data: <a class="ext" href="https://open-meteo.com/">Open-Meteo</a></p>
  </div>
</section>
