<?php
/** @var array $project */

$tech = $project['tech'] ?? [];
$link = $project['link'] ?? null;
?>
      <li class="project" id="<?= e($project['slug']) ?>">
        <h3 class="project__name"><?= e($project['name']) ?></h3>
        <div class="project__body">
          <p><?= e_cs($project['summary']) ?><?php if ($link): ?> <a class="more" href="<?= e($link['href']) ?>"><?= e($link['label']) ?></a><?php endif; ?></p>
          <dl class="project__meta">
            <div><dt>stav</dt><dd><?= e(project_status_label($project['status'])) ?></dd></div>
<?php if (isset($project['access'])): ?>
            <div><dt>přístup</dt><dd><?= e(project_access_label($project['access'])) ?></dd></div>
<?php endif; ?>
<?php if ($tech !== []): ?>
            <div><dt>technologie</dt><dd><?= e(implode(' · ', $tech)) ?></dd></div>
<?php endif; ?>
          </dl>
        </div>
      </li>
