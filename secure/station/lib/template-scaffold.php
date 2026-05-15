<?php

declare(strict_types=1);

/**
 * Shared files merged into every new template project (Git + Docker + AI context).
 *
 * @return array<string, string>
 */
function station_template_scaffold_files(string $stack, int $appPort, bool $includeDocker = true): array
{
    $stack = strtolower(trim($stack));
    $port = max(1, $appPort);

    $files = [
        'DEPLOYSTATION.md' => station_template_scaffold_deploystation_md($stack, $port),
        '.env.example' => station_template_scaffold_env_example($stack, $port),
        'deploystation.project.json' => <<<'JSON'
{
  "schema": "deploystation.project/v1",
  "stack": "{{STACK}}",
  "appPort": {{APP_PORT}},
  "runtime": "docker",
  "dataInGit": false,
  "notes": "App uploads, databases, and runtime volumes must not be committed. Use .env locally."
}
JSON,
        '.github/workflows/ci.yml' => <<<'YAML'
name: CI
on:
  push:
    branches: [main, master]
  pull_request:
    branches: [main, master]
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Build Docker image
        run: docker build -t ${{ github.repository }}:ci .
YAML,
    ];

    $files['deploystation.project.json'] = str_replace(
        ['{{STACK}}', '{{APP_PORT}}'],
        [$stack, (string) $port],
        $files['deploystation.project.json']
    );

    if ($includeDocker && $stack !== 'extension') {
        $files['docker-compose.deploy.yml'] = station_template_scaffold_compose_overlay($port);
    }

    return $files;
}

function station_template_scaffold_deploystation_md(string $stack, int $appPort): string
{
    return <<<MD
# DeployStation project context

This file helps humans and AI assistants understand how this repository is meant to run on [DeployStation](https://github.com/daSYIFERguy/DeployStation) and on any Docker host.

## Runtime summary

| Field | Value |
|-------|--------|
| Stack | `{$stack}` |
| Container port | `{$appPort}` |
| Primary runtime | Docker (see `Dockerfile`) |
| Source in Git | Yes — application source only |
| Runtime data in Git | No — use volumes / `.env` |

## DeployStation (control plane)

When hosted on DeployStation:

- Project slug maps to `/p/{slug}/` behind the station reverse proxy.
- Enable **Docker** under **Manage → Docker** for this project.
- Configure **GitHub** under **Manage → GitHub** for push/pull (recommended for builders).
- Use **Files** (workspace) for browsing; prefer Git + your IDE for multi-file edits.
- **AI Assist** (clipboard FAB) can read this file and `README.md` for context.

## Docker on any host

```bash
cp .env.example .env
# edit .env, then:
docker build -t my-app .
docker run --rm -p 8080:{$appPort} --env-file .env my-app
```

With Compose (if `docker-compose.yml` or `docker-compose.deploy.yml` exists):

```bash
docker compose -f docker-compose.yml up -d --build
```

## Portable export

From DeployStation **Manage → Export portable bundle** produces a zip you can unzip on another server and run with Docker Compose.

## Do not commit

- `.env`, `.env.local`, secrets
- `node_modules/`, `vendor/`, build output
- Database files, uploads, caches, logs
- Docker volume data

MD;
}

function station_template_scaffold_env_example(string $stack, int $appPort): string
{
    $lines = [
        '# Copy to .env — never commit .env',
        'APP_ENV=development',
        'APP_PORT=' . $appPort,
    ];
    if (in_array($stack, ['node', 'static', 'other'], true)) {
        $lines[] = 'PORT=' . $appPort;
    }
    if ($stack === 'php') {
        $lines[] = 'DB_HOST=db';
        $lines[] = 'DB_DATABASE=app';
        $lines[] = 'DB_USERNAME=app';
        $lines[] = 'DB_PASSWORD=change-me';
    }

    return implode("\n", $lines) . "\n";
}

function station_template_scaffold_compose_overlay(int $appPort): string
{
    return <<<YAML
# Optional overlay for portable export / manual deploy (merge with project docker-compose.yml)
services:
  app:
    ports:
      - "127.0.0.1:8080:{$appPort}"
YAML;
}

/**
 * Merge scaffold into rendered template files (scaffold does not override template paths).
 *
 * @param array<string, string> $rendered
 * @return array<string, string>
 */
function station_template_merge_scaffold(array $rendered, array $template): array
{
    $stack = (string) ($template['stack'] ?? 'other');
    $appPort = (int) ($template['appPort'] ?? 80);
    $key = (string) ($template['key'] ?? '');
    $isExtension = $key === 'chrome-extension' || $stack === 'extension';

    $scaffold = station_template_scaffold_files($isExtension ? 'extension' : $stack, $appPort, !$isExtension);

    foreach ($scaffold as $path => $content) {
        if (!isset($rendered[$path]) || trim($rendered[$path]) === '') {
            $rendered[$path] = $content;
        }
    }

    if (isset($rendered['README.md'])) {
        $rendered['README.md'] = station_template_enhance_readme(
            $rendered['README.md'],
            $isExtension ? 'extension' : $stack,
            $appPort
        );
    }

    return $rendered;
}

function station_template_enhance_readme(string $readme, string $stack, int $appPort): string
{
    if (str_contains($readme, 'DeployStation')) {
        return $readme;
    }

    $block = <<<MD


## DeployStation & Docker

This template is ready for DeployStation and standalone Docker hosts.

- See `DEPLOYSTATION.md` for AI/deploy context.
- Copy `.env.example` to `.env` before running.
- Default container port: **{$appPort}** (`{$stack}` stack).

MD;

    return rtrim($readme) . $block . "\n";
}
