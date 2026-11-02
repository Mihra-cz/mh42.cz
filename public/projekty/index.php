<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_app.php';
require APP_ROOT . '/src/projects.php';
require APP_ROOT . '/src/heartbeat.php';

$groups = load_project_groups();

// Veřejný stav NexusSTATu z heartbeatu. null = heartbeat je vypnutý, stav se nikde
// neukazuje. Se zapnutým stavem se stránka nesmí cachovat – jinak by prohlížeč nebo
// Cloudflare mohl ukázat zastaralé zelené OK.
$heartbeat = heartbeat_config();
$nexusState = $heartbeat === null ? null : heartbeat_state_for_page($heartbeat);
if ($nexusState !== null) {
    heartbeat_no_store();
}

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
<?php template('project-row', [
  'project' => $project,
  'state'   => $heartbeat !== null && $heartbeat['project'] === $project['slug'] ? $nexusState : null,
]); ?>
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
<?php if ($nexusState !== null): ?>

<section class="section">
  <div class="section__head">
    <h2 id="stav-nexusu" class="section__title">Stav Nexusu</h2>
  </div>
  <div class="section__body">
    <p class="legend__intro">Co znamenají hodnoty u&nbsp;NexusSTATu. Zveřejňuje se jen celkový stav, nic dalšího.</p>
    <dl class="legend">
<?php foreach (HEARTBEAT_LEGEND as $state => $description): ?>
      <div><dt><?= e($state) ?></dt><dd><?= e_cs($description) ?></dd></div>
<?php endforeach; ?>
    </dl>
  </div>
</section>
<?php endif; ?>

<?php page_end();
