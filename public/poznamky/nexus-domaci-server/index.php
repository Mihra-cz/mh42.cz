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
    <p>Nexus není nový stroj. Je to původní Raspberry Pi 5, které už doma nějakou dobu mám a&nbsp;které se postupně mění na domácí server pro vlastní projekty a&nbsp;služby. Hardware se nezměnil, změnila se role: z&nbsp;místa na zkoušení se stává místo, kde věci mají běžet dlouhodobě a&nbsp;kde se k&nbsp;nim dá po půl roce vrátit bez archeologie.</p>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <h2 class="section__title">Linux na ARM64</h2>
  </div>
  <div class="section__body prose">
    <p>Platforma je Linux na ARM64. Je to drobnost, dokud člověk nezačne skládat obrazy kontejnerů – ne všechno má ARM64 variantu a&nbsp;některé věci je potřeba sestavit, ne jen stáhnout. Současně to vede ke&nbsp;zdravé skromnosti: výkon ani paměť nejsou neomezené, takže se na server nevejde nic, co by tam leželo „jen tak pro případ“.</p>
    <p>Aplikace proto budou pokud možno oddělené v&nbsp;kontejnerech. Důvod není móda, ale to, že se jedna aplikace nerozlije do zbytku systému a&nbsp;dá se vyměnit nebo zrušit, aniž po ní zůstane nepořádek v&nbsp;závislostech.</p>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <h2 class="section__title">Struktura, která se dá udržet</h2>
  </div>
  <div class="section__body prose">
    <p>Hlavní změna ale není technická, je organizační. Nexus má teď jasně oddělená místa pro projekty, pro data a&nbsp;pro dokumentaci. Ke&nbsp;každému projektu patří stejné uspořádání a&nbsp;manifest: čím projekt je, co potřebuje k&nbsp;běhu, co po sobě zapisuje a&nbsp;co ho spouští. K&nbsp;tomu základní dokumentace – jak se to spustí, jak se to zastaví a&nbsp;co dělat, když to neběží. Zatím je to pravidlo pro projekty, které na Nexus teprve míří.</p>
    <p>Na server v&nbsp;bytě to může znít jako zbytečná byrokracie. Zkušenost říká opak: bez zapsaného stavu si za rok nevzpomenu, proč je něco udělané právě takhle, a&nbsp;přesně v&nbsp;tom okamžiku se server stane neudržovatelným.</p>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <h2 class="section__title">Záloha a&nbsp;obnova</h2>
  </div>
  <div class="section__body prose">
    <p>Druhý důraz je na obnovu, ne jen na zálohu. Záloha, kterou nikdo nikdy nezkusil vrátit, je hlavně pocit, že záloha existuje. Struktura je proto postavená tak, aby bylo předem zřejmé, co je stav aplikace, co jsou vlastní data a&nbsp;co se dá kdykoli znovu vytvořit. Obnova pak nemá být hledání, ale postup.</p>
    <p>Zatím je to ale pravidlo, ne hotová a&nbsp;vyzkoušená mašinérie. Automatické zálohování a&nbsp;ověřená obnova přijdou spolu s&nbsp;prvními službami, které na Nexusu opravdu poběží – rozdíl mezi „je to navržené“ a&nbsp;„je to ozkoušené“ si tu chci hlídat.</p>
    <p>Co tady záměrně nenajdete: konkrétní konfiguraci přístupů, adresy ani cesty. Na veřejném webu nemají co dělat a&nbsp;k&nbsp;pochopení myšlenky nejsou potřeba. Správa Nexusu zůstává neveřejná.</p>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <h2 class="section__title">Co se připravuje</h2>
  </div>
  <div class="section__body prose">
    <p>První větší aplikace, která se na Nexus chystá, je <a href="/projekty/#animedb">AnimeDB</a>. Zatím tam neběží – teď se připravuje způsob, jak ji nasadit bezpečně: oddělenou, se zálohovanými daty a&nbsp;bez zbytečného přístupu zvenku.</p>
    <p>Vedle toho je rozpracovaný <a href="/projekty/#nexusstat">NexusSTAT</a>, menší pomocný projekt: sledovat stav serveru a&nbsp;vybraných služeb a&nbsp;shrnout ho do jednoduchého přehledu. Kolektor systémových a&nbsp;síťových údajů, pravidelné měření, základ historie v&nbsp;SQLite i&nbsp;simulátor displeje už fungují a&nbsp;mají testy – na Nexusu ale zatím neběží a&nbsp;podoba výstupu se teprve usazuje. Jde mi u&nbsp;něj hlavně o&nbsp;to, aby se na jednom místě poznalo, že je něco v&nbsp;nepořádku – dřív než si toho všimnu podle toho, že mi něco nefunguje.</p>
  </div>
</section>

<?php note_page_end();
