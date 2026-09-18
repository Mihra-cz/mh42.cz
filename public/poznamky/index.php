<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_app.php';
require APP_ROOT . '/src/notes.php';

// Poznámky seskupené podle roku, od nejnovější.
$years = [];
foreach (load_notes() as $note) {
    $years[substr($note['published'], 0, 4)][] = $note;
}

page_start([
    'key'         => 'poznamky',
    'path'        => '/poznamky/',
    'title'       => 'Poznámky',
    'description' => 'Krátké technické poznámky k projektům mh42 a k tomuhle webu.',
]);
?>
<header class="page-intro">
  <div class="page-intro__body">
    <h1 class="lead">Poznámky</h1>
    <p class="page-intro__text">Krátké technické poznámky k&nbsp;projektům a&nbsp;k&nbsp;tomuhle webu. Nejnovější jsou nahoře.</p>
    <ul class="page-intro__links">
      <li><a href="/feed.atom" type="application/atom+xml">Atom feed</a></li>
    </ul>
  </div>
</header>

<?php foreach ($years as $year => $notes): ?>
<section class="section">
  <div class="section__head">
    <h2 id="rok-<?= e((string) $year) ?>" class="section__title"><?= e((string) $year) ?></h2>
  </div>
  <div class="section__body">
    <ul class="notes">
<?php foreach ($notes as $note): ?>
<?php template('note-row', ['note' => $note]); ?>
<?php endforeach; ?>
    </ul>
  </div>
</section>

<?php endforeach; ?>
<?php page_end();
