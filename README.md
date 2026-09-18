# mh42.cz

Osobní web / osobní technický portál. HTML, CSS, trocha JavaScriptu a PHP jen tam,
kde má smysl (sdílené šablony a strukturovaná data – projekty, poznámky). Bez frameworku,
bez databáze, bez build nástrojů.

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
  styleguide/      přehled tokenů a komponent (jen APP_ENV=dev)
  assets/          CSS, JS, obrázky
src/
  bootstrap.php    společný začátek každé stránky (konfigurace, hlavičky);
                   stránky ho načítají přes public/_app.php
  helpers.php      e(), asset(), page_start(), page_end(), …
  projects.php     načtení projektů
  notes.php        načtení poznámek, adresy a data pro Atom
  templates/       hlavička, patička, kostra stránky, řádky seznamů
content/
  projects.json    projekty pro stránku Projekty
  notes.json       metadata poznámek (seznam, úvodní stránka, sitemap, feed)
config/
  app.php          veřejné nastavení (v gitu)
  local.php        lokální přepsání a tajné hodnoty (mimo git, volitelné)
docs/              návrh a dokumentace
tools/             vývojové nástroje a generátory obrázků (nejsou součástí webu)
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

Postup je v [docs/nasazeni.md](docs/nasazeni.md).

## Konvence

- Každá stránka je složka v `public/` s `index.php`. Čisté adresy tak fungují
  bez přepisovacích pravidel na Apachi (WEDOS) i na nginx nebo Caddy.
- Stránka začíná `page_start([...])` a končí `page_end()`; obsah mezi nimi je HTML.
- Texty jsou přímo v HTML stránek. Strukturovaná data (projekty) jsou v `content/`.
- Bezpečnostní hlavičky včetně CSP posílá PHP (`src/helpers.php`), takže platí
  na každém serveru stejně. Žádné inline skripty ani inline styly.
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
