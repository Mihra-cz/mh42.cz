<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_app.php';

// Přehled tokenů a komponent – jen pro vývoj, na produkci neexistuje.
if (!is_dev()) {
    not_found();
}

page_start([
    'key'     => 'styleguide',
    'path'    => null,
    'title'   => 'Styleguide',
    'noindex' => true,
    'styles'  => ['css/styleguide.css'],
]);

$colors = [
    ['--bg',      '#f6f5f1', '#121314', 'pozadí stránky'],
    ['--surface', '#eeece6', '#1b1c1e', 'plochy, hover'],
    ['--text',    '#1c1c1a', '#e7e5df', 'text'],
    ['--muted',   '#5c5a54', '#9d9a92', 'vedlejší text, popisky'],
    ['--line',    '#d9d6cc', '#2d2e31', 'dělicí linky'],
    ['--accent',  '#a8461a', '#f0925a', 'akcent – střídmě'],
];
?>
<header class="page-intro">
  <div class="page-intro__body">
    <h1 class="lead">Styleguide</h1>
    <p class="page-intro__text">Tokeny a komponenty webu v aktuálním motivu. Stránka existuje jen při <code>APP_ENV=dev</code>. Texty jsou ukázkové.</p>
  </div>
</header>

<section class="section sg-section--top">
  <div class="section__head">
    <h2 id="sg-barvy" class="section__title">Barvy</h2>
    <p class="section__meta">světlý / tmavý</p>
  </div>
  <div class="section__body">
    <ul class="sg-swatches">
<?php foreach ($colors as [$token, $light, $dark, $use]): ?>
      <li>
        <span class="sg-swatch sg-swatch<?= e($token) ?>"></span>
        <code class="sg-swatch__name"><?= e($token) ?></code>
        <span class="sg-swatch__values"><?= e($light) ?> / <?= e($dark) ?></span>
        <span class="sg-swatch__values"><?= e($use) ?></span>
      </li>
<?php endforeach; ?>
    </ul>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <h2 id="sg-typo" class="section__title">Typografie</h2>
  </div>
  <div class="section__body sg-samples">
    <div>
      <p class="sg-sample__label">.lead – nadpis stránky</p>
      <p class="lead">Ukázková úvodní věta, která zabere dva až tři řádky.</p>
    </div>
    <div>
      <p class="sg-sample__label">text – 17 px / 1,6</p>
      <p>Ukázkový odstavec. Příliš žluťoučký kůň úpěl ďábelské ódy. Délka řádku je omezená na 40rem, aby se text dobře četl.</p>
    </div>
    <div>
      <p class="sg-sample__label">vedlejší text</p>
      <p class="page-intro__text">Ukázkový vedlejší text v barvě --muted.</p>
    </div>
    <div>
      <p class="sg-sample__label">.section__title a .section__meta</p>
      <p class="section__title">Popisek sekce</p>
      <p class="section__meta">aktualizováno <time datetime="2026-01-01">2026-01-01</time></p>
    </div>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <h2 id="sg-odkazy" class="section__title">Odkazy</h2>
  </div>
  <div class="section__body sg-samples">
    <p>Text s <a href="#sg-odkazy">odkazem uvnitř věty</a> a <a class="ext" href="#sg-odkazy">odkazem mimo web</a>. Na konci odstavce může být <a class="more" href="#sg-odkazy">kontextový</a></p>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <h2 id="sg-seznamy" class="section__title">Seznamy</h2>
  </div>
  <div class="section__body sg-samples">
    <div>
      <p class="sg-sample__label">.topics</p>
      <dl class="topics">
        <div class="topic">
          <dt>Ukázkový pojem</dt>
          <dd>Ukázkový popis na jeden až dva řádky, v barvě vedlejšího textu.</dd>
        </div>
        <div class="topic">
          <dt>Druhý pojem</dt>
          <dd>Krátký popis. <a class="more" href="#sg-seznamy">odkaz</a></dd>
        </div>
      </dl>
    </div>
    <div>
      <p class="sg-sample__label">.now</p>
      <ul class="now">
        <li>Položka na jeden řádek.</li>
        <li>Položka s poznámkou.<span class="now__note">Poznámka v barvě vedlejšího textu.</span></li>
      </ul>
    </div>
  </div>
</section>

<?php page_end();
