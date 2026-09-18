<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/_app.php';
require APP_ROOT . '/src/notes.php';

note_page_start(__DIR__);
?>
<section class="section">
  <div class="section__head">
    <h2 class="section__title">Rozsah</h2>
  </div>
  <div class="section__body prose">
    <p>Web mh42.cz má čtyři hlavní stránky: úvod, O&nbsp;mně, Projekty a&nbsp;Poznámky. Obsah se mění ručně úpravou souborů, ne v&nbsp;administraci, a&nbsp;web nepotřebuje přihlašování, formuláře ani databázi. Na takový rozsah by framework přinesl víc věcí k&nbsp;údržbě, než kolik by jich vyřešil.</p>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <h2 class="section__title">Co dělá PHP</h2>
  </div>
  <div class="section__body prose">
    <p>PHP tu má dvě úlohy. Skládá stránky ze sdílených šablon, takže hlavička, patička a&nbsp;kostra stránky jsou na jednom místě. A&nbsp;čte strukturovaná data: projekty a&nbsp;poznámky jsou v&nbsp;JSON souborech, ze kterých vznikají seznamy na webu a&nbsp;ze kterých se generuje i&nbsp;sitemap a&nbsp;Atom feed. Všechno ostatní je obyčejné HTML a&nbsp;CSS.</p>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <h2 class="section__title">JavaScript</h2>
  </div>
  <div class="section__body prose">
    <p>JavaScript je jeden malý soubor. Přepíná světlý a&nbsp;tmavý motiv a&nbsp;pamatuje si volbu. Když se nenačte nebo je vypnutý, web funguje dál a&nbsp;motiv se řídí nastavením systému.</p>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <h2 class="section__title">Přenositelnost</h2>
  </div>
  <div class="section__body prose">
    <p>Web teď běží na PHP hostingu a&nbsp;později se má přestěhovat na domácí server. Čím méně předpokladů o&nbsp;prostředí, tím jednodušší přesun – stačí webserver s&nbsp;PHP. Bezpečnostní hlavičky posílá přímo PHP, takže nezávisí na konfiguraci konkrétního serveru, a&nbsp;adresy stránek fungují bez přepisovacích pravidel.</p>
    <p>Stejně jednoduché je spuštění na vlastním počítači: stačí vestavěný server PHP nebo Docker s&nbsp;Apachem, na kterém se před nasazením zkouší i&nbsp;konfigurace <code>.htaccess</code>.</p>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <h2 class="section__title">Závislosti</h2>
  </div>
  <div class="section__body prose">
    <p>Web sám nemá žádné balíčky z&nbsp;Composeru ani npm, které by bylo potřeba sledovat a&nbsp;aktualizovat. Pomocné nástroje – generování ikon a&nbsp;pozadí, sestavení produkčního balíčku a&nbsp;kontrola nasazení – jsou samostatné skripty ve složce <code>tools/</code> a&nbsp;na produkční server se nenahrávají.</p>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <h2 class="section__title">Kdyby se to změnilo</h2>
  </div>
  <div class="section__body prose">
    <p>Není to trvalé rozhodnutí. Pokud web časem naroste – přibude přihlašování, víc dynamického obsahu nebo rozhraní pro data z&nbsp;domácích systémů – architektura se může změnit. Pro současný rozsah ale jednoduché řešení stačí.</p>
  </div>
</section>

<?php note_page_end();
