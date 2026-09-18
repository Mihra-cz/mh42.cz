<?php

declare(strict_types=1);

// Poznámky: metadata v content/notes.json, text v public/poznamky/<slug>/index.php.
// Ze stejných dat vzniká seznam na webu, výběr na úvodní stránce, sitemap.xml a feed.atom.

const NOTES_TIMEZONE = 'Europe/Prague';

/**
 * Všechny poznámky od nejnovější. Při stejném datu rozhoduje pořadí v souboru.
 *
 * @return list<array{slug: string, title: string, published: string, updated: string, summary: string}>
 */
function load_notes(): array
{
    static $notes = null;
    if ($notes !== null) {
        return $notes;
    }

    $data = json_decode(
        (string) file_get_contents(APP_ROOT . '/content/notes.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $notes = $data['notes'];
    foreach ($notes as $note) {
        foreach (['published', 'updated'] as $field) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $note[$field] ?? '')) {
                throw new UnexpectedValueException("Poznámka {$note['slug']}: {$field} musí být ve tvaru RRRR-MM-DD.");
            }
        }
        if ($note['updated'] < $note['published']) {
            throw new UnexpectedValueException("Poznámka {$note['slug']}: updated je starší než published.");
        }
    }

    // usort je od PHP 8.0 stabilní – stejná data zůstanou v pořadí ze souboru.
    usort($notes, static fn (array $a, array $b): int => strcmp($b['published'], $a['published']));

    return $notes;
}

function find_note(string $slug): ?array
{
    foreach (load_notes() as $note) {
        if ($note['slug'] === $slug) {
            return $note;
        }
    }

    return null;
}

function note_url(array $note): string
{
    return '/poznamky/' . $note['slug'] . '/';
}

/** Datum z notes.json jako okamžik pro Atom (RFC 3339) – začátek dne v pražském čase. */
function note_datetime(string $date): string
{
    return (new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone(NOTES_TIMEZONE)))->format(DATE_ATOM);
}

/**
 * Začátek stránky jedné poznámky. Slug se bere z názvu složky stránky,
 * metadata (název, popis, data) z notes.json.
 */
function note_page_start(string $dir): array
{
    $note = find_note(basename($dir));
    if ($note === null) {
        throw new UnexpectedValueException('Poznámka ' . basename($dir) . ' chybí v content/notes.json.');
    }

    page_start([
        'key'         => 'poznamka',
        'path'        => note_url($note),
        'title'       => $note['title'],
        'description' => $note['summary'],
        'type'        => 'article',
    ]);
    template('note-start', ['note' => $note]);

    return $note;
}

function note_page_end(): void
{
    template('note-end');
    page_end();
}
