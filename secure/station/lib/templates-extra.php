<?php

declare(strict_types=1);

/**
 * Additional built-in templates and overrides (loaded after core definitions).
 *
 * @return array<string, array<string, mixed>>
 */
function station_extra_template_definitions(): array
{
    $chromeManifest = json_encode([
        'manifest_version' => 3,
        'name' => '{{PROJECT_NAME}}',
        'version' => '1.0.0',
        'description' => 'Extension package for {{PROJECT_NAME}}',
        'action' => ['default_popup' => 'extension/popup.html'],
        'permissions' => ['storage', 'activeTab', 'scripting'],
        'background' => ['service_worker' => 'extension/background.js'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';

    return [
        'chrome-extension' => [
            'key' => 'chrome-extension',
            'label' => 'Chrome Extension (MV3)',
            'description' => 'Browser extension with an install splash page and scripts to build zip packages for Chrome, Edge, and Firefox.',
            'icon' => '🧩',
            'stack' => 'extension',
            'appPort' => 80,
            'recommendedServices' => [],
            'files' => [
                'install/index.html' => station_chrome_install_splash_html(),
                'install/install.css' => station_chrome_install_css(),
                'extension/manifest.json' => $chromeManifest,
                'extension/popup.html' => <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>{{PROJECT_NAME}}</title>
  <link rel="stylesheet" href="popup.css">
</head>
<body>
  <main>
    <h1>{{PROJECT_NAME}}</h1>
    <p>Extension popup — load unpacked from the <code>extension/</code> folder.</p>
    <button type="button" id="inspectBtn">Log active tab URL</button>
  </main>
  <script src="popup.js"></script>
</body>
</html>
HTML,
                'extension/popup.css' => "body{font-family:system-ui,sans-serif;min-width:280px;padding:1rem;color:#13233f}\nbutton{padding:.6rem .9rem;border:none;border-radius:999px;background:#1a73e8;color:#fff;cursor:pointer}\n",
                'extension/popup.js' => "document.getElementById('inspectBtn').addEventListener('click', async () => {\n  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });\n  console.log('active tab', tab && tab.url);\n});\n",
                'extension/background.js' => "chrome.runtime.onInstalled.addListener(() => console.log('{{PROJECT_NAME}} installed'));\n",
                'scripts/package-extension.sh' => station_chrome_package_script(),
                'dist/README.md' => "# Extension packages\n\nRun from project root:\n\n```bash\nchmod +x scripts/package-extension.sh\n./scripts/package-extension.sh\n```\n\nOutputs:\n\n- `dist/chrome-mv3.zip` — Chrome / Brave / Edge (Load unpacked or publish)\n- `dist/firefox-mv3.zip` — Firefox temporary add-on (about:debugging)\n\nSafari requires Xcode and a separate wrapper; see Apple's extension docs.\n",
                'README.md' => <<<'MD'
# {{PROJECT_NAME}}

Browser extension (Manifest V3) with a web **install** page and packaged zips under `dist/`.

## Install page (DeployStation / Docker)

When this project runs in Docker, open the station launch URL or `http://localhost:8080/install/` to see download instructions.

## Developer workflow

1. Edit sources under `extension/`.
2. Run `./scripts/package-extension.sh` to refresh `dist/*.zip`.
3. Chrome → Extensions → Developer mode → **Load unpacked** → select `extension/`.
4. Firefox → `about:debugging` → **Load temporary add-on** → pick `dist/firefox-mv3.zip` or manifest.

## GitHub

Commit `extension/` and `install/` — do not commit secrets. Zips in `dist/` may be committed as release artifacts or built in CI.
MD,
                '.gitignore' => "*.crx\n*.pem\n.DS_Store\ndist/*.zip\n",
                'Dockerfile' => <<<'DOCKER'
FROM nginx:alpine
COPY install /usr/share/nginx/html/install
COPY dist /usr/share/nginx/html/dist
RUN echo '<!doctype html><meta http-equiv="refresh" content="0;url=install/">' > /usr/share/nginx/html/index.html
EXPOSE 80
DOCKER,
                '.dockerignore' => "Dockerfile\n.dockerignore\n.git\n.gitignore\nnode_modules\n.DS_Store\n",
            ],
        ],
        'go-api' => [
            'key' => 'go-api',
            'label' => 'Go HTTP API',
            'description' => 'Minimal Go 1.22 HTTP server in Docker, GitHub-ready.',
            'icon' => '🐹',
            'stack' => 'other',
            'appPort' => 8080,
            'recommendedServices' => [],
            'files' => station_go_api_files(),
        ],
        'django' => [
            'key' => 'django',
            'label' => 'Django (Python)',
            'description' => 'Django 5 starter with Gunicorn-ready Dockerfile.',
            'icon' => '🐍',
            'stack' => 'python',
            'appPort' => 8000,
            'recommendedServices' => ['postgres'],
            'files' => station_django_files(),
        ],
        'astro-site' => [
            'key' => 'astro-site',
            'label' => 'Astro Static Site',
            'description' => 'Astro static build served by nginx.',
            'icon' => '🚀',
            'stack' => 'static',
            'appPort' => 80,
            'recommendedServices' => [],
            'files' => station_astro_files(),
        ],
        'hono-api' => [
            'key' => 'hono-api',
            'label' => 'Hono API (Node)',
            'description' => 'Lightweight TypeScript API on Node 20.',
            'icon' => '⚡',
            'stack' => 'node',
            'appPort' => 3000,
            'recommendedServices' => ['redis'],
            'files' => station_hono_files(),
        ],
        'wordpress-php' => [
            'key' => 'wordpress-php',
            'label' => 'WordPress (PHP)',
            'description' => 'WordPress with external MySQL — use DeployStation MySQL service.',
            'icon' => '📝',
            'stack' => 'php',
            'appPort' => 80,
            'recommendedServices' => ['mysql'],
            'files' => station_wordpress_files(),
        ],
        'bun-api' => [
            'key' => 'bun-api',
            'label' => 'Bun API',
            'description' => 'Bun runtime HTTP server.',
            'icon' => '🥟',
            'stack' => 'node',
            'appPort' => 3000,
            'recommendedServices' => [],
            'files' => station_bun_files(),
        ],
    ];
}

function station_chrome_install_splash_html(): string
{
    return <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{PROJECT_NAME}} — Install extension</title>
  <link rel="stylesheet" href="install.css">
</head>
<body>
  <main class="wrap">
    <p class="kicker">Browser extension</p>
    <h1>{{PROJECT_NAME}}</h1>
    <p class="lede">Download a zip for your browser, or load the <code>extension/</code> folder unpacked during development.</p>

    <section class="cards">
      <article class="card">
        <h2>Chrome / Edge / Brave</h2>
        <ol>
          <li>Download <a href="../dist/chrome-mv3.zip">chrome-mv3.zip</a> (build with <code>scripts/package-extension.sh</code> if missing).</li>
          <li>Unzip, or use <strong>Load unpacked</strong> with the <code>extension/</code> directory.</li>
          <li>Open <code>chrome://extensions</code> → Developer mode → Load unpacked.</li>
        </ol>
      </article>
      <article class="card">
        <h2>Firefox</h2>
        <ol>
          <li>Download <a href="../dist/firefox-mv3.zip">firefox-mv3.zip</a>.</li>
          <li>Open <code>about:debugging</code> → This Firefox → Load temporary add-on.</li>
        </ol>
      </article>
      <article class="card">
        <h2>Safari</h2>
        <p>Safari requires Apple&apos;s Xcode conversion workflow — not included in the zip bundle.</p>
      </article>
    </section>

    <p class="note">Hosted by DeployStation. Source lives in Git; runtime zips are build artifacts.</p>
  </main>
</body>
</html>
HTML;
}

function station_chrome_install_css(): string
{
    return <<<'CSS'
:root { --ink:#13233f; --muted:#587092; --brand:#2f7de2; --card:#fff; --line:#dbe5f4; }
* { box-sizing: border-box; }
body { margin:0; font-family: system-ui, sans-serif; background: linear-gradient(180deg,#f7faff,#eef3fb); color: var(--ink); }
.wrap { max-width: 720px; margin: 0 auto; padding: 2.5rem 1.25rem 3rem; }
.kicker { text-transform: uppercase; letter-spacing: .12em; font-size: .72rem; color: var(--brand); font-weight: 700; }
.lede { color: var(--muted); line-height: 1.6; }
.cards { display: grid; gap: 1rem; margin: 1.5rem 0; }
.card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 1.25rem; box-shadow: 0 12px 32px rgba(19,35,63,.06); }
.card h2 { margin: 0 0 .75rem; font-size: 1.1rem; }
.card ol { margin: 0; padding-left: 1.2rem; color: var(--ink); line-height: 1.65; }
.card a { color: var(--brand); font-weight: 600; }
.note { font-size: .85rem; color: var(--muted); }
code { background: #f0f4fc; padding: 2px 6px; border-radius: 4px; font-size: .9em; }
CSS;
}

function station_chrome_package_script(): string
{
    return <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/dist"
EXT="$ROOT/extension"
mkdir -p "$DIST"
if [[ ! -d "$EXT" ]]; then echo "Missing extension/ directory" >&2; exit 1; fi
(cd "$EXT" && zip -r "$DIST/chrome-mv3.zip" . -x "*.DS_Store")
(cp "$DIST/chrome-mv3.zip" "$DIST/edge-mv3.zip")
(cd "$EXT" && zip -r "$DIST/firefox-mv3.zip" . -x "*.DS_Store")
echo "Wrote $DIST/chrome-mv3.zip, edge-mv3.zip, firefox-mv3.zip"
BASH;
}

function station_go_api_files(): array
{
    return [
        'main.go' => <<<'GO'
package main

import (
	"encoding/json"
	"log"
	"net/http"
	"os"
)

func main() {
	port := os.Getenv("PORT")
	if port == "" { port = "8080" }
	http.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]string{"status": "ok", "app": "{{PROJECT_NAME}}"})
	})
	http.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte("{{PROJECT_NAME}} API"))
	})
	log.Printf("listening on :%s", port)
	log.Fatal(http.ListenAndServe(":"+port, nil))
}
GO,
        'go.mod' => "module {{PROJECT_SLUG}}\n\ngo 1.22\n",
        'README.md' => "# {{PROJECT_NAME}}\n\nGo HTTP API. `docker build -t {{PROJECT_SLUG}} . && docker run -p 8080:8080 {{PROJECT_SLUG}}`\n",
        '.gitignore' => "bin/\n*.exe\n.env\n",
        'Dockerfile' => <<<'DOCKER'
FROM golang:1.22-alpine AS build
WORKDIR /app
COPY go.mod ./
COPY . .
RUN go build -o /server .
FROM alpine:3.20
WORKDIR /app
COPY --from=build /server /app/server
ENV PORT=8080
EXPOSE 8080
CMD ["/app/server"]
DOCKER,
        '.dockerignore' => "Dockerfile\n.git\n.env\nbin/\n",
    ];
}

function station_django_files(): array
{
    return [
        'manage.py' => "#!/usr/bin/env python\nimport os\nimport sys\nif __name__ == '__main__':\n    os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'config.settings')\n    from django.core.management import execute_from_command_line\n    execute_from_command_line(sys.argv)\n",
        'config/settings.py' => "import os\nfrom pathlib import Path\nBASE_DIR = Path(__file__).resolve().parent.parent\nSECRET_KEY = os.environ.get('DJANGO_SECRET_KEY', 'dev-only-change-me')\nDEBUG = os.environ.get('DEBUG', '1') == '1'\nALLOWED_HOSTS = ['*']\nINSTALLED_APPS = ['django.contrib.contenttypes', 'django.contrib.staticfiles']\nROOT_URLCONF = 'config.urls'\nWSGI_APPLICATION = 'config.wsgi.application'\nDATABASES = {'default': {'ENGINE': 'django.db.backends.sqlite3', 'NAME': BASE_DIR / 'data/db.sqlite3'}}\nSTATIC_URL = 'static/'\n",
        'config/urls.py' => "from django.http import JsonResponse\nfrom django.urls import path\ndef health(_): return JsonResponse({'status': 'ok', 'app': '{{PROJECT_NAME}}'})\nurlpatterns = [path('', health), path('health/', health)]\n",
        'config/wsgi.py' => "import os\nfrom django.core.wsgi import get_wsgi_application\nos.environ.setdefault('DJANGO_SETTINGS_MODULE', 'config.settings')\napplication = get_wsgi_application()\n",
        'requirements.txt' => "Django>=5.0,<6\ngunicorn>=22.0\n",
        'README.md' => "# {{PROJECT_NAME}}\n\nDjango starter. Run migrations in container on first deploy.\n",
        '.gitignore' => ".env\n__pycache__/\ndata/\nstaticfiles/\n",
        'Dockerfile' => <<<'DOCKER'
FROM python:3.12-slim
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY . .
RUN mkdir -p data
ENV PORT=8000
EXPOSE 8000
CMD ["sh", "-c", "python manage.py migrate --run-syncdb 2>/dev/null || true; gunicorn config.wsgi:application --bind 0.0.0.0:${PORT:-8000}"]
DOCKER,
        '.dockerignore' => ".git\n.env\n__pycache__\ndata/\n",
    ];
}

function station_astro_files(): array
{
    return [
        'package.json' => json_encode(['name' => '{{PROJECT_SLUG}}', 'type' => 'module', 'scripts' => ['build' => 'echo "static scaffold — add Astro CLI"', 'start' => 'npx serve dist'], 'devDependencies' => ['serve' => '^14.0.0']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        'dist/index.html' => "<!doctype html><html><head><meta charset=utf-8><title>{{PROJECT_NAME}}</title></head><body><h1>{{PROJECT_NAME}}</h1><p>Astro scaffold — run <code>npm create astro@latest</code> to replace this stub.</p></body></html>\n",
        'README.md' => "# {{PROJECT_NAME}}\n\nStatic site scaffold. Replace with Astro CLI output, then `npm run build`.\n",
        '.gitignore' => "node_modules/\ndist/\n.env\n",
        'Dockerfile' => <<<'DOCKER'
FROM nginx:alpine
COPY dist /usr/share/nginx/html
EXPOSE 80
DOCKER,
        '.dockerignore' => "node_modules\n.git\n.env\n",
    ];
}

function station_hono_files(): array
{
    return [
        'package.json' => json_encode(['name' => '{{PROJECT_SLUG}}', 'type' => 'module', 'scripts' => ['start' => 'node src/index.js'], 'dependencies' => ['hono' => '^4.0.0', '@hono/node-server' => '^1.0.0']], JSON_PRETTY_PRINT) . "\n",
        'src/index.js' => "import { Hono } from 'hono';\nimport { serve } from '@hono/node-server';\nconst app = new Hono();\napp.get('/health', (c) => c.json({ status: 'ok', app: '{{PROJECT_NAME}}' }));\napp.get('/', (c) => c.text('{{PROJECT_NAME}}'));\nconst port = Number(process.env.PORT || 3000);\nserve({ fetch: app.fetch, port });\nconsole.log('listening', port);\n",
        'README.md' => "# {{PROJECT_NAME}}\n\nHono API on Node 20.\n",
        '.gitignore' => "node_modules/\n.env\n",
        'Dockerfile' => <<<'DOCKER'
FROM node:20-alpine
WORKDIR /app
COPY package.json ./
RUN npm install --omit=dev
COPY . .
ENV PORT=3000
EXPOSE 3000
CMD ["npm", "start"]
DOCKER,
        '.dockerignore' => "node_modules\n.git\n.env\n",
    ];
}

function station_wordpress_files(): array
{
    return [
        'README.md' => <<<'MD'
# {{PROJECT_NAME}}

WordPress via official image — connect to MySQL using DeployStation Docker services.

Use `docker-compose.yml` generated by the station or extend with:

```yaml
services:
  wordpress:
    image: wordpress:latest
    ports: ["8080:80"]
    environment:
      WORDPRESS_DB_HOST: db
      WORDPRESS_DB_USER: app
      WORDPRESS_DB_PASSWORD: ${DB_PASSWORD}
      WORDPRESS_DB_NAME: app
```

Do not commit uploads or database dumps.
MD,
        'docker-compose.wordpress.yml' => <<<'YAML'
services:
  wordpress:
    image: wordpress:6-apache
    ports:
      - "127.0.0.1:8080:80"
    environment:
      WORDPRESS_DB_HOST: ${DB_HOST:-db}
      WORDPRESS_DB_USER: ${DB_USER:-app}
      WORDPRESS_DB_PASSWORD: ${DB_PASSWORD:-change-me}
      WORDPRESS_DB_NAME: ${DB_NAME:-app}
    volumes:
      - wp_data:/var/www/html
volumes:
  wp_data:
YAML,
        '.gitignore' => ".env\nwp-content/uploads/\n",
        'Dockerfile' => <<<'DOCKER'
FROM wordpress:6-apache
# Use docker-compose.wordpress.yml for full stack
EXPOSE 80
DOCKER,
        '.dockerignore' => ".git\n.env\n",
    ];
}

function station_bun_files(): array
{
    return [
        'package.json' => json_encode(['name' => '{{PROJECT_SLUG}}', 'scripts' => ['start' => 'bun run src/index.ts'], 'dependencies' => ['hono' => '^4.0.0']], JSON_PRETTY_PRINT) . "\n",
        'src/index.ts' => "import { Hono } from 'hono';\nconst app = new Hono();\napp.get('/health', (c) => c.json({ status: 'ok' }));\napp.get('/', (c) => c.text('{{PROJECT_NAME}}'));\nexport default { port: Number(process.env.PORT || 3000), fetch: app.fetch };\n",
        'README.md' => "# {{PROJECT_NAME}}\n\nBun + Hono API.\n",
        '.gitignore' => "node_modules/\n.env\n",
        'Dockerfile' => <<<'DOCKER'
FROM oven/bun:1
WORKDIR /app
COPY package.json bun.lockb* ./
RUN bun install --production
COPY . .
ENV PORT=3000
EXPOSE 3000
CMD ["bun", "run", "src/index.ts"]
DOCKER,
        '.dockerignore' => "node_modules\n.git\n.env\n",
    ];
}
