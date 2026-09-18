<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/_app.php';
require APP_ROOT . '/src/notes.php';

note_page_start(__DIR__);
?>
<section class="section">
  <div class="section__head">
    <h2 class="section__title">Nápad</h2>
  </div>
  <div class="section__body prose">
    <p>Web má střídmý vzhled „technického listu“: popisky sekcí v&nbsp;levém sloupci, tenké dělicí linky a&nbsp;jeden rezavý akcent. Pozadí mělo tenhle charakter podpořit, ne přebít. Motiv je proto inspirovaný elektronikou – ve světlém motivu je to abstraktní elektronické schéma, v&nbsp;tmavém layout desky plošných spojů.</p>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <h2 class="section__title">Jak vzniká</h2>
  </div>
  <div class="section__body prose">
    <p>Motiv není kreslený ručně. Generuje ho skript <code>tools/pozadi-pcb.py</code>, který skládá opakovatelné dlaždice z&nbsp;jednoduchých prvků: ve schématu z&nbsp;vodičů, rezistorů, kondenzátorů a&nbsp;integrovaných obvodů, na desce z&nbsp;cest, padů a&nbsp;prokovů. Prvky tvoří uzavřené ostrůvky, takže nikde nevzniká nekonečná vodorovná čára. Čáry schématu jsou jemně červené, cesty desky jemně zelené a&nbsp;potisk zůstává neutrálně šedý.</p>
    <p>Kontrast motivu vůči pozadí je nejvýš 1,43&nbsp;:&nbsp;1, zatímco text má přes 14&nbsp;:&nbsp;1. Dlaždice mají v&nbsp;adrese otisk obsahu, takže po změně generátoru si prohlížeč stáhne novou verzi.</p>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <h2 class="section__title">Výběr varianty</h2>
  </div>
  <div class="section__body prose">
    <p>Vznikly tři varianty: velmi jemná, středně výrazná a&nbsp;rastr připomínající univerzální desku. Na webu je středně výrazná varianta&nbsp;B, jemnější varianta&nbsp;A zůstala v&nbsp;prototypu jako záloha. Stejný skript vytváří i&nbsp;prototyp, ve kterém jde varianty porovnat v&nbsp;obou motivech.</p>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <h2 class="section__title">Čitelnost</h2>
  </div>
  <div class="section__body prose">
    <p>Pod hlavním textovým sloupcem je motiv zeslabený na 65&nbsp;%, naplno je vidět jen ve volných okrajích širších obrazovek. Kolem vodorovných dělicích linek jsou ochranná pásma, do kterých motiv nezasahuje, aby na linky nenavazovaly vodiče ani cesty. U&nbsp;linek sekcí vedou pásma přes celou šířku okna, u&nbsp;oddělovačů řádků na stránkách Projekty a&nbsp;Poznámky o&nbsp;kus přes jejich konce. Pásma jsou kreslená tak, že stránku nerozšiřují a&nbsp;nevzniká vodorovné posouvání.</p>
    <p>Při tisku, zvýšeném kontrastu a&nbsp;vynucených barvách se motiv vypíná úplně.</p>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <h2 class="section__title">Cíl</h2>
  </div>
  <div class="section__body prose">
    <p>Cílem bylo dát webu vlastní technickou identitu, aniž by utrpěla čitelnost. Pozadí je jen doplněk, ne hlavní grafický prvek.</p>
  </div>
</section>

<?php note_page_end();
