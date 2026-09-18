# Nasazení mh42.cz na WEDOS

Stav k 2026-09-18: **v1.1 nasazená** (delta nad v1, varianta A2).
Nasazení provádí autor ručně přes FTP a administraci WEDOS / Cloudflare;
kontroly po nahrání spouští Claude přes HTTPS (`tools/overeni-nasazeni.py`).

## Stav produkce v1.1

| | |
|---|---|
| Nasazeno | 2026-09-18 |
| Produkční commit | `af4a95b207aac2b38f53ecd33aee1a8bcd5e59cf` (mh42.cz v1.1 – poznámky a web app manifest) |
| Způsob | delta deployment varianty A2 – nahrané jen soubory změněné proti v1 |
| Rozsah | 24 produkčních souborů (14 nových, 10 změněných), nic se nemazalo |
| `config/asset-versions.php` | součástí nasazení (nové otisky změněných CSS) |
| Cloudflare purge | nebyl potřeba – změněné CSS mají novou adresu `?v=` |
| Balíček v1.1 | `dist/mh42-2026-09-18-1856/` – pořadí nahrávání a rollback v `NASAZENI-v1.1.md` |
| Rollback základ | balíček v1 `dist/mh42-v1-2026-09-18-1636/` – zůstává beze změny |

Balíčky v `dist/` jsou jen lokálně, nejsou v Gitu. Hosting a nastavení Cloudflare
se proti v1 nezměnily – tabulka v sekci „Finální stav produkce v1“ platí dál.

**Kontrola:** `tools/overeni-nasazeni.py` → **87 ok, 0 chyb.**

**Vizuální kontrola (headless Chromium):** světlý i tmavý motiv, PCB pozadí, mobilní
navigace a Poznámky v pořádku; žádný horizontální overflow, žádné chyby v konzoli,
žádné chyby CSP, žádné externí požadavky prohlížeče, žádné cookies.

**Nově ověřené veřejné části**
- `/poznamky/`, `/poznamky/mh42-cz-v1/`, `/poznamky/proc-bez-frameworku/`,
  `/poznamky/schema-ve-dne-pcb-v-noci/`
- `/feed.atom` – `application/atom+xml`, 3 položky, platné `id`/`title`/`updated`,
  unikátní `id` položek, odkazy vracejí 200
- `/manifest.webmanifest` – `application/manifest+json`, `id`, `start_url` i `scope` = `/`
- ikony `icon-192.png`, `icon-512.png`, `icon-512-maskable.png` (`/assets/img/`) –
  ověřené proti manifestu, maskable ikona je neprůhledná
- `sitemap.xml` – 7 veřejných URL, všechny vracejí 200

**Cache:** HTML `no-cache`; CSS/JS/písma rok + `immutable`; obrázky týden; verze
v adresách (`?v=`) sedí s otiskem obsahu.

## Finální stav produkce v1

**Kontrola:** `tools/overeni-nasazeni.py` → **67 ok, 0 chyb** (po finálních změnách
Cloudflare). Verze CSS/JS/obrázků v adresách jsou otisky obsahu
(`config/asset-versions.php`), cache hlavičky projdou přes Cloudflare beze změny.

Zástupné názvy: `<FTP_ROOT>` = kořen FTP účtu webhostingu (na WEDOS tvaru
`/data/web/virtuals/<WEDOS_ID>/virtual`), `<WEB_ROOT>` = `<FTP_ROOT>/www`, veřejný kořen webu.

| Oblast | Nastavení |
|---|---|
| Hosting | WEDOS, PHP 8.3 (PHP-FPM), Apache s `.htaccess` |
| Kořen webu | `<WEB_ROOT>` (`www/`) – obsah `public/` |
| Aplikace mimo web | `<FTP_ROOT>/{src,config,content}` (vedle `www/`) – PHP k nim má přístup, z webu nedostupné |
| Cloudflare SSL/TLS | **Full (strict)** |
| Certifikát na WEDOS (origin) | Let's Encrypt, aktivní, platnost do 16. 12. 2026 |
| Certifikát Cloudflare (návštěvník) | Let's Encrypt `mh42.cz` + `*.mh42.cz`, platnost do 16. 12. 2026 |
| `www.mh42.cz` → `https://mh42.cz` | 301, zachovává cestu i query string |
| `http://` → `https://` | 301 (Cloudflare) |
| Browser Cache TTL | Respect Existing Headers |
| Rocket Loader | Off |
| Web Analytics | Disabled |
| Email Address Obfuscation | Off |
| Bot Fight Mode | Off |
| Continuous Script Monitoring | Off |
| Hotlink Protection | Off |
| HSTS | **zatím vypnuté** – viz „Později: HSTS“ |

**Otevřené body**
- **HSTS** zapnout až po několika dnech spolehlivého provozu přes HTTPS: nejdřív krátký
  `max-age`, bez `includeSubDomains` a bez preloadu, teprve potom prodlužovat.
- **Certifikát na WEDOS** vyprší 16. 12. 2026 – před tím ověřit, že se obnovil
  (při režimu Full (strict) by propadlý certifikát způsobil chybu 526).
- Kosmetika: WEDOS ke statickým souborům přidává `Expires` (+3 dny);
  `Cache-Control: max-age` má přednost, nevadí.
- `fonts/LICENSE.txt` se na serveru liší koncem řádků (přenos `.txt` přes FTP
  v textovém režimu); web ho nepoužívá.

## Aktualizace webu (další nasazení)

1. Změny udělat a ověřit lokálně (`README.md` – vývojový server, test na Apachi).
2. Po změně poznámek `php tools/seo-soubory.php`, po změně ikon `python3 tools/obrazky.py`;
   pak `python3 tools/balicek.py` – kontrola vygenerovaných souborů a nový balíček
   v `dist/mh42-<datum>/`.
3. Nahrát přes FTP **v binárním režimu** změněné soubory z `A-oddeleny-koren/`:
   obsah `public/` do `www/`, `src/`, `config/`, `content/` vedle `www/`.
   Při každé změně assetů nahrát i `config/asset-versions.php` – jinak zůstanou staré
   adresy (a staré verze v cache).
4. `python3 tools/overeni-nasazeni.py` – musí skončit bez chyb.
5. Cloudflare purge jen cíleně (`favicon.ico`, `og-image.png` – nemají verzi v adrese),
   nikdy „Purge Everything“ jako běžný krok.

Rollback: předchozí balíček v `dist/` nahrát stejným postupem; při chybě 500 z `.htaccess`
přejmenovat `www/.htaccess` na `.htaccess.off`. Návrat v1.1 → v1: postup
v `dist/mh42-2026-09-18-1856/NASAZENI-v1.1.md`, zdrojem je balíček
`dist/mh42-v1-2026-09-18-1636/`.

---

# Historie prvního nasazení (2026-09-18)

## Druhá kontrola
`tools/overeni-nasazeni.py`: **67 ok, 0 chyb.** `config/asset-versions.php` je nahraný
(verze v adresách = otisky obsahu), CSS/JS mají přes Cloudflare `max-age=31536000, immutable`,
`www` i `http://` končí 301 na `https://mh42.cz/`.

Zbývá: přečíst nastavení Cloudflare (režim SSL, Rocket Loader, Web Analytics, Email
Obfuscation, Bot Fight Mode, Browser Cache TTL) a platnost certifikátu na WEDOS;
HSTS až po několika dnech provozu. Kosmetika: WEDOS ke statickým souborům přidává
`Expires` (+3 dny) – `Cache-Control: max-age` má přednost, nevadí.

## První kontrola po nahrání
`tools/overeni-nasazeni.py`: 54 ok, 13 chyb – všechny chyby mají známou příčinu:

- **Verze assetů jsou časy změny, ne otisky obsahu** → na serveru chybí
  `config/asset-versions.php` (obsah všech 24 assetů přitom sedí s balíčkem).
  Oprava: nahrát ho z balíčku do `config/` vedle `www/`. Adresy assetů se tím změní,
  Cloudflare je stáhne znovu se správnými hlavičkami – purge není potřeba.
- **CSS/JS mají v Cloudflare staré hlavičky WEDOS** (`max-age=259200` + `Expires`,
  `cf-cache-status: HIT`) – načetly se dřív, než byl nahraný náš `.htaccess`.
  Po opravě výše už na ně nic neodkazuje. `pcb.svg` (verze z `tokens.css`) se sám
  obnoví po vypršení (WEDOS posílal `Expires` +10 min, Cloudflare to zvedl na 4 h).
- **`www` → `mh42.cz` zatím nepřesměrovává** – čeká na pravidlo v Cloudflare.

Vše ostatní v pořádku: stránky, 404 (i `*.php`, `/styleguide/`, `/assets/`), interní
soubory nedostupné, bezpečnostní hlavičky, písma (`font/woff2`, rok), OG obrázek,
`robots.txt`, `sitemap.xml`, žádné cookies; vizuálně oba motivy, přepínač, pozadí,
mobil, žádné chyby v konzoli. `info.php`, `old/`, `index.html` a diagnostika jsou pryč.

Poznámka: `fonts/LICENSE.txt` se na serveru liší (převod konců řádků při přenosu
`.txt` přes FTP v textovém režimu) – web ho nepoužívá; do budoucna nahrávat binárně.

## Co je zjištěné zvenku (bez přístupu k hostingu)

- `mh42.cz` i `www.mh42.cz` jdou přes proxy Cloudflare, `http://` se přesměrovává na `https://`.
- Certifikát návštěvník ↔ Cloudflare: Let's Encrypt, `mh42.cz` + `*.mh42.cz`, platný do 16. 12. 2026.
- `https://www.mh42.cz/` vrací 200 – **nepřesměrovává** na `https://mh42.cz/`.
- Provizorní stránka je statické HTML s `cache-control: max-age=300` (není jasné,
  jestli ho posílá WEDOS, nebo Cloudflare – ukáže diagnostika).
- HSTS se zatím neposílá.

## Výsledek diagnostiky WEDOS (2026-09-18)

| Zjištění | Hodnota |
|---|---|
| PHP | 8.3.33, běží jako PHP-FPM (`fpm-fcgi`), `expose_php` vypnuté |
| Kořen webu (DOCUMENT_ROOT) | `<WEB_ROOT>` = `<FTP_ROOT>/www` (hlavní doména hostingu) |
| Nadřazený adresář | `<FTP_ROOT>` – obsahuje `www/`, `session/`, `tmp/`; přes FTP do něj jde zapisovat |
| `open_basedir` | `<FTP_ROOT>` – PHP smí k nadřazenému adresáři |
| Čtení souboru nad kořenem z PHP | **funguje** (`mh42-diag-mimo/test.txt` přečteno, druhý běh diagnostiky) |
| `.htaccess`: ErrorDocument, Options -Indexes, DirectoryIndex, AddType, zákaz přístupu | vše funguje |
| Vlastní 404 pro neexistující `*.php` i složky, 403 → 404 | funguje i přes PHP-FPM |
| `mod_headers`, `mod_rewrite` | ano |
| `font/woff2` | server ho posílá správně i bez `AddType` |
| Cloudflare → WEDOS | HTTPS (`HTTPS=on`) – režim je Full nebo Full (strict), přesný zjistit v dashboardu |
| Hlavičky z PHP přes Cloudflare | projdou beze změny, `Cache-Control: no-cache` zůstane, HTML `DYNAMIC` |
| Cache statického CSS přes Cloudflare | `public, max-age=31536000, immutable` zůstane |

**Obsah kořene webu `www/`:** `.htaccess` (výchozí pravidla WEDOS pro `www/domains/`
a `www/subdom/` – převzatá do našeho `.htaccess`), `domains/`, `subdom/`, `index.html`
(provizorní stránka), `old/` (výchozí parkovací stránka WEDOS), **`info.php` – veřejný
`phpinfo()`, smazat co nejdřív**.

Kořen webu je pevný adresář hlavní domény (`www/`), varianta A1 (přepnutí kořene) se
proto nepoužije. **Použije se varianta A2** – aplikace v `<FTP_ROOT>/`
vedle `www/`, mimo web. `public/_app.php` se nemění (`dirname(www) = virtual`).

## Postup pro WEDOS (varianta A2)

Balíček: `dist/mh42-v1-2026-09-18-1636/A-oddeleny-koren/`. Kořen FTP = `…/virtual/`
(obsahuje `www/`, `session/`, `tmp/`).

| Z balíčku | Kam na FTP |
|---|---|
| `src/`, `config/`, `content/` | kořen FTP, vedle `www/` |
| obsah `public/` (včetně `.htaccess`) | do `www/` |

1. **Hned:** smazat `www/info.php` (veřejný `phpinfo()`).
2. **Záloha:** stáhnout celé `www/` včetně skrytých souborů do
   `~/Projekty/WEB-zalohy/mh42-provizorni-2026-09-18/`.
3. **Úklid diagnostiky:** smazat `www/mh42-diag-<kód>/` a `mh42-diag-mimo/`.
4. **Aplikace:** nahrát `src/`, `config/`, `content/` do kořene FTP (vedle `www/`) – z webu nejsou dostupné.
5. **Web bez přepnutí:** nahrát do `www/` obsah `public/` **kromě `.htaccess`**
   (`_app.php`, `404.php`, `index.php`, `favicon.ico`, `robots.txt`, `sitemap.xml`,
   `assets/`, `o-mne/`, `projekty/`). Stará úvodní stránka zatím může běžet dál.
6. **Předběžná kontrola (Claude):** `/o-mne/` a `/projekty/` už ukazují nový web.
7. **Přepnutí:** nahrát `.htaccess` do `www/` (přepíše výchozí WEDOS – jeho pravidla
   jsou v našem souboru), smazat `www/index.html` a `www/old/`. `www/domains/`
   a `www/subdom/` nechat.
8. **Kontrola (Claude):** `tools/overeni-nasazeni.py` + vizuální kontrola.
9. **Cloudflare:** přesměrování `www` → `https://mh42.cz`, Browser Cache TTL
   „Respect Existing Headers“, ověřit režim SSL (viz krok 6 níže).

**Rollback A2:** chyba 500 → přejmenovat `www/.htaccess` na `.htaccess.off`.
Úplný návrat → smazat z `www/` soubory nové verze (`public/…` v `SOUBORY-A-oddeleny-koren.txt`)
a nahrát zpět zálohu (bez `info.php`). Složky `src/`, `config/`, `content/` nad
`www/` můžou zůstat, z webu nejsou dostupné.

## Připravené soubory (`dist/`, vytváří `tools/balicek.py`)

| Cesta | Obsah |
|---|---|
| `dist/mh42-v1-<datum>/A-oddeleny-koren/` (+ `.zip`) | varianta A: `public/`, `src/`, `config/`, `content/` |
| `dist/mh42-v1-<datum>/B-jeden-adresar/` (+ `.zip`) | varianta B: obsah `public/` v kořeni, `src/`, `config/`, `content/` se zákazem přístupu |
| `dist/mh42-v1-<datum>/SOUBORY-*.txt` | přesný seznam souborů verze se SHA-256 (pro kontrolu a rollback) |
| `dist/mh42-v1-<datum>/nahradni-htaccess/htaccess-bez-options.txt` | `.htaccess` pro hosting, který nepovolí `Options` |
| `dist/wedos-diagnostika/` | diagnostická sada (krok 1) |

V balíčku nejsou `tools/`, `prototyp/`, `docs/`, `.claude/`, styleguide ani `config/local.php`.
Vygenerované soubory (`robots.txt`, `sitemap.xml`, OG obrázek, ikony, dlaždice pozadí,
otisky assetů v `config/asset-versions.php`) jsou v balíčku hotové; `tools/balicek.py`
před sestavením ověří, že sedí s konfigurací.

Obě varianty jsou otestované na Apachi 2.4 (Docker `php:8.3-apache`): 64/64 kontrol.
Náhradní `.htaccess` je otestovaný na Apachi, který `Options` nepovolí (původní → HTTP 500,
náhradní → 64/64).

## Krok 1 – zjistit možnosti hostingu (nic se nemění, nic se nemaže)

**Administrace WEDOS**
1. U webhostingu najít, do kterého adresáře míří doména `mh42.cz`, a zda jde změnit
   (např. na podsložku `…/public`). Opsat přesnou cestu.
2. Zjistit aktivní verzi PHP a zda jde přepnout na 8.3 (min. 8.0).
3. Zjistit, zda je na WEDOS pro `mh42.cz` vystavený SSL certifikát a do kdy platí.

**FTP**
4. Poznamenat strukturu adresářů od kořene FTP ke kořeni webu (stačí snímek obrazovky).
5. Zkusit, zda jde vytvořit složku **o úroveň výš** než kořen webu.
6. Nahrát do kořene webu složku `dist/wedos-diagnostika/mh42-diag-<kód>/` (nic nepřepíše).
   Pokud šel bod 5, nahrát `dist/wedos-diagnostika/mh42-diag-mimo/` o úroveň výš.

**Cloudflare – jen přečíst, nic neměnit**
7. SSL/TLS → režim (Flexible / Full / Full (strict)).
8. Caching → Configuration → Browser Cache TTL.
9. Stav Rocket Loaderu, Web Analytics, Email Address Obfuscation (Scrape Shield),
   Bot Fight Mode a existující Redirect/Page Rules.

Pak Claude spustí `python3 tools/wedos-diagnostika.py vyhodnotit` a vyhodnotí verzi PHP,
způsob běhu PHP, `open_basedir`, strukturu adresářů, `.htaccess` (`ErrorDocument`,
`Options`, `DirectoryIndex`, `AddType`, zákaz přístupu), `mod_headers`, vlastní 404 pro
`*.php` a to, jak hlavičky z PHP projdou přes Cloudflare. **Diagnostickou složku pak smazat.**

## Krok 2 – výběr varianty

| Zjištění | Varianta |
|---|---|
| Kořen webu jde nasměrovat na podsložku | **A1** – nahrát celý balíček A do nové složky a přepnout kořen na `…/public` |
| Kořen je pevný, ale nad ním jde zapisovat a PHP tam smí číst (`open_basedir`) | **A2** – na WEDOS: `src/`, `config/`, `content/` z balíčku A do `<FTP_ROOT>/` (vedle `www/`), obsah `public/` do `www/`; `_app.php` se nemění |
| Jinak | **B** – vše v kořeni webu, interní složky se zákazem přístupu |
| Hosting nepovolí `Options` | ve všech variantách místo `.htaccess` použít `htaccess-bez-options.txt` |

## Krok 3 – záloha současné stránky

1. Ve FTP klientovi zapnout zobrazení skrytých souborů (kvůli `.htaccess`).
2. Stáhnout **celý obsah kořene webu** do `~/Projekty/WEB-zalohy/mh42-provizorni-<datum>/`
   (mimo repozitář).
3. Zkontrolovat, že počet souborů a velikosti sedí s hostingem, a uložit si seznam
   souborů (snímek obrazovky nebo výpis `webroot_obsah` z diagnostiky).

## Krok 4 – nasazení

**A1** (nic starého se nemaže)
1. Vytvořit složku pro novou verzi (umístění podle kroku 1), nahrát do ní obsah
   `A-oddeleny-koren/` včetně `public/.htaccess`.
2. V administraci přepnout adresář domény na `…/<složka>/public`.

**A2 a B** (výměna v kořeni webu)
1. Nahrát nové soubory **kromě `.htaccess` v kořeni** – stará stránka zatím běží dál.
   U varianty B vytvořit nejdřív složky `src/`, `config/`, `content/` a nahrát do nich
   jako první jejich `.htaccess`, teprve potom zbytek. U varianty A2 nejdřív aplikační
   složky nad kořen.
2. Předběžná kontrola: `https://mh42.cz/o-mne/` a `/projekty/` už ukazují nový web.
3. Přepnutí: nahrát kořenový `.htaccess` a smazat soubory provizorní stránky
   (podle zálohy – zejména `index.html`).

## Krok 5 – kontrola hned po nahrání (Claude)

`python3 tools/overeni-nasazeni.py` (produkce `https://mh42.cz`) a vizuální kontrola:

- **Stavy:** `/`, `/o-mne/`, `/projekty/` → 200; neexistující stránka, `*.php`,
  `*.txt`, `/styleguide/`, `/assets/`, `/_app.php` → 404 s vlastní stránkou;
  `src/`, `config/`, `content/`, `.htaccess`, `public/` → 403/404 bez úniku obsahu.
- **Hlavičky:** CSP, `nosniff`, `Referrer-Policy`, `Permissions-Policy`,
  `X-Frame-Options` + `frame-ancestors`, žádné `X-Powered-By`, žádné `Set-Cookie`,
  HSTS zatím ne (a zatím ani později bez `includeSubDomains`).
- **Cache:** HTML `no-cache`; CSS/JS/písma rok + `immutable`; verze v adrese = otisk
  obsahu (kontrola porovná `?v=` se staženým souborem); obrázky týden.
- **Assety:** všechny CSS/JS z HTML, písma z `fonts.css` (`font/woff2`), dlaždice
  pozadí z `tokens.css`, favicon, Apple touch icon, OG obrázek 1200 × 630 přes
  `https://mh42.cz`, `robots.txt`, `sitemap.xml` a všechny adresy v ní.
- **Přesměrování:** `http://mh42.cz/`, `https://www.mh42.cz/`, `http://www.mh42.cz/`
  → `https://mh42.cz/`; `/o-mne` → `/o-mne/`.
- **Vzhled (headless Chromium):** světlý a tmavý motiv, přepínač, schéma ve světlém,
  layout desky v tmavém, mobil 375 px, odkaz na GitHub.

## Krok 6 – Cloudflare (po úspěšném nasazení)

- **www → mh42.cz:** Rules → Redirect Rules, šablona „Redirect from WWW to root“:
  `www.mh42.cz` → `https://mh42.cz` + zachovat cestu a query, 301.
- **Browser Cache TTL:** „Respect Existing Headers“.
- **Nezapínat:** Rocket Loader, Web Analytics, optimalizace JS/HTML. Email Address
  Obfuscation doporučuji vypnout (při výskytu e-mailu mění HTML a vkládá skript).
- **SSL Full (strict):** pokud už je nastavený a web funguje, origin certifikát je v pořádku.
  Jinak nejdřív ověřit platný certifikát na WEDOS (krok 1, bod 3), přepnout na
  Full (strict) a hned otestovat. Při chybě 526 vrátit předchozí režim a zjistit příčinu
  (chybějící / propadlý certifikát, nesoulad jména) – ověřování nevypínat.
- **Purge:** žádný „Purge Everything“. Assety s verzí se mění adresou; bez verze jsou
  jen `favicon.ico` a `og-image.png` – při jejich změně purge jen těchto URL.

## Rollback

- **HTTP 500 po nahrání `.htaccess`:** přes FTP přejmenovat `.htaccess` na
  `.htaccess.off` – web hned funguje bez něj (hlavičky posílá PHP). Pak nahrát
  `htaccess-bez-options.txt` jako `.htaccess`. FTP na stavu webu nezávisí.
- **A1:** přepnout adresář domény zpět na původní – okamžitý návrat, nic se nemazalo.
- **A2 / B:** smazat soubory nové verze (seznam `SOUBORY-*.txt`, plus aplikační složky)
  a nahrát zpět obsah zálohy z kroku 3. HTML se v Cloudflare necachuje, návrat je vidět hned.

## Později: HSTS

Až web několik dní spolehlivě běží přes HTTPS: Cloudflare → SSL/TLS → Edge Certificates
→ HSTS s `max-age` 1 den, **bez `includeSubDomains`**, bez preloadu. Teprve potom
postupně prodlužovat.

## Cache – pravidla aplikace

| Obsah | Hlavička | Proč je to bezpečné |
|---|---|---|
| HTML (PHP) | `no-cache` | změny obsahu jsou vidět hned |
| CSS, JS, obrázky z `asset()` | CSS/JS rok + `immutable`, obrázky týden | adresa má `?v=<otisk obsahu>` z `config/asset-versions.php` |
| dlaždice pozadí | týden | `?v=<otisk>` zapisuje do `tokens.css` `tools/pozadi-pcb.py` |
| písma `.woff2` | rok + `immutable` | při změně písma se soubory **přejmenují** |
| `favicon.ico`, `og-image.png` | týden | pevná adresa bez verze |
