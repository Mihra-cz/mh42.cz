<?php /** @var array $note */ ?>
      <li class="note-row">
        <time class="note-row__date" datetime="<?= e($note['published']) ?>"><?= e($note['published']) ?></time>
        <div class="note-row__body">
          <h3 class="note-row__title"><a href="<?= e(note_url($note)) ?>"><?= e_cs($note['title']) ?></a></h3>
          <p><?= e_cs($note['summary']) ?></p>
        </div>
      </li>
