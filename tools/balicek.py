#!/usr/bin/env python3
"""Sestaví produkční balíček webu do dist/.

Varianta A (preferovaná): public/ je kořen webu, src/ config/ content/ var/ leží vedle
něj (mimo web). Varianta B (záložní): všechno v jednom veřejném adresáři – obsah public/
v kořeni, src/ config/ content/ var/ jako podsložky se zákazem přístupu z webu.
var/ je pro běhová data (cache počasí) – v balíčku je prázdná, jen s .htaccess.

Před sestavením ověří, že vygenerované soubory (robots.txt, sitemap.xml, feed.atom,
manifest, ikony, OG obrázek, dlaždice pozadí) existují a sedí s konfigurací a poznámkami,
a spustí testy počasí (tools/test-pocasi.php – nastavení, cache, výpadky API, úvodní
stránka se zapnutým i vypnutým počasím; bez sítě). PHP kontroly běží v Dockeru.
Otisky assetů zapíše do config/asset-versions.php (verze v adresách podle obsahu).

Po sestavení ověří čistotu balíčku: produkční balíček je neměnný artefakt a nesmí
obsahovat běhová data (cache počasí, budget.json, značky chyb, dočasné soubory).
Kontrolují se obě varianty i oba ZIP archivy; při nálezu sestavení selže.
Integrační testy proto nikdy neběží nad balíčkem v dist/, ale nad jeho dočasnou
kopií – viz tools/test-balicek.py.

Spuštění:
  python3 tools/balicek.py [--bez-lintu]        # sestavit balíček
  python3 tools/balicek.py --kontrola [SLOŽKA]  # jen čistota existujícího balíčku
                                                # (bez SLOŽKY nejnovější v dist/)
"""
import hashlib, json, os, re, shutil, struct, subprocess, sys, time, zipfile
from pathlib import Path
from xml.dom import minidom

ROOT = Path(__file__).resolve().parent.parent
PUBLIC = ROOT / 'public'
DIST = ROOT / 'dist'

# Co z public/ nepatří na produkci (vývojové stránky).
PUBLIC_EXCLUDE = {'styleguide', 'assets/css/styleguide.css'}

DENY_HTACCESS = """# Interní soubory aplikace – z webu nedostupné.
<IfModule mod_authz_core.c>
  Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
  Order allow,deny
  Deny from all
</IfModule>
"""

errors = []

# ---------- Čistota balíčku ----------
# Balíček je neměnný artefakt pro ruční upload. Běhová data, která aplikace zapisuje za
# provozu, do něj nepatří: na produkci by přepsala živý stav a zároveň rozbíjejí porovnání
# balíčků, ze kterého se odvozuje delta nasazení. Ve var/ smí být jediný soubor.
VAR_POVOLENO = {'var/.htaccess'}
RUNTIME_JMENA = {'budget.json', '.DS_Store', 'Thumbs.db'}
RUNTIME_PRIPONY = ('.fail', '.tmp', '.swp', '.log', '~')
RUNTIME_SLOZKY = {'weather', '__pycache__', '.git'}


def runtime_duvod(rel):
    """Proč cesta v balíčku nemá co dělat – nebo None, když je v pořádku.

    Porovnává se na celé části cesty, ne na podřetězce: src/weather.php
    ani assets/js/weather.js nejsou běhová data.
    """
    parts = rel.split('/')
    name = parts[-1]
    if parts[0] == 'var' and rel not in VAR_POVOLENO:
        return 'běhová data ve var/ (povolený je jen var/.htaccess)'
    if RUNTIME_SLOZKY & set(parts[:-1]):
        return 'běhová složka'
    if name in RUNTIME_JMENA:
        return 'běhový soubor'
    if name.endswith(RUNTIME_PRIPONY) or name.startswith('.tmp-'):
        return 'dočasný soubor'
    return None


def runtime_nalezy(cesty):
    """[(cesta, důvod), …] pro cesty relativní ke kořeni varianty."""
    return [(rel, duvod) for rel in sorted(cesty) if (duvod := runtime_duvod(rel)) is not None]


def cesty_ve_slozce(root):
    return [p.relative_to(root).as_posix() for p in root.rglob('*') if p.is_file()]


def nalezy_ve_slozce(root):
    """Nálezy ve složce: běhové soubory podle cesty + symbolické odkazy.

    Symlink v balíčku nedává smysl (balíček se sestavuje kopírováním) a rglob do něj
    nezabíhá – byl by to slepý bod kontroly, kterým by runtime data mohla projít.
    """
    nalezy = runtime_nalezy(cesty_ve_slozce(root))
    nalezy += [(p.relative_to(root).as_posix(), 'symbolický odkaz')
               for p in sorted(root.rglob('*')) if p.is_symlink()]
    return sorted(set(nalezy))


def cesty_v_zipu(path):
    with zipfile.ZipFile(path) as z:
        return [n for n in z.namelist() if not n.endswith('/')]


def varianty(balicek):
    """Podsložky balíčku, které jsou deployment variantou (poznají se podle var/)."""
    return sorted(d for d in balicek.iterdir() if d.is_dir() and (d / 'var').is_dir())


def kontrola_cistoty(balicek):
    """Obě varianty i oba ZIPy. Vrací počet nálezů; podrobnosti vypíše."""
    print('\nKontrola čistoty balíčku (běhová data nepatří do deploymentu):')
    nalezeno = 0
    cile = [(d.name + '/', nalezy_ve_slozce(d), len(cesty_ve_slozce(d))) for d in varianty(balicek)]
    cile += [(z.name, runtime_nalezy(cesty_v_zipu(z)), len(cesty_v_zipu(z)))
             for z in sorted(balicek.glob('*.zip'))]
    if not cile:
        check(False, f'{balicek.name}: nenalezena žádná varianta ani ZIP ke kontrole')
        return 1

    for kde, nalezy, pocet in cile:
        nalezeno += len(nalezy)
        check(not nalezy, f'{kde} bez běhových dat ({pocet} souborů)'
              + (f' – NALEZENO {len(nalezy)}' if nalezy else ''))
        for rel, duvod in nalezy[:10]:
            print(f'          {rel}  ← {duvod}')
        if len(nalezy) > 10:
            print(f'          … a dalších {len(nalezy) - 10}')
    return nalezeno


def nejnovejsi_balicek():
    kandidati = sorted((d for d in DIST.glob('mh42-*') if d.is_dir()), key=lambda d: d.name)
    return kandidati[-1] if kandidati else None


def check(ok, message):
    print(('  ok    ' if ok else '  CHYBA ') + message)
    if not ok:
        errors.append(message)


def sha(path, n):
    return hashlib.sha1(path.read_bytes()).hexdigest()[:n]


def preflight(lint):
    print('Kontrola před sestavením:')
    config = (ROOT / 'config' / 'app.php').read_text(encoding='utf-8')
    base_url = re.search(r"'base_url'\s*=>\s*'([^']+)'", config).group(1).rstrip('/')
    env = re.search(r"'env'\s*=>\s*'([^']+)'", config).group(1)
    nav = re.findall(r"'href'\s*=>\s*'([^']+)'", config)
    check(env == 'production', f"config/app.php: env = '{env}'")
    check(base_url == 'https://mh42.cz', f'config/app.php: base_url = {base_url}')

    # poznámky: metadata v content/notes.json, stránka v public/poznamky/<slug>/index.php
    notes = json.loads((ROOT / 'content' / 'notes.json').read_text(encoding='utf-8'))['notes']
    notes = sorted(notes, key=lambda n: n['published'], reverse=True)   # stabilní, jako v src/notes.php
    slugs = [n['slug'] for n in notes]
    pages = sorted(d.name for d in (PUBLIC / 'poznamky').iterdir() if d.is_dir())
    check(sorted(slugs) == pages and all((PUBLIC / 'poznamky' / s / 'index.php').is_file() for s in slugs),
          f'poznámky: notes.json ↔ public/poznamky/<slug>/ ({len(slugs)})')
    check(len(set(slugs)) == len(slugs), 'poznámky: slugy jsou unikátní')

    robots = (PUBLIC / 'robots.txt').read_text(encoding='utf-8')
    check(f'Sitemap: {base_url}/sitemap.xml' in robots, 'robots.txt odkazuje na produkční sitemap')
    doc = minidom.parse(str(PUBLIC / 'sitemap.xml'))
    locs = [n.firstChild.data for n in doc.getElementsByTagName('loc')]
    expected = [base_url + p for p in ['/'] + nav] + [f'{base_url}/poznamky/{s}/' for s in slugs]
    check(locs == expected, f'sitemap.xml = úvodní stránka + navigace + poznámky ({len(locs)} adres)')

    feed = minidom.parse(str(PUBLIC / 'feed.atom'))
    entries = feed.getElementsByTagName('entry')
    ids = [e.getElementsByTagName('id')[0].firstChild.data for e in entries]
    titles = [e.getElementsByTagName('title')[0].firstChild.data for e in entries]
    updated = [e.getElementsByTagName('updated')[0].firstChild.data[:10] for e in entries]
    check(ids == [f'{base_url}/poznamky/{s}/' for s in slugs] and titles == [n['title'] for n in notes]
          and updated == [n['updated'] for n in notes], 'feed.atom odpovídá content/notes.json')

    manifest = json.loads((PUBLIC / 'manifest.webmanifest').read_text(encoding='utf-8'))
    check(manifest.get('id') == '/' and manifest.get('start_url') == '/' and manifest.get('scope') == '/',
          'manifest: id, start_url a scope = /')
    for item in manifest['icons']:
        path = PUBLIC / item['src'].lstrip('/')
        data = path.read_bytes() if path.is_file() else b''
        size = struct.unpack('>II', data[16:24]) if data else (0, 0)
        # PNG bez alfa kanálu: typ barev 0 (šedá) nebo 2 (RGB) v hlavičce IHDR
        opaque = len(data) > 25 and data[25] in (0, 2)
        purpose = item.get('purpose', 'any')
        check(f'{size[0]}x{size[1]}' == item['sizes'] and (purpose != 'maskable' or opaque),
              f'manifest: {item["src"]} {item["sizes"]} ({purpose}' + (', neprůhledná)' if opaque else ')'))

    og = PUBLIC / 'assets' / 'img' / 'og-image.png'
    w, h = struct.unpack('>II', og.read_bytes()[16:24]) if og.is_file() else (0, 0)
    check((w, h) == (1200, 630), f'og-image.png {w}×{h}')
    for name in ('favicon.ico', 'assets/img/favicon.svg', 'assets/img/apple-touch-icon.png'):
        check((PUBLIC / name).is_file(), f'{name} existuje')

    tokens = (PUBLIC / 'assets' / 'css' / 'tokens.css').read_text(encoding='utf-8')
    for tile in ('schema.svg', 'pcb.svg'):
        path = PUBLIC / 'assets' / 'img' / 'pozadi' / tile
        m = re.search(r'pozadi/' + re.escape(tile) + r'\?v=([0-9a-f]+)', tokens)
        check(path.is_file() and m and m.group(1) == sha(path, 8), f'pozadí {tile}: verze v tokens.css odpovídá obsahu')

    for css in sorted((PUBLIC / 'assets' / 'css').glob('*.css')):
        for ref in re.findall(r'url\("?([^")]+)"?\)', css.read_text(encoding='utf-8')):
            if ref.startswith('data:'):
                continue
            target = (css.parent / ref.split('?')[0]).resolve()
            check(target.is_file(), f'{css.name}: {ref.split("?")[0]} existuje')

    if lint:
        cmd = ['docker', 'run', '--rm', '-v', f'{ROOT}:/app', '-w', '/app', 'php:8.3-cli-alpine', 'sh', '-c',
               'for f in $(find public src config -name "*.php"); do php -l "$f" >/dev/null || echo "$f"; done']
        try:
            bad = subprocess.run(cmd, capture_output=True, text=True, timeout=120).stdout.strip()
            check(bad == '', 'PHP syntaxe (php 8.3)' + (f': {bad}' if bad else ''))
        except (OSError, subprocess.TimeoutExpired) as exc:
            print(f'  pozor PHP lint se nespustil ({exc}); spusť se --bez-lintu nebo zkontroluj ručně')

        # Počasí: platné nastavení v config/app.php, cache a výpadky API s podvrženým klientem,
        # úvodní stránka s vypnutým počasím (bez sekce) i se zapnutým a cache z fixture (se sekcí).
        cmd = ['docker', 'run', '--rm', '--user', f'{os.getuid()}:{os.getgid()}', '-v', f'{ROOT}:/app:ro', '-w', '/app',
               'php:8.3-cli-alpine', 'php', 'tools/test-pocasi.php']
        try:
            run = subprocess.run(cmd, capture_output=True, text=True, timeout=180)
            lines = run.stdout.strip().splitlines()
            failed = [line.strip() for line in lines if line.strip().startswith('CHYBA')]
            summary = lines[-1] if lines else run.stderr.strip()[-300:]
            check(run.returncode == 0, f'testy počasí (tools/test-pocasi.php): {summary}' + (f' – {failed[:3]}' if failed else ''))
        except (OSError, subprocess.TimeoutExpired) as exc:
            print(f'  pozor testy počasí se nespustily ({exc}); spusť ručně: php tools/test-pocasi.php')


def copy_public(target):
    for src in PUBLIC.rglob('*'):
        rel = src.relative_to(PUBLIC).as_posix()
        if any(rel == ex or rel.startswith(ex + '/') for ex in PUBLIC_EXCLUDE) or src.is_dir():
            continue
        dst = target / rel
        dst.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(src, dst)


def copy_app(target):
    for name in ('src', 'content'):
        shutil.copytree(ROOT / name, target / name)
    (target / 'config').mkdir(parents=True)
    shutil.copy2(ROOT / 'config' / 'app.php', target / 'config' / 'app.php')   # nikdy local.php
    # var/ – běhová data (cache počasí). Obsah z vývoje se nikdy nebalí; na serveru musí
    # být složka zapisovatelná pro PHP (viz docs/nasazeni.md). .htaccess chrání variantu B
    # a pro jistotu i A, kdyby se var/ ocitla v kořeni webu.
    (target / 'var').mkdir()
    (target / 'var' / '.htaccess').write_text(DENY_HTACCESS, encoding='utf-8')


def write_versions(config_dir):
    lines = ['<?php', '', '// Generováno nástrojem tools/balicek.py – otisky obsahu assetů pro asset(). Neupravovat ručně.', '',
             'return [']
    for path in sorted((PUBLIC / 'assets').rglob('*')):
        rel = path.relative_to(PUBLIC / 'assets').as_posix()
        if path.is_file() and not any(('assets/' + rel).startswith(ex) for ex in PUBLIC_EXCLUDE):
            lines.append(f"    '{rel}' => '{sha(path, 10)}',")
    lines += ['];', '']
    (config_dir / 'asset-versions.php').write_text('\n'.join(lines), encoding='utf-8')


def file_list(root, out):
    rows = []
    for path in sorted(p for p in root.rglob('*') if p.is_file()):
        rows.append(f'{hashlib.sha256(path.read_bytes()).hexdigest()}  {path.relative_to(root).as_posix()}')
    out.write_text('\n'.join(rows) + '\n', encoding='utf-8')
    return len(rows)


def zip_dir(root, out):
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
        for path in sorted(p for p in root.rglob('*') if p.is_file()):
            z.write(path, path.relative_to(root).as_posix())


def build():
    stamp = time.strftime('%Y-%m-%d-%H%M')
    out = DIST / f'mh42-{stamp}'
    a = out / 'A-oddeleny-koren'
    b = out / 'B-jeden-adresar'

    # Varianta A: stejné rozložení jako v repozitáři
    copy_public(a / 'public')
    copy_app(a)
    write_versions(a / 'config')

    # Varianta B: obsah public/ v kořeni, aplikace v podsložkách se zákazem přístupu
    copy_public(b)
    copy_app(b)
    write_versions(b / 'config')
    app = (b / '_app.php').read_text(encoding='utf-8')
    app = app.replace("require dirname(__DIR__) . '/src/bootstrap.php';", "require __DIR__ . '/src/bootstrap.php';")
    (b / '_app.php').write_text(app, encoding='utf-8')
    for name in ('src', 'config', 'content'):
        (b / name / '.htaccess').write_text(DENY_HTACCESS, encoding='utf-8')

    # Náhradní .htaccess pro případ, že hosting nepovolí „Options“ (HTTP 500)
    spare = out / 'nahradni-htaccess'
    spare.mkdir(parents=True)
    htaccess = (PUBLIC / '.htaccess').read_text(encoding='utf-8')
    no_options = htaccess.replace("""Options -Indexes
ErrorDocument 403 /404.php""", """# Hosting nepovolil „Options -Indexes“ – výpis složek místo toho zastaví DirectoryIndex:
# složka bez index.php zobrazí stránku 404 (se stavem 404).
DirectoryIndex index.php /404.php
ErrorDocument 403 /404.php""")
    assert no_options != htaccess
    (spare / 'htaccess-bez-options.txt').write_text(no_options, encoding='utf-8')

    counts = {}
    for variant in (a, b):
        counts[variant.name] = file_list(variant, out / f'SOUBORY-{variant.name}.txt')
        zip_dir(variant, out / f'{variant.name}.zip')
    return out, counts


RUNTIME_VYSVETLENI = """
Balíček obsahuje běhová data a NESMÍ se nasadit.
Vznikají, když se aplikace spustí přímo nad balíčkem v dist/ – typicky produkční
Apache test. Balíček je neměnný artefakt: integrační testy patří nad jeho dočasnou
kopii (python3 tools/test-balicek.py --apache), ne nad něj samotný.

Co s tím: balíček sestav znovu (python3 tools/balicek.py) a test pusť nad kopií.
Nalezené soubory tenhle nástroj záměrně nemaže – ani v pracovním projektu,
ani ve starších balíčcích, které slouží jako základ rollbacku."""


if __name__ == '__main__':
    if '--kontrola' in sys.argv:
        args = [a for a in sys.argv[1:] if not a.startswith('--')]
        balicek = Path(args[0]) if args else nejnovejsi_balicek()
        if balicek is None or not balicek.is_dir():
            sys.exit(f'Balíček ke kontrole nenalezen: {args[0] if args else "dist/mh42-*"}')
        print(f'Balíček: {balicek}')
        if kontrola_cistoty(balicek):
            sys.exit(RUNTIME_VYSVETLENI)
        print('\nBalíček je čistý – připravený k nasazení.')
        sys.exit(0)

    preflight(lint='--bez-lintu' not in sys.argv)
    if errors:
        sys.exit(f'\nBalíček nesestaven – {len(errors)} chyb.')
    out, counts = build()
    print(f'\nBalíček: {out.relative_to(ROOT)}')
    for name, n in counts.items():
        print(f'  {name}: {n} souborů ({name}.zip, SOUBORY-{name}.txt)')

    # Závěrečná kontrola: čerstvě sestavený balíček musí odcházet bez běhových dat.
    if kontrola_cistoty(out):
        sys.exit(RUNTIME_VYSVETLENI)
