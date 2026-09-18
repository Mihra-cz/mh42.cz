# mh42.cz

Osobní web / osobní technický portál. HTML, CSS, trocha JavaScriptu a PHP jen tam,
kde má smysl (sdílené šablony, strukturovaná data – projekty, poznámky – a počasí
z Open-Meteo načítané serverem). Bez frameworku, bez databáze, bez build nástrojů.

Schválený návrh a rozhodnutí: [docs/navrh.md](docs/navrh.md).
Nasazení, cache a body k ověření na WEDOS: [docs/nasazeni.md](docs/nasazeni.md).
Schválený vizuální prototyp: [prototyp/homepage.html](prototyp/homepage.html).

## Struktura

```
public/            kořen webu – jediné, co je dostupné zvenku
  index.php        Domů
  o-mne/           O mně
  projekty/        Projekty
  poznamky/        Poznámky – seznam a jedna složka s index.php na každou poznámku
  feed.atom        Atom feed poznámek (generovaný)
  manifest.webmanifest  web app manifest (generovaný)
  api/pocasi/      POST – počasí pro místo zvolené návštěvníkem (JSON)
  api/mista/       POST – hledání místa pro počasí (JSON)
  styleguide/      přehled tokenů a komponent (jen APP_ENV=dev)
  assets/          CSS, JS, obrázky
src/
  bootstrap.php    společný začátek každé stránky (konfigurace, hlavičky);
                   stránky ho načítají přes public/_app.php
  helpers.php      e(), asset(), page_start(), page_end(), …
  projects.php     načtení projektů
  notes.php        načtení poznámek, adresy a data pro Atom
  weather.php      počasí: nastavení, Open-Meteo, cache, hledání místa, formátování
  http.php         HTTP GET pro volání API ze serveru (curl, záložně PHP streamy)
  api.php          společné chování JSON endpointů v public/api/
  templates/       hlavička, patička, kostra stránky, řádky seznamů, sekce Počasí
content/
  projects.json    projekty pro stránku Projekty
  notes.json       metadata poznámek (seznam, úvodní stránka, sitemap, feed)
config/
  app.php          veřejné nastavení (v gitu)
  local.php        lokální přepsání a tajné hodnoty (mimo git, volitelné)
var/               běhová data – cache počasí var/weather/ (mimo git, zapisuje PHP)
docs/              návrh a dokumentace
tools/             vývojové nástroje, generátory a testy (nejsou součástí webu)
  testdata/        odpovědi Open-Meteo pro testy (bez sítě)
prototyp/          statické prototypy k posouzení (nejsou součástí webu)
```

## Lokální spuštění

S nainstalovaným PHP (8.1+):

```bash
APP_ENV=dev php -S localhost:8000 -t public tools/dev-router.php
```

Nebo přes Docker:

```bash
docker run --rm -p 8000:8000 -v "$PWD":/app -w /app -e APP_ENV=dev php:8.3-cli-alpine php -S 0.0.0.0:8000 -t public tools/dev-router.php
```

Web pak běží na <http://localhost:8000>, styleguide na <http://localhost:8000/styleguide/>.

Router `tools/dev-router.php` vrací pro neexistující adresy stránku 404 stejně
jako Apache na produkci (tam ji zajišťuje `public/.htaccess`).

Test na Apachi v produkčním režimu (ověří `.htaccess`, stavy 404, cache a hlavičky):

```bash
docker run --rm -p 8080:80 -v "$PWD":/var/www -v "$PWD/public":/var/www/html php:8.3-apache sh -c "a2enmod headers && apache2-foreground"
```

## Generované soubory

- `python3 tools/obrazky.py` – `favicon.ico`, `apple-touch-icon.png`, ikony manifestu
  `icon-192.png`, `icon-512.png` a `icon-512-maskable.png`, náhled `og-image.png`
  a `manifest.webmanifest` (Pillow, písmo IBM Plex z `public/assets/fonts/`).
  Maskable ikona má menší číslice; generátor ověří, že grafika leží v bezpečné zóně
  (kruh o poloměru 40 %) a pozadí pokrývá celou plochu.
- `python3 tools/pozadi-pcb.py` – dlaždice pozadí `public/assets/img/pozadi/*.svg`
  (a jejich verze v `tokens.css`) a prototyp variant `prototyp/pozadi-pcb.html`.
- `php tools/seo-soubory.php` – `robots.txt`, `sitemap.xml` a `feed.atom` z `config/app.php`
  a `content/notes.json` (produkční adresa `base_url` je jen tam; sitemap = úvodní stránka
  + navigace + všechny poznámky).
  Přes Docker: `docker run --rm --user "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.3-cli-alpine php tools/seo-soubory.php`

Vygenerované soubory se ručně neupravují, mění se generátor.

## Nasazení

- `python3 tools/balicek.py` – zkontroluje vygenerované soubory a sestaví produkční
  balíček do `dist/` (varianty A a B, seznamy souborů, zipy, náhradní `.htaccess`).
- `python3 tools/wedos-diagnostika.py pripravit|vyhodnotit` – diagnostika hostingu.
- `python3 tools/overeni-nasazeni.py [adresa] [--lokalne]` – kontrola po nahrání.
  Cache-Control vyhodnocuje ze všech výskytů hlavičky (WEDOS přidává vlastní `max-age=300`).
- `python3 tools/hlavicky.py` – testy tohoto vyhodnocení s fixtures (bez sítě).

Postup je v [docs/nasazeni.md](docs/nasazeni.md).

## Konvence

- Každá stránka je složka v `public/` s `index.php`. Čisté adresy tak fungují
  bez přepisovacích pravidel na Apachi (WEDOS) i na nginx nebo Caddy.
- Stránka začíná `page_start([...])` a končí `page_end()`; obsah mezi nimi je HTML.
- Texty jsou přímo v HTML stránek. Strukturovaná data (projekty) jsou v `content/`.
- Bezpečnostní hlavičky včetně CSP posílá PHP (`src/helpers.php`), takže platí
  na každém serveru stejně. Žádné inline skripty ani inline styly.
  `Permissions-Policy` povoluje polohu jen tomuto webu (`geolocation=(self)`),
  kamera, mikrofon, platby a USB zůstávají zakázané.
- Prohlížeč návštěvníka komunikuje jen s mh42.cz. Cizí API (Open-Meteo) volá server.
- Barvy, písmo a rozměry jsou jen v `public/assets/css/tokens.css`.
- CSS a JS se odkazují přes `asset()` (verze v adrese). Písma mají roční cache –
  při jejich změně je potřeba soubory přejmenovat.
- Odkazy mimo web mají třídu `ext` (šipka ↗), otevírají se ve stejném okně.

## Projekty (`content/projects.json`)

`groups` určuje pořadí a názvy skupin, `projects` jednotlivé projekty:

| Pole | Povinné | Význam |
|---|---|---|
| `slug` | ano | kotva na stránce, např. `/projekty/#animedb` |
| `name` | ano | název |
| `group` | ano | klíč skupiny z `groups` |
| `summary` | ano | jedna až dvě věty popisu |
| `status` | ano | `planned`, `development`, `building`, `live`, `paused`, `archived` |
| `access` | ne | dostupnost samotné aplikace nebo služby: `public`, `private`, `local`; vynechat, kde by jedna hodnota zkreslovala |
| `tech` | ne | seznam technologií – jen kde dává smysl |
| `link` | ne | `{ "href": "…", "label": "…" }` – kontextový odkaz |
| `listed` | ne | `false` = projekt se nevypíše |

U neveřejných projektů nepatří do souboru adresa ani hostname aplikace.

## Poznámky (`content/notes.json`)

Metadata jsou jen v `content/notes.json`, text poznámky je obyčejné HTML v
`public/poznamky/<slug>/index.php`. Ze stejných dat vzniká seznam `/poznamky/`,
výběr tří nejnovějších na úvodní stránce, `sitemap.xml` i `feed.atom`.

| Pole | Význam |
|---|---|
| `slug` | adresa `/poznamky/<slug>/`, stejný jako název složky se stránkou |
| `title` | název (titulek stránky, Open Graph, feed) |
| `published` | datum publikace `RRRR-MM-DD` (řazení od nejnovější; při shodě pořadí v souboru) |
| `updated` | datum poslední podstatné změny `RRRR-MM-DD` (feed, `lastmod` v sitemap) |
| `summary` | jedna věta: popis v seznamu, meta description, Open Graph, souhrn ve feedu |

**Nová poznámka**

1. Přidat záznam do `content/notes.json`.
2. Zkopírovat složku existující poznámky do `public/poznamky/<slug>/` a přepsat sekce
   (každá sekce = popisek vlevo a odstavce vpravo). Slug se bere z názvu složky.
3. `php tools/seo-soubory.php` – přegeneruje `sitemap.xml` a `feed.atom`.
4. `python3 tools/balicek.py` – ověří, že poznámky, sitemap a feed sedí, a sestaví balíček.

Feed je Atom 1.0 (RFC 4287) se souhrny, bez plného textu. Manifest je jen popis webu
pro „přidat na plochu“ – **service worker, offline cache ani notifikace záměrně nejsou**.
Manifest má explicitní `"id": "/"` (identita nainstalované aplikace nezávislá na
`start_url`) a jeden statický výchozí `theme_color` a `background_color`; lištu
prohlížeče na načteném webu řídí `<meta name="theme-color">` podle světlého/tmavého motivu.

## Počasí (v1.2)

Sekce Počasí na úvodní stránce je první dynamická veřejná část webu. Data jsou
z [Open-Meteo](https://open-meteo.com/) (Forecast API a Geocoding API) pod licencí
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/); stránka proto pod počasím
uvádí „Data: Open-Meteo“ s odkazem. Web je nekomerční osobní projekt.

**Jak to funguje**

- Výchozí místo je Třebíč (veřejné centrum města, ne adresa bydliště). Vykresluje ho server
  při prvním načtení, funguje i bez JavaScriptu.
- Open-Meteo volá jen PHP (`src/weather.php`, `src/http.php`, timeout 3 s na spojení a 5 s
  celkem, limit odpovědi 64 kB, přesměrování se nesledují). Prohlížeč se k Open-Meteo
  nikdy nepřipojuje.
- Požadují se jen zobrazované údaje: aktuální teplota, vlhkost, kód počasí a vítr,
  denní kód počasí, minimum a maximum na 3 dny (°C, km/h).
- Odpověď se převede na vlastní model a uloží do `var/weather/<sha256("lat,lon")>.json`
  (jen normalizovaná data, bez souřadnic a bez zbytku odpovědi API).
- Souřadnice se všude zaokrouhlují na 2 desetinná místa (~1 km); klíč cache je jejich otisk.
- Když API selže, zobrazí se poslední dobrá data s neutrálním „data z 18:45“, po chybě se API
  pro stejné místo znovu volá až za 2 minuty. Bez použitelných dat se sekce nevykreslí.
  Nevalidní odpověď nikdy nepřepíše dobrou cache. Zápis cache je atomický (dočasný soubor + `rename`).

Čtyři časové a velikostní meze cache – každá znamená něco jiného:

| Mez | Hodnota | Význam |
|---|---|---|
| Platnost cache (TTL) | 15 minut | do té doby se Open-Meteo pro stejné místo znovu neptá |
| Nejvyšší stáří zobrazených dat | 6 hodin | jen při výpadku API; starší „aktuální“ počasí by klamalo, sekce se pak nevykreslí |
| Fyzická retence souboru | 7 dní | soubor s daty se maže až po 7 dnech – i když se jeho data po 6 hodinách už nezobrazí |
| Nejvyšší počet souborů | 500 | při překročení se smažou nejstarší |

Jedno místo = jeden soubor (obnova ho přepíše, nový vzniká jen pro nové místo). Úklid běží
při zápisu cache, bez cronu a bez databáze; právě zapsaný soubor a soubor výchozího místa
(Třebíč) nemaže nikdy. Značky chyb (`.fail`) se mažou po uplynutí 2minutového odkladu.

**Vlastní strop dotazů:** dotazy na Open-Meteo vyvolané návštěvníky (jiná místa a hledání)
mají společný strop **30 za minutu a 1 500 za den**. Chrání naše endpointy před zneužitím
jako neomezené proxy – nejde o kopii limitů Open-Meteo. Třebíč se nepočítá, její dotazy
omezuje už 15minutová cache. Stav je jediný soubor `var/weather/budget.json`, který se při
každém dotazu přepíše – `{"minute": <číslo minuty>, "m": <počet>, "day": "<datum UTC>", "d": <počet>}`.
Neobsahuje IP adresy, místa, dotazy ani historii; úklid ho nemaže, zůstává jen jeho poslední stav.

**Změna místa (JavaScript, `public/assets/js/weather.js`)**

- „změnit místo“ otevře pole „Místo“ pro název města, obce nebo jiného místa, tlačítko Hledat
  a „Použít moji polohu“. PSČ web nenabízí – Open-Meteo česká PSČ spolehlivě nenachází.
- Hledání jde přes `POST /api/mista/` (server volá Geocoding API: `count=5`, `language=cs`,
  bez omezení na Česko). Víc výsledků → seznam nejvýš 5 míst, jeden výsledek se vybere rovnou.
- Poloha zařízení: standardní `navigator.geolocation.getCurrentPosition()`, jen po kliknutí
  (nikdy při načtení stránky), přesnost se nevyžaduje. Souřadnice se před odesláním
  zaokrouhlí na 2 desetinná místa a místo se jmenuje „Moje poloha“. Po odmítnutí se o polohu
  znovu nežádá.
- Počasí pro zvolené místo načte `POST /api/pocasi/` a nahradí jen údaje v sekci.
- Volba se ukládá jen do `localStorage` (klíč `mh42-pocasi`: název a zaokrouhlené souřadnice),
  žádná cookie. „zpět na Třebíč“ volbu smaže.

**Endpointy** přijímají jen `POST` s `Content-Type: application/json` (tělo nejvýš 1 kB),
odmítají požadavky z cizích webů (`Sec-Fetch-Site`), vrací JSON s `Cache-Control: no-store`.
Data jsou v těle, ne v adrese, takže se nedostanou do logů přístupů. `/api/pocasi/` přijímá
jen čísla v rozsahu ±90 / ±180, `/api/mista/` jen 2–60 znaků (písmena, číslice, mezery,
`. , ' ’ ( ) -`). Adresy API jsou pevně v kódu nebo konfiguraci, od návštěvníka jdou jen
ověřené hodnoty parametrů. Server polohu ani dotazy neukládá a neloguje – do logu jde jen
hostitel API a důvod chyby, nikdy tělo požadavku.

**Nastavení** (`config/app.php`, klíč `weather`):

```php
'weather' => [
    'enabled'   => true,
    'default'   => ['label' => 'Třebíč', 'latitude' => 49.216, 'longitude' => 15.880],
    'timezone'  => 'Europe/Prague',
    'cache_ttl' => 900,
],
```

Když je `enabled` false nebo chybí název či souřadnice, sekce se nevykreslí a endpointy
vrací 404. Bez placeholderu a bez vymyšlených hodnot.

**Testy** (Open-Meteo se při nich nevolá):

- `php tools/test-pocasi.php` – nastavení, souřadnice, parser, kódy WMO, formátování,
  cache a výpadky API s podvrženým HTTP klientem, meze cache, hledání, šablony a úvodní
  stránka se zapnutým i vypnutým počasím. Spouští ho i `tools/balicek.py`.
  Přes Docker: `docker run --rm --user "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.3-cli-alpine php tools/test-pocasi.php`
- `python3 tools/test-pocasi-web.py [--snimky SLOŽKA]` – endpointy přes HTTP, HTTP klient
  (curl i streamy) a prohlížeč (headless Chromium): bez JavaScriptu, hledání, výběr,
  `localStorage`, návrat na Třebíč, poloha (povolená, odmítnutá, nedostupná, timeout,
  nepodporovaná), zaokrouhlení, Permissions-Policy, jen požadavky na stejný původ, tisk,
  forced colors. Místo Open-Meteo běží lokální náhrada s odpověďmi z `tools/testdata/open-meteo/`.
  Potřebuje Docker, Chromium a Python balíček `websockets`.
- `python3 tools/test-pocasi-web.py --nahled` – web s náhradou Open-Meteo na
  <http://127.0.0.1:8092> pro ruční vyzkoušení (hledání: Brno, Jihlava, Xqzvyk = nic, Chyba = výpadek).

Běžný vývojový server (`php -S …`) volá skutečné Open-Meteo – nejvýš jednou za 15 minut
pro jedno místo – a cache ukládá do `var/weather/` v repozitáři (git ji ignoruje).
