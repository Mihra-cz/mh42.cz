<?php

declare(strict_types=1);

require __DIR__ . '/_app.php';
require APP_ROOT . '/src/notes.php';
require APP_ROOT . '/src/weather.php';

$weather = weather_home();   // null = sekce Počasí se nevykreslí

page_start([
    'key'         => 'home',
    'path'        => '/',
    'description' => 'Osobní web mh42 – domácí server, domácí automatizace a vlastní aplikace.',
    'scripts'     => $weather !== null ? ['js/weather.js'] : [],
]);

$github = config('github_url');
$latestNotes = array_slice(load_notes(), 0, 3);
?>
<section class="page-intro">
  <div class="page-intro__body">
    <h1 id="uvod" class="lead">Osobní web o&nbsp;domácím serveru, automatizaci a&nbsp;několika vlastních aplikacích.</h1>
    <p class="page-intro__text">Píšu tu, čemu se věnuji a&nbsp;co mám zrovna rozpracované. Projekty mají vlastní stránku – některé jsou soukromé, takže u&nbsp;nich najdete jen popis.</p>
<?php if ($github): ?>
    <ul class="page-intro__links">
      <li><a class="ext" href="<?= e($github) ?>" rel="me">GitHub</a></li>
    </ul>
<?php endif; ?>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <h2 id="venuji" class="section__title">Čemu se věnuji</h2>
  </div>
  <div class="section__body">
    <dl class="topics">
      <div class="topic">
        <dt>Domácí server a&nbsp;HomeLab</dt>
        <dd>Domácí server Nexus, bez veřejné IP adresy. Je provozně připravený a&nbsp;postupně na něj přecházejí vlastní služby; vybrané z&nbsp;nich zpřístupním přes Cloudflare Tunnel. Správa a&nbsp;interní služby zůstávají neveřejné. <a class="more" href="/projekty/#homelab">projekt</a></dd>
      </div>
      <div class="topic">
        <dt>Domácí automatizace</dt>
        <dd>Meteostanice, senzory teploty a&nbsp;vlhkosti, spotřeba energie a&nbsp;stav domácí infrastruktury. Na jaké platformě to poběží, zatím není rozhodnuté. <a class="more" href="/projekty/#domaci-automatizace">projekt</a></dd>
      </div>
      <div class="topic">
        <dt>Anime a&nbsp;manga</dt>
        <dd>Vlastní evidence domácí knihovny. AnimeDB je ve vývoji a&nbsp;připravuje se její nasazení na Nexus, MangaDB na ni časem naváže. Aplikace nejsou veřejné, na webu je jen jejich popis. <a class="more" href="/projekty/#animedb">projekty</a></dd>
      </div>
    </dl>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <h2 id="ted" class="section__title">Teď</h2>
    <p class="section__meta">aktualizováno <time datetime="2026-10-20">2026-10-20</time></p>
  </div>
  <div class="section__body">
    <ul class="now">
      <li>Domácí server Nexus je provozně připravený.<span class="now__note">Vlastní služby na něj přecházejí postupně, po jedné.</span></li>
      <li>Dál vyvíjím AnimeDB a&nbsp;připravuji její nasazení na Nexus.</li>
      <li>NexusSTAT pro diagnostiku a&nbsp;stav Nexusu je ve vývoji; na Nexusu zatím neběží.</li>
      <li>Domácí automatizace je zatím ve fázi návrhu.</li>
    </ul>
  </div>
</section>
<?php if ($latestNotes !== []): ?>

<section class="section">
  <div class="section__head">
    <h2 id="poznamky" class="section__title">Poznámky</h2>
  </div>
  <div class="section__body">
    <ul class="note-list">
<?php foreach ($latestNotes as $note): ?>
      <li><time datetime="<?= e($note['published']) ?>"><?= e($note['published']) ?></time> <a href="<?= e(note_url($note)) ?>"><?= e_cs($note['title']) ?></a></li>
<?php endforeach; ?>
    </ul>
    <p class="note-list__all"><a class="more" href="/poznamky/">všechny poznámky</a></p>
  </div>
</section>
<?php endif; ?>
<?php if ($weather !== null): ?>

<?php template('weather', ['weather' => $weather]); ?>
<?php endif; ?>

<?php page_end();
