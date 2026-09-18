<?php

declare(strict_types=1);

// Projekty ze souboru content/projects.json (popis polí je v README).

const PROJECT_STATUSES = [
    'planned'     => 'plánováno',
    'development' => 've vývoji',
    'building'    => 've výstavbě',
    'live'        => 'v provozu',
    'paused'      => 'pozastaveno',
    'archived'    => 'archiv',
];

// Přístup popisuje dostupnost samotné aplikace nebo služby, ne popisu projektu –
// ten je na stránce Projekty veřejný vždy. Kde by jedna hodnota zkreslovala
// (např. infrastruktura s různými službami), pole "access" se vynechá.
const PROJECT_ACCESS = [
    'public'  => ['label' => 'veřejné',        'description' => 'Aplikace nebo služba je dostupná komukoli.'],
    'private' => ['label' => 'neveřejné',      'description' => 'Aplikace ani její data nejsou veřejně dostupné.'],
    'local'   => ['label' => 'jen domácí síť', 'description' => 'Rozhraní je dostupné jen z domácí sítě nebo přes VPN.'],
];

/**
 * Projekty seskupené podle skupin v pořadí ze souboru.
 * Projekty s "listed": false se vynechají, prázdné skupiny také.
 *
 * @return list<array{key: string, label: string, projects: list<array>}>
 */
function load_project_groups(): array
{
    $data = json_decode(
        (string) file_get_contents(APP_ROOT . '/content/projects.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $groups = [];
    foreach ($data['groups'] as $group) {
        $groups[$group['key']] = $group + ['projects' => []];
    }

    foreach ($data['projects'] as $project) {
        if (($project['listed'] ?? true) === false) {
            continue;
        }
        if (!isset($groups[$project['group']])) {
            throw new UnexpectedValueException("Projekt {$project['slug']} má neznámou skupinu {$project['group']}.");
        }
        $groups[$project['group']]['projects'][] = $project;
    }

    return array_values(array_filter($groups, static fn (array $group): bool => $group['projects'] !== []));
}

function project_status_label(string $status): string
{
    return PROJECT_STATUSES[$status] ?? $status;
}

function project_access_label(string $access): string
{
    return PROJECT_ACCESS[$access]['label'] ?? $access;
}
