<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_app.php';
require APP_ROOT . '/src/projects.php';

$groups = load_project_groups();

page_start([
    'key'         => 'projekty',
    'path'        => '/projekty/',
    'title'       => 'Projekty',
    'description' => 'Projekty, které stavím a provozuji doma: AnimeDB, MangaDB, HomeLab, domácí automatizace a tenhle web.',
]);
?>
<header class="page-intro">
  <div class="page-intro__body">
    <h1 class="lead">Projekty</h1>
    <p class="page-intro__text">Věci, které si stavím a&nbsp;provozuji doma. U&nbsp;neveřejných projektů tu najdete jen popis – samotné aplikace a&nbsp;data zůstávají mimo veřejný web.</p>
  </div>
</header>

<?php foreach ($groups as $group): ?>
<section class="section">
  <div class="section__head">
    <h2 id="skupina-<?= e($group['key']) ?>" class="section__title"><?= e($group['label']) ?></h2>
  </div>
  <div class="section__body">
    <ul class="projects">
<?php foreach ($group['projects'] as $project): ?>
<?php template('project-row', ['project' => $project]); ?>
<?php endforeach; ?>
    </ul>
  </div>
</section>

<?php endforeach; ?>
<section class="section">
  <div class="section__head">
    <h2 id="pristup" class="section__title">Přístup</h2>
  </div>
  <div class="section__body">
    <p class="legend__intro">Popis projektů je veřejný vždy. Údaj „přístup“ říká, kdo se dostane k&nbsp;samotné aplikaci nebo službě.</p>
    <dl class="legend">
<?php foreach (PROJECT_ACCESS as $access): ?>
      <div><dt><?= e($access['label']) ?></dt><dd><?= e_cs($access['description']) ?></dd></div>
<?php endforeach; ?>
    </dl>
  </div>
</section>

<?php page_end();
