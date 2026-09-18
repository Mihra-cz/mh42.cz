<?php
/** @var array $page */

$current = static fn (string $key): string => $key === $page['key'] ? ' aria-current="page"' : '';
?>
<header class="site-header">
  <div class="wrap site-header__inner">
    <a class="wordmark" href="/" aria-label="mh42 – úvodní stránka"<?= $current('home') ?>>mh<span>42</span></a>
    <nav class="nav" aria-label="Hlavní navigace">
      <ul>
<?php foreach (config('nav', []) as $item): ?>
        <li><a href="<?= e($item['href']) ?>"<?= $current($item['key']) ?>><?= e($item['label']) ?></a></li>
<?php endforeach; ?>
      </ul>
    </nav>
    <button class="theme-toggle" type="button" aria-pressed="false" title="Přepnout světlý / tmavý motiv" hidden>
      <svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
        <circle cx="10" cy="10" r="7.25" fill="none" stroke="currentColor" stroke-width="1.5"/>
        <path d="M10 2.75a7.25 7.25 0 0 1 0 14.5z" fill="currentColor"/>
      </svg>
      <span class="visually-hidden">Tmavý motiv</span>
    </button>
  </div>
</header>
