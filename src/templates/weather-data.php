<?php
/** @var array $weather model z weather_get() */

// Údaje o počasí – na úvodní stránce i v odpovědi /api/pocasi/ (stejné formátování).
// Rozsah „10 / 17 °C“ čtečka přečte jako „od 10 do 17 °C“.
$current = $weather['current'];
$updated = $weather['updated_at'];
?>
      <p class="weather__now"><span class="weather__temp"><?= e(weather_temperature($current['temperature'], 1)) ?></span> <span class="weather__desc"><?= e($current['weather_label']) ?></span></p>
      <dl class="weather__meta">
        <div><dt>vlhkost</dt><dd><?= e(weather_number($current['humidity'], 0)) ?>&nbsp;%</dd></div>
        <div><dt>vítr</dt><dd><?= e(weather_number($current['wind_speed'], 0)) ?>&nbsp;km/h</dd></div>
      </dl>
<?php if ($weather['days'] !== []): ?>
      <table class="weather__days">
        <caption class="visually-hidden">Předpověď</caption>
<?php foreach ($weather['days'] as $day): ?>
        <tr>
          <th scope="row"><time datetime="<?= e($day['date']) ?>"><?= e($day['name']) ?></time></th>
          <td class="weather__range"><span class="visually-hidden">od </span><?= e(weather_number($day['min'], 0)) ?><span aria-hidden="true"> / </span><span class="visually-hidden"> do </span><?= e(weather_temperature($day['max'], 0)) ?></td>
          <td class="weather__day-desc"><?= e($day['weather_label']) ?></td>
        </tr>
<?php endforeach; ?>
      </table>
<?php endif; ?>
      <p class="weather__updated"><?= $weather['stale'] ? 'data z' : 'aktualizováno' ?> <time datetime="<?= e($updated->format(DATE_ATOM)) ?>"><?= e($weather['updated_time']) ?></time></p>
