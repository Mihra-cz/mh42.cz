<?php /** @var array $note */ ?>
<article class="note">
<header class="page-intro">
  <div class="page-intro__body">
    <p class="page-intro__meta"><a href="/poznamky/">Poznámky</a> · <time datetime="<?= e($note['published']) ?>"><?= e($note['published']) ?></time><?php if ($note['updated'] !== $note['published']): ?> · aktualizováno <time datetime="<?= e($note['updated']) ?>"><?= e($note['updated']) ?></time><?php endif; ?></p>
    <h1 class="lead"><?= e_cs($note['title']) ?></h1>
    <p class="page-intro__text"><?= e_cs($note['summary']) ?></p>
  </div>
</header>
