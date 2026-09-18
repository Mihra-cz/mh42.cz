#!/usr/bin/env python3
"""Vyhodnocení hlaviček Cache-Control pro kontroly nasazení a testy.

Hosting může k odpovědi přidat vlastní hlavičku (WEDOS k dynamickým odpovědím PHP přidává
„Cache-Control: max-age=300“ a Expires +5 min). Kontroly proto nikdy nečtou jen první
hlavičku: sloučí direktivy ze všech výskytů Cache-Control a vyhodnocují jejich význam,
ne pořadí hlaviček.

  python3 tools/hlavicky.py     # testy s fixtures, bez sítě
"""
import http.client, io, sys


def cache_directives(headers):
    """Direktivy ze všech hlaviček Cache-Control: {název: [hodnoty]} (název malými písmeny).

    Direktiva bez hodnoty má hodnotu None; opakovaná direktiva má víc hodnot.
    """
    directives = {}
    for line in headers.get_all('Cache-Control') or []:
        for part in line.split(','):
            name, has_value, value = part.strip().partition('=')
            if name:
                directives.setdefault(name.strip().lower(), []).append(value.strip().strip('"') if has_value else None)
    return directives


def cache_text(headers):
    """Všechny hlavičky Cache-Control pro výpis, např. „no-cache | max-age=300“."""
    return ' | '.join(headers.get_all('Cache-Control') or []) or '—'


def html_cache_ok(headers):
    """HTML: prohlížeč musí před použitím ověřit (no-cache nebo no-store) a nesmí jít o immutable.

    Přidaný max-age od hostingu nevadí – no-cache i tak vynutí revalidaci.
    """
    d = cache_directives(headers)
    return ('no-cache' in d or 'no-store' in d) and 'immutable' not in d


def api_cache_ok(headers):
    """JSON z API: no-store má přednost před jakýmkoli max-age – odpověď se neukládá."""
    return 'no-store' in cache_directives(headers)


def static_cache_ok(headers, max_age, immutable=False):
    """Statický soubor: všechny výskyty max-age mají očekávanou hodnotu, bez no-store/no-cache.

    Dva různé max-age (např. náš rok a přidaných 300 s) by byly nejednoznačné → chyba.
    """
    d = cache_directives(headers)
    ok = d.get('max-age') and set(d['max-age']) == {str(max_age)} and 'no-store' not in d and 'no-cache' not in d
    return bool(ok) and (not immutable or 'immutable' in d)


# ---------- testy s fixtures ----------

def fixture(*lines):
    """Hlavičky odpovědi z textových řádků, jak by je vrátil server (opakované hlavičky zůstanou zvlášť)."""
    return http.client.parse_headers(io.BytesIO(('\r\n'.join(lines) + '\r\n\r\n').encode('latin-1')))


FIXTURES = [
    # (popis, řádky hlaviček, HTML ok, API ok, rok+immutable ok, týden ok)
    ('WEDOS u HTML: no-cache + max-age=300', ('Cache-Control: no-cache', 'Cache-Control: max-age=300'), True, False, False, False),
    ('WEDOS u JSON: no-store + max-age=300', ('Cache-Control: no-store', 'Cache-Control: max-age=300'), True, True, False, False),
    ('asset: public, max-age=31536000, immutable', ('Cache-Control: public, max-age=31536000, immutable',), False, False, True, False),
    ('obrácené pořadí: max-age=300 + no-cache', ('Cache-Control: max-age=300', 'Cache-Control: no-cache'), True, False, False, False),
    ('jedna hlavička: no-cache, max-age=300', ('Cache-Control: no-cache, max-age=300',), True, False, False, False),
    ('velká písmena: No-Cache + Max-Age=300', ('Cache-Control: No-Cache', 'cache-control: Max-Age=300'), True, False, False, False),
    ('HTML jen s max-age=300 (chybí no-cache)', ('Cache-Control: max-age=300',), False, False, False, False),
    ('JSON s no-cache, bez no-store', ('Cache-Control: no-cache', 'Cache-Control: max-age=300'), True, False, False, False),
    ('asset + přidaný max-age=300 (nejednoznačné)', ('Cache-Control: public, max-age=31536000, immutable', 'Cache-Control: max-age=300'), False, False, False, False),
    ('asset bez immutable', ('Cache-Control: public, max-age=31536000',), False, False, False, False),
    ('obrázek: public, max-age=604800', ('Cache-Control: public, max-age=604800',), False, False, False, True),
    ('bez Cache-Control', ('Content-Type: text/html',), False, False, False, False),
    ('HTML s immutable', ('Cache-Control: no-cache', 'Cache-Control: immutable'), False, False, False, False),
]


def self_test():
    failures = 0
    for name, lines, html, api, year, week in FIXTURES:
        h = fixture(*lines)
        got = (html_cache_ok(h), api_cache_ok(h), static_cache_ok(h, 31536000, immutable=True), static_cache_ok(h, 604800))
        ok = got == (html, api, year, week)
        failures += not ok
        print(f'  {"ok    " if ok else "CHYBA "} {name}: HTML {got[0]}, API {got[1]}, asset rok {got[2]}, týden {got[3]}  [{cache_text(h)}]')
    h = fixture('Cache-Control: no-cache', 'Cache-Control: max-age=300')
    parsed = cache_directives(h) == {'no-cache': [None], 'max-age': ['300']}
    failures += not parsed
    print(f'  {"ok    " if parsed else "CHYBA "} sloučené direktivy: {cache_directives(h)}')
    print(f'\nVýsledek: {len(FIXTURES) + 1 - failures} ok, {failures} chyb')
    return failures == 0


if __name__ == '__main__':
    sys.exit(0 if self_test() else 1)
