<?php $github = config('github_url'); ?>
<footer class="site-footer">
  <div class="wrap site-footer__inner">
    <p>mh42.cz</p>
    <ul class="site-footer__links">
<?php if ($github): ?>
      <li><a class="ext" href="<?= e($github) ?>" rel="me">GitHub</a></li>
<?php endif; ?>
      <li><a href="/o-mne/#jak-web-bezi">Jak tenhle web běží</a></li>
    </ul>
  </div>
</footer>
