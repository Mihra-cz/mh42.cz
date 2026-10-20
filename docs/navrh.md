# mh42.cz – schválený návrh

Stav k 2026-09-18. Návrh schválil autor webu; tento dokument shrnuje rozhodnutí,
podle kterých se web staví.

## Koncepce

`mh42.cz` je osobní web, ne rozcestník aplikací. Má tři vrstvy:

| Vrstva | Obsah | Váha |
|---|---|---|
| Osobní | kdo jsem, čemu se věnuji, co teď řeším | hlavní |
| Projektová | stránka Projekty – popisy, stav, úroveň přístupu | vedlejší |
| Datová | několik bezpečných hodnot z domácích systémů | doplněk, až budou skutečná data |

Projekt a služba nejsou totéž: popis projektu může být veřejný, aplikace a data ne.

Zásady: web funguje i bez dat a bez JavaScriptu; nezávisí na backendech aplikací;
žádné cookies ani analytika třetích stran; žádné vymyšlené hodnoty; texty píše autor.

## Rozhodnutí autora

- Identita webu je `mh42`. Celé jméno nemá být dominantně na homepage, může být na O mně.
- Kontakt: GitHub. Veřejný e-mail zatím ne.
- AnimeDB a MangaDB mohou být na stránce Projekty s krátkým popisem, ale bez odkazu
  na aplikace a bez jejich hostname. (Minimalizace propagace, ne zabezpečení.)
- Položka Poznámky vznikne až se skutečným obsahem (od v1.1 – tři první poznámky).
- Home Assistant ani podobný systém nebude veřejně dostupný; správa vzdáleně přes VPN.
- První verze jen česky, bez infrastruktury pro více jazyků.

## Homepage

Pořadí: hlavička → Úvod → Čemu se věnuji → Teď → Poznámky (max. 3 nejnovější, datum a název) → Počasí → (Stav) → patička.

- **Úvod** – nadpis je jedna popisná věta, pod ní 1–2 věty a odkaz na GitHub. Bez jména, fotky a tlačítek.
- **Čemu se věnuji** – 3–4 oblasti (název + 1–2 věty), projekty jen jako kontextové odkazy.
- **Teď** – 3–5 bodů s datem poslední aktualizace.
- **Počasí** – místo, aktuální teplota s popisem, vlhkost a vítr, 3 dny (min / max, popis),
  čas aktualizace a „Data: Open-Meteo“. Bez barevné karty a ikon; nevykreslí se bez dat (od v1.2).
- **Stav** – max. 4 hodnoty s časem měření. Nevykreslí se, dokud nejsou skutečná data.
- Na homepage záměrně nejsou dlaždice projektů, loga technologií ani grafy.

## Navigace

`mh42` (= Domů) · O mně · Projekty · Poznámky · přepínač motivu. Bez hamburgeru: pod 30rem
dva řádky (wordmark a přepínač, pod nimi navigace), od 30rem jeden řádek.
Žádné tlačítko „Přihlásit“ – přihlášení probíhá až na subdoméně aplikace.

## Projekty

Řádky, ne karty, ve skupinách (zatím Aplikace · Domácnost a infrastruktura · Tento web;
prázdné skupiny se nezobrazují). U každého projektu krátký popis, stav, úroveň přístupu
a technologie jen tam, kde dávají smysl. Popis projektu je veřejný vždy; úroveň přístupu
se týká samotné aplikace nebo služby: `public` (veřejné), `private` (neveřejné – bez odkazu
a hostname), `local` (jen domácí síť nebo VPN). Kde by jedna hodnota zkreslovala
(HomeLab), štítek se nezobrazuje. `listed: false` projekt nevypíše. Podstránka projektu vznikne jen tam, kde pro ni bude skutečný obsah.
Data v `content/projects.json`, popis polí v README.

## Vizuální směr „technický list“

- Desktop a tablet (od 48rem): levý sloupec s popisky sekcí (mono, verzálky), vpravo obsah;
  sekce oddělené 1px linkou s krátkým akcentním úsekem. Pod 48rem popisek nad obsahem.
- IBM Plex Sans (text 17 px / 1,6) + IBM Plex Mono (wordmark, popisky, metadata, data ve tvaru `2026-09-18`).
- Světlý „papír“ a tmavý „grafit“, jediný rezavý akcent: `42` ve wordmarku, úsek linky,
  pomlčky v seznamu Teď, hover a focus. Bez stínů a přechodů.
- Motiv podle systému, přepínač ho přepíše; volba shodná se systémem se zapomene.
- Pohyb jen krátké přechody barvy; `prefers-reduced-motion` respektováno.

Tokeny jsou v `public/assets/css/tokens.css`, přehled na `/styleguide/` (jen dev).

## Veřejné a soukromé části

| Zóna | Příklady | Mechanismus |
|---|---|---|
| Veřejná | `mh42.cz` | – |
| Soukromá přes internet | `anime.mh42.cz`, `manga.mh42.cz` | Cloudflare Access + ověření `Cf-Access-Jwt-Assertion` v aplikaci |
| Lokální | Home Assistant, kamery, alarm, správa | nepublikovat; vzdáleně VPN |

Pravidla: jeden hostname = jeden režim přístupu; origin nedostupný zvenku (jen Tunnel);
soukromé aplikace nenasazovat na WEDOS jen za Access; hostname není tajemství (CT logy);
portál nemá cookies, aplikace jen host-only cookies.

## Domácí data (fáze 2)

Domov data posílá (push), portál si pro ně nechodí. Publisher na domácím serveru má
explicitní seznam povolených hodnot, zaokrouhluje a agreguje, posílá v pevném intervalu
na `/api/ingest.php` s HMAC podpisem. Portál má vlastní seznam povolených metrik,
ukládá jen poslední stav a zastaralé hodnoty nezobrazuje. Nikdy ven: přítomnost, alarm,
dveře a okna, kamery, detaily zařízení, okamžitá spotřeba.

## Postup

Fáze 1 – veřejný portál bez dat:

- [x] 1. Kostra projektu, šablony, tři stránky
- [x] 2. Tokeny, typografie, motivy, IBM Plex z vlastního serveru, `/styleguide/`
- [x] 3. Hlavička, navigace, patička, přepínač motivu, skip link
- [x] 4. Homepage (zatím s pracovními texty)
- [x] 5. Projekty z `content/projects.json`
- [x] 6. O mně včetně „Jak tenhle web běží“ (pracovní text)
- [x] 7. 404, ikony, meta a OG, `robots.txt`, `sitemap.xml`
- [x] 8. Cache hlavičky, přístupnost, validace, test šířek (audit 2026-09-18)
- [x] 9. Nasazení na WEDOS + Cloudflare – v1 nasazená 2026-09-18, viz `docs/nasazeni.md`

## v1.1 – obsah

- [x] Aktualizovaná sekce „Teď“ na úvodní stránce, bez pracovního komentáře
- [x] Položka „Poznámky“ v navigaci, navigace na úzkém displeji ve dvou řádcích
- [x] `/poznamky/` – řádkový seznam (datum, název, popis) seskupený podle roku, od nejnovější
- [x] `/poznamky/<slug>/` – poznámka jako technický list (popisky sekcí vlevo, text vpravo)
- [x] Metadata poznámek jen v `content/notes.json` (`src/notes.php`)
- [x] Tři první poznámky: mh42.cz v1, Proč tenhle web nemá framework, Schéma ve dne, PCB v noci
- [x] Úvodní stránka: sekce Poznámky (3 nejnovější) s odkazem „všechny poznámky“
- [x] Atom feed `/feed.atom` (RFC 4287, souhrny) + autodiscovery v `<head>` a odkaz na stránce Poznámky
- [x] `sitemap.xml` s poznámkami a `lastmod`, generovaná ze stejných dat
- [x] `/manifest.webmanifest` s `"id": "/"`, ikonami 192 a 512 px (`any`) a samostatnou
  `icon-512-maskable.png` (grafika do 26 % poloměru, bezpečná zóna 40 %), bez service workeru.
  Barvy manifestu jsou statické výchozí; lištu prohlížeče řídí dynamické `<meta name="theme-color">`.
- [x] Poznámky: vlastní title, description, canonical, `og:type=article`, `og:url`
- [x] Kontroly nasazení a balíčku rozšířené o poznámky, feed a manifest

## v1.2 – počasí

První skutečně dynamická veřejná data. Rozhodnutí autora:

- Zdroj: Open-Meteo (Forecast a Geocoding API), nekomerční osobní web, viditelná atribuce
  „Data: Open-Meteo“ s odkazem (data CC BY 4.0).
- Prohlížeč návštěvníka nikdy nekomunikuje s Open-Meteo – všechno volá PHP na serveru,
  prohlížeč mluví jen s mh42.cz.
- Výchozí místo je Třebíč – veřejné centrum města (49.216, 15.880), ne adresa bydliště.
  Poloha autora se nikde nevymýšlí ani neodhaduje.
- Tři režimy: výchozí Třebíč (server, funguje bez JavaScriptu), ruční hledání města
  (i zahraničního) a volitelně poloha zařízení přes standardní Geolocation API – jen po
  kliknutí, bez automatické žádosti o oprávnění, zaokrouhlená na 2 desetinná místa, s názvem
  „Moje poloha“ (bez reverse geocodingu).
- Hledání: pole „Místo“ pro město, obec nebo jiný název místa. PSČ se nenabízí – česká PSČ
  Open-Meteo spolehlivě nenachází a kvůli nim nevznikne integrace další služby. Jeden
  výsledek se vybere rovnou; jiná místa než výchozí používají `timezone=auto`.
- Volba místa jen v `localStorage`, žádné cookies; „zpět na Třebíč“ ji smaže.
- Server neukládá přesnou polohu, historii poloh ani dotazy; cache je jen poslední stav
  pro zaokrouhlené souřadnice pod jejich otiskem.
- Cache: platnost 15 minut; při výpadku API poslední dobrá data s neutrálním „data z …“,
  zobrazená nejvýš 6 hodin stará; bez dat se sekce nevykreslí. Fyzická retence je jiná věc
  než stáří zobrazitelných dat: soubor se maže až po 7 dnech, souborů je nejvýš 500
  (navíc se mažou nejstarší). Jedno místo = jeden soubor, úklid při zápisu, bez databáze;
  právě zapsaný soubor a Třebíč jsou při úklidu chráněné.
- Vlastní strop dotazů na Open-Meteo vyvolaných návštěvníky: 30 za minutu, 1 500 za den –
  ochrana endpointů před zneužitím, ne kopie limitů Open-Meteo. Třebíč mimo strop
  (omezuje ji cache). Strop drží jen dva čítače, žádné IP adresy ani historii.
- Model soukromí se nemění: poloha se zaokrouhluje v prohlížeči i na serveru, tělo
  požadavku se neloguje, název souboru cache je otisk souřadnic, cache neobsahuje
  souřadnice, žádná historie poloh, cookies ani analytika.
- Vzhled: sekce technického listu, žádná weather karta ani dashboard; aktuální teplota
  je nejvýraznější číslo. Význam nikdy jen barvou nebo ikonou.

- [x] `config/app.php` – `weather` (Třebíč, Europe/Prague, cache 900 s); bez platného nastavení se nic nevykreslí
- [x] `src/weather.php` – Open-Meteo, normalizovaný model, kódy WMO → čeština, cache, výpadky, strop dotazů
- [x] `src/http.php` – HTTP klient bez knihoven (curl, záložně streamy), timeouty, limit velikosti
- [x] `public/api/pocasi/`, `public/api/mista/` – POST JSON endpointy s validací, no-store
- [x] Sekce Počasí na úvodní stránce (šablony `weather.php`, `weather-data.php`)
- [x] `public/assets/js/weather.js` – změna místa, hledání, poloha zařízení, `localStorage`
- [x] `Permissions-Policy: geolocation=(self)`, ostatní omezení beze změny
- [x] O mně, „Jak tenhle web běží“: věta o počasí z Open-Meteo a o tom, že prohlížeč komunikuje jen s mh42.cz
- [x] Testy bez Open-Meteo (`tools/test-pocasi.php`, `tools/test-pocasi-web.py`), kontrola balíčku a nasazení
- [x] Jednorázová diagnostika WEDOS (odchozí HTTPS na Open-Meteo, zápis ve `var/`), viz `docs/nasazeni.md`
- [x] Nasazení na WEDOS (A2 delta + `var/`) – v1.2 nasazená 2026-09-18, viz `docs/nasazeni.md`

Fáze 2 – data: komponenta Stav, `api/ingest.php`, publisher.
Fáze 3 – domácí server: Tunnel, Access, VPN, přesun portálu.

## v1.3 – podzimní aktualizace obsahu

Obsahová verze bez nové technické funkce: web má odpovídat skutečnému stavu projektů
k 2026-10-20. Počasí (Open-Meteo, cache, strop dotazů, geolokace, endpointy) se nemění.

- [x] Sekce „Teď“ aktualizovaná k 2026-10-20: Nexus provozně připravený, AnimeDB a příprava
  jejího nasazení, NexusSTAT ve vývoji (na Nexusu zatím neběží), domácí automatizace stále ve fázi návrhu
- [x] „Čemu se věnuji“: HomeLab už nezní jako pouhý plán – server existuje a je provozně
  připravený, služby na něj přecházejí postupně, správa a interní služby zůstávají neveřejné
- [x] HomeLab v `content/projects.json`: `building` → `live` („v provozu“ odpovídá skutečnosti),
  summary popisuje Nexus – Linux na ARM64, oddělování aplikací do kontejnerů a připravenou
  strukturu projektů, dat a dokumentace i pravidla pro zálohu a obnovu;
  technologie Linux · Docker · ARM64
- [x] Texty rozlišují **provozované / připravené / plánované**: server běží, struktura a pravidla
  jsou připravená, kontejnerizace služeb a ověřená obnova zatím ne. Nikde se netvrdí, že Nexus
  má hotové a vyzkoušené zálohování ani že všechny aplikace už běží v kontejnerech
- [x] AnimeDB: stav zůstává `development`, summary doplněno o přípravu bezpečného nasazení
  na Nexus a o to, že produkčně tam ještě neběží; technologie beze změny
- [x] Nový projekt NexusSTAT (skupina Domácnost a infrastruktura, `development`, přístup `local`):
  diagnostika stavu Nexusu a vybraných služeb. Podle veřejného repozitáře je rozpracovaný –
  systémový a síťový kolektor, pravidelné měření, základ historie v SQLite, simulátor OLED
  a automatizované testy – ale na Nexusu nasazený není; vývoj se nezaměňuje s nasazením
  a lokální webové rozhraní se neoznačuje za hotové
- [x] MangaDB a Domácí automatizace zůstávají `planned` – stav se nemění kvůli dojmu aktivity
- [x] Nová poznámka „Nexus: domácí server dostává pevnou strukturu“ (2026-10-20), ze stejných
  dat se přegenerovaly `sitemap.xml` a `feed.atom` (`tools/seo-soubory.php`)
- [x] O mně: rozlišeno, že Nexus běží, jednotlivé služby se teprve připravují a AnimeDB
  na něm produkčně neběží
- [x] Pozadí: ochranné pásmo i kolem tabulky předpovědi počasí – nález na 320 px ve světlém
  motivu, kdy čára motivu procházela jejími řádky
- [x] Veřejné texty bez interních IP adres, cest, mountů, názvů share, kapacit a identifikátorů
- [ ] Nasazení v1.3 – **zatím neproběhlo**, `docs/nasazeni.md` proto zůstává na stavu v1.2

## Odložený vizuální polish: pozadí

Až bude základ webu hotový, přibude velmi decentní motiv pozadí inspirovaný elektronikou:
světlý režim jako schéma ve světle červené (tenké linky, uzly, značky, kontrast skoro jako vodoznak),
tmavý režim jako layout desky plošných spojů v jemně zelené (cesty, pady, prokovy, vrstvy; o něco
výraznější, ale stále jemný). Nesmí snižovat čitelnost ani odvádět pozornost, žádné
výrazné barvy, RGB ani herní vzhled. Prototyp: `prototyp/pozadi-pcb.html`
(generuje `tools/pozadi-pcb.py`).

Vybraná je **varianta B** – středně výrazná, pod hlavním textovým sloupcem zeslabená
na 65 %, naplno jen ve volných okrajích na širších obrazovkách. Varianta A zůstává
v prototypu jako jemnější záloha, varianta C se dál nerozvíjí.

Varianta B je na webu (`public/assets/css/background.css`, dlaždice
`public/assets/img/pozadi/`). Kolem vodorovných dělicích linek je ochranné pásmo
(±0,75rem s měkkým přechodem), do kterého motiv nezasahuje: u linek sekcí, hlavičky
a patičky přes celou šířku okna, u oddělovačů projektů a poznámek 8rem za jejich konce.
Pásma jsou kreslená jako `border-image`, takže jejich přesah do stran nerozšiřuje
stránku. Při zvýšeném kontrastu, vynucených barvách a tisku se motiv ani pásma nezobrazují.

Od v1.3 má vlastní pásmo i tabulka předpovědi počasí: tam čára motivu nevedla přes dělicí
linku, ale přes řádky tabulky, kde vypadala jako podtržení nebo linka tabulky. Pásmo kopíruje
celou výšku tabulky (±0,5rem) a přesahuje 1rem za její okraje – stejná technika, jen místo
pevných 1,5rem kolem linky.
