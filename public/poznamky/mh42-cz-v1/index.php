<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/_app.php';
require APP_ROOT . '/src/notes.php';

note_page_start(__DIR__);
?>
<section class="section">
  <div class="section__head">
    <h2 class="section__title">Výchozí stav</h2>
  </div>
  <div class="section__body prose">
    <p>Na mh42.cz byla na začátku jen jednoduchá provizorní stránka. Nová verze z&nbsp;ní neměla udělat katalog aplikací ani rozcestník na domácí služby, ale osobní technický portál: pár vět o&nbsp;tom, čemu se věnuji, přehled projektů a&nbsp;technické poznámky, jako je tahle.</p>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <h2 class="section__title">Z&nbsp;čeho se skládá</h2>
  </div>
  <div class="section__body prose">
    <p>Stránky jsou obyčejné HTML a&nbsp;CSS. Skládá je několik jednoduchých PHP šablon – hlavička, patička a&nbsp;kostra stránky jsou na jednom místě. Seznam projektů a&nbsp;poznámek je v&nbsp;malých JSON souborech. Framework, databázi ani sestavovací nástroje web nepoužívá.</p>
    <p>JavaScript je jediný malý soubor, který přepíná světlý a&nbsp;tmavý motiv. Bez něj web funguje stejně, jen se motiv řídí nastavením systému. Písmo IBM Plex Sans a&nbsp;IBM Plex Mono se načítá přímo z&nbsp;mh42.cz, ne z&nbsp;cizího serveru. Web teď nepoužívá analytiku třetích stran ani cookies; volba motivu zůstává jen v&nbsp;prohlížeči.</p>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <h2 class="section__title">Kde běží</h2>
  </div>
  <div class="section__body prose">
    <p>První verze běží na klasickém PHP hostingu WEDOS. DNS a&nbsp;proxy obstarává Cloudflare. Spojení mezi Cloudflare a&nbsp;hostingem je šifrované v&nbsp;režimu Full (strict), takže Cloudflare ověřuje i&nbsp;certifikát serveru, nejen to, že spojení existuje.</p>
    <p>Z&nbsp;webu je dostupný jen obsah složky <code>public/</code>. Šablony, konfigurace a&nbsp;data leží na hostingu vedle ní, mimo veřejný web. Před nasazením prošel web kontrolou validity HTML, ovládání klávesnicí, kontrastu v&nbsp;obou motivech, stránek 404, bezpečnostních hlaviček a&nbsp;cache. Stejný kontrolní skript se spouští i&nbsp;proti produkci.</p>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <h2 class="section__title">Zdrojový kód</h2>
  </div>
  <div class="section__body prose">
    <p>Zdrojový kód je veřejný na <a class="ext" href="https://github.com/Mihra-cz/mh42.cz">GitHubu</a>, včetně nástrojů, které generují ikony, náhledový obrázek, pozadí a&nbsp;produkční balíček.</p>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <h2 class="section__title">Co dál</h2>
  </div>
  <div class="section__body prose">
    <p>Dlouhodobě se web přestěhuje na domácí server a&nbsp;vybrané služby budou dostupné přes Cloudflare Tunnel. Do té doby sem budu přidávat obsah a&nbsp;poznámky k&nbsp;projektům.</p>
  </div>
</section>

<?php note_page_end();
