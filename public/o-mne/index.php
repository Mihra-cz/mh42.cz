<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_app.php';

page_start([
    'key'         => 'o-mne',
    'path'        => '/o-mne/',
    'title'       => 'O mně',
    'description' => 'Kdo stojí za mh42, čemu se věnuje a jak tenhle web běží.',
]);

$github = config('github_url');
?>
<header class="page-intro">
  <div class="page-intro__body">
    <h1 class="lead">O&nbsp;mně</h1>
    <p class="page-intro__text">mh42 je moje místo pro věci, které si stavím a&nbsp;provozuji sám. Zajímá mě software, elektronika, domácí servery a&nbsp;postupně také domácí automatizace.</p>
  </div>
</header>

<section class="section">
  <div class="section__head">
    <h2 id="co-stavim" class="section__title">Co stavím</h2>
  </div>
  <div class="section__body prose">
    <p>Když pro něco nenajdu řešení, které mi vyhovuje, často je zajímavější postavit si vlastní. Takhle vzniká například AnimeDB pro správu domácí anime knihovny a&nbsp;časem by na něj měla navázat MangaDB.</p>
    <p>Doma postupně vzniká i&nbsp;vlastní serverové prostředí, kam chci přesouvat další služby a&nbsp;propojovat je se senzory, automatizací a&nbsp;dalšími projekty. <a class="more" href="/projekty/">projekty</a></p>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <h2 id="tenhle-web" class="section__title">Tenhle web</h2>
  </div>
  <div class="section__body prose">
    <p>Tenhle web je veřejná část toho všeho. Píšu sem, čemu se věnuji, co právě vzniká a&nbsp;jak jednotlivé projekty fungují.</p>
  </div>
</section>

<section class="section" id="jak-web-bezi">
  <div class="section__head">
    <h2 class="section__title">Jak tenhle web běží</h2>
  </div>
  <div class="section__body prose">
    <p>Web běží na doméně mh42.cz. DNS a&nbsp;další síťové služby spravuji v&nbsp;Cloudflare.</p>
    <p>Stránky jsou obyčejné HTML a&nbsp;CSS, které skládá několik jednoduchých PHP šablon – bez frameworku, databáze a&nbsp;sestavovacích nástrojů. První verze běží na klasickém PHP hostingu.</p>
    <p>Písmo IBM Plex Sans a&nbsp;IBM Plex Mono je uložené přímo na webu, takže se nic nenačítá z&nbsp;cizích serverů. Počasí na úvodní stránce načítá server z&nbsp;Open-Meteo a&nbsp;drží ho 15&nbsp;minut v&nbsp;cache; prohlížeč komunikuje jen s&nbsp;mh42.cz. Web aktuálně nepoužívá analytické služby třetích stran ani cookies.</p>
    <p>Světlý nebo tmavý motiv se řídí nastavením systému. Přepínačem v&nbsp;hlavičce ho jde změnit a&nbsp;volba zůstane uložená jen ve vašem prohlížeči.</p>
    <p>Dlouhodobě se web přestěhuje na domácí server. Ten nemá veřejnou IP adresu, proto budou vybrané služby dostupné přes Cloudflare Tunnel: spojení navazuje domácí server směrem ven a&nbsp;doma není potřeba otevírat žádné porty.</p>
  </div>
</section>
<?php if ($github): ?>

<section class="section">
  <div class="section__head">
    <h2 id="kontakt" class="section__title">Kontakt</h2>
  </div>
  <div class="section__body prose">
    <p>Najdete mě na <a class="ext" href="<?= e($github) ?>" rel="me">GitHubu</a>.</p>
  </div>
</section>
<?php endif; ?>

<?php page_end();
