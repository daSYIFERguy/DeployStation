<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/docker.php';

/**
 * Structured starter template definitions.
 *
 * Each template is a self-contained scaffold that produces a fully
 * Docker-ready project out of the box. Definitions follow this shape:
 *
 *   [
 *     'key'                 => string  (matches the array key),
 *     'label'               => string,
 *     'description'         => string,
 *     'icon'                => string  (emoji),
 *     'stack'               => string  (node|php|python|static|other),
 *     'appPort'             => int     (port the container exposes),
 *     'recommendedServices' => string[] (preselected services on create),
 *     'files'               => array<relativePath, fileContents>
 *   ]
 *
 * Files are rendered with `{{PROJECT_NAME}}` and `{{PROJECT_SLUG}}`
 * placeholders replaced at create time. Each template ships its own
 * Dockerfile + .dockerignore so `station_ensure_project_dockerfile`
 * never has to guess.
 */

/**
 * Convenience helper used inside file contents to escape HTML safely.
 */
function station_template_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Built-in template registry. Returns an associative array keyed by
 * template slug (e.g. `node-express`). See file docblock for shape.
 *
 * @return array<string, array<string, mixed>>
 */
function station_builtin_template_definitions(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $templates = [];

    // ───────── static-html (default — vanilla HTML/CSS/JS on nginx) ─────────
    $templates['static-html'] = [
        'key' => 'static-html',
        'label' => 'Static HTML Site',
        'description' => 'A clean HTML/CSS/JS starter served by nginx — perfect for marketing sites or landing pages.',
        'icon' => '🌐',
        'stack' => 'static',
        'appPort' => 80,
        'recommendedServices' => [],
        'files' => [
            'index.html' => <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{PROJECT_NAME}}</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <header class="hero">
    <p class="eyebrow">Launched with Deployment Station</p>
    <h1>{{PROJECT_NAME}}</h1>
    <p class="lede">A polished static-site starter. Edit <code>index.html</code> to make it yours.</p>
    <a class="cta" href="#features">Get Started</a>
  </header>
  <section class="grid" id="features">
    <article class="card"><h2>Fast</h2><p>Served by nginx with HTTP keep-alive and gzip.</p></article>
    <article class="card"><h2>Portable</h2><p>Single image. Deploy anywhere docker runs.</p></article>
    <article class="card"><h2>Yours</h2><p>Drop your own HTML, CSS, JS, or images alongside this file.</p></article>
  </section>
  <script src="app.js"></script>
</body>
</html>
HTML,
            'styles.css' => <<<'CSS'
:root { --bg:#f7f9fc; --ink:#1f2937; --brand:#1a73e8; --card:#fff; }
* { box-sizing: border-box; }
body { margin:0; font-family: system-ui, sans-serif; background: linear-gradient(180deg,#fff,#f7f9fc); color: var(--ink); }
.hero { padding: 5rem 1.5rem 3rem; background: radial-gradient(circle at top left, #e8f0fe, transparent 40%); }
.eyebrow { text-transform: uppercase; letter-spacing: .12em; color: var(--brand); font-size: .75rem; }
.lede { max-width: 45rem; }
.cta { display: inline-block; background: var(--brand); color: #fff; padding: .8rem 1rem; border-radius: 999px; text-decoration: none; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px,1fr)); gap: 1rem; padding: 1.5rem; }
.card { background: var(--card); border: 1px solid #dbe3f0; border-radius: 1rem; padding: 1rem; box-shadow: 0 10px 25px rgba(26,115,232,.08); }
CSS,
            'app.js' => "console.log('{{PROJECT_NAME}} ready');\n",
            'README.md' => "# {{PROJECT_NAME}}\n\nA static HTML/CSS/JS starter served by nginx in Docker.\n\n## Run locally\n\n```bash\ndocker build -t {{PROJECT_SLUG}} .\ndocker run -p 8080:80 {{PROJECT_SLUG}}\n```\n",
            '.gitignore' => ".DS_Store\nnode_modules\n*.log\n",
            'Dockerfile' => <<<'DOCKER'
FROM nginx:alpine
COPY . /usr/share/nginx/html
EXPOSE 80
DOCKER,
            '.dockerignore' => "Dockerfile\n.dockerignore\n.htaccess\n.git\n.gitignore\nREADME.md\nnode_modules\n.DS_Store\n",
        ],
    ];

    // ───────── php (nginx + php-fpm) ─────────
    $templates['php-nginx'] = [
        'key' => 'php-nginx',
        'label' => 'PHP (Nginx + PHP-FPM)',
        'description' => 'PHP 8.3 starter served by nginx + php-fpm — no Apache anywhere in the stack.',
        'icon' => '🐘',
        'stack' => 'php',
        'appPort' => 80,
        'recommendedServices' => ['mysql'],
        'files' => [
            'index.php' => <<<'PHP'
<?php

declare(strict_types=1);

$title = '{{PROJECT_NAME}}';
$slug  = '{{PROJECT_SLUG}}';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title) ?></title>
</head>
<body style="font-family:system-ui;margin:2rem;color:#13233f;">
  <h1><?= htmlspecialchars($title) ?></h1>
  <p>PHP <?= htmlspecialchars(PHP_VERSION) ?> is running inside Docker (nginx + php-fpm).</p>
  <p>Slug: <code><?= htmlspecialchars($slug) ?></code></p>
</body>
</html>
PHP,
            'README.md' => "# {{PROJECT_NAME}}\n\nPHP 8.3 served by nginx + php-fpm. No Apache.\n\n```bash\ndocker build -t {{PROJECT_SLUG}} .\ndocker run -p 8080:80 {{PROJECT_SLUG}}\n```\n\nEdit `docker/nginx.conf` to tweak the web server. Edit `docker/supervisord.conf` to tweak the process supervisor.\n",
            '.gitignore' => "vendor/\n.env\n*.log\n.DS_Store\n",
            'Dockerfile' => station_php_nginx_dockerfile_text(),
            'docker/nginx.conf' => station_php_nginx_nginx_conf(),
            'docker/supervisord.conf' => station_php_nginx_supervisord_conf(),
            '.dockerignore' => "Dockerfile\n.dockerignore\n.htaccess\n.git\n.gitignore\nvendor\n.env\n*.log\n.DS_Store\n",
        ],
    ];

    // Back-compat aliases — the dropdown used to call this template `php-apache`.
    $templates['php-apache'] = $templates['php-nginx'];
    $templates['php-apache']['key'] = 'php-apache';
    $templates['php-apache']['label'] = 'PHP (Nginx + PHP-FPM)';

    // ───────── node-express ─────────
    $templates['node-express'] = [
        'key' => 'node-express',
        'label' => 'Node + Express',
        'description' => 'Express server on Node 22 (alpine). Listens on port 3000 with health endpoint.',
        'icon' => '🟢',
        'stack' => 'node',
        'appPort' => 3000,
        'recommendedServices' => ['postgres', 'redis'],
        'files' => [
            'package.json' => <<<'JSON'
{
  "name": "{{PROJECT_SLUG}}",
  "version": "1.0.0",
  "private": true,
  "type": "commonjs",
  "main": "server.js",
  "scripts": {
    "start": "node server.js",
    "dev": "node --watch server.js",
    "build": "echo \"no build step\""
  },
  "engines": {
    "node": ">=20"
  },
  "dependencies": {
    "express": "^4.19.2"
  }
}
JSON,
            'server.js' => <<<'JS'
const express = require('express');

const app = express();
const port = Number(process.env.PORT) || 3000;
const host = process.env.HOST || '0.0.0.0';

app.use(express.json());

app.get('/', (_req, res) => {
  res.type('html').send(`<!doctype html>
<html><head><meta charset="utf-8"><title>{{PROJECT_NAME}}</title></head>
<body style="font-family:system-ui;padding:2rem;color:#13233f">
  <h1>{{PROJECT_NAME}}</h1>
  <p>Node ${process.version} is running this Express app inside Docker.</p>
  <p>Try the <a href="/api/health">/api/health</a> endpoint.</p>
</body></html>`);
});

app.get('/api/health', (_req, res) => {
  res.json({ ok: true, project: '{{PROJECT_SLUG}}', node: process.version });
});

app.listen(port, host, () => {
  console.log(`{{PROJECT_NAME}} listening on http://${host}:${port}`);
});
JS,
            'README.md' => "# {{PROJECT_NAME}}\n\nMinimal Express server on Node 22.\n\n```bash\nnpm install\nnpm run dev\n```\n\nOr in Docker:\n\n```bash\ndocker build -t {{PROJECT_SLUG}} .\ndocker run -p 3000:3000 {{PROJECT_SLUG}}\n```\n",
            '.gitignore' => "node_modules/\nnpm-debug.log\n.env\n.DS_Store\n",
            'Dockerfile' => <<<'DOCKER'
FROM node:22-alpine
WORKDIR /app
ENV HOST=0.0.0.0
ENV PORT=3000
ENV NODE_ENV=production
COPY package*.json ./
RUN if [ -f package-lock.json ]; then npm ci --omit=dev=false; else npm install; fi
COPY . .
EXPOSE 3000
CMD ["npm", "start"]
DOCKER,
            '.dockerignore' => "Dockerfile\n.dockerignore\n.htaccess\n.git\n.gitignore\nnode_modules\nnpm-debug.log\n.env\n.DS_Store\n",
        ],
    ];

    // ───────── next-app ─────────
    $templates['next-app'] = [
        'key' => 'next-app',
        'label' => 'Next.js (App Router)',
        'description' => 'Minimal Next.js 14 app on Node 22. Boots with `npm run dev` or `npm start` in prod.',
        'icon' => '⚫',
        'stack' => 'node',
        'appPort' => 3000,
        'recommendedServices' => ['postgres'],
        'files' => [
            'package.json' => <<<'JSON'
{
  "name": "{{PROJECT_SLUG}}",
  "version": "0.1.0",
  "private": true,
  "scripts": {
    "dev": "next dev -p 3000",
    "build": "next build",
    "start": "next start -p 3000"
  },
  "dependencies": {
    "next": "^14.2.5",
    "react": "^18.3.1",
    "react-dom": "^18.3.1"
  },
  "engines": {
    "node": ">=20"
  }
}
JSON,
            'next.config.js' => <<<'JS'
/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  output: 'standalone'
};
module.exports = nextConfig;
JS,
            'app/layout.js' => <<<'JS'
export const metadata = {
  title: '{{PROJECT_NAME}}',
  description: 'Built with Next.js and Deployment Station.'
};

export default function RootLayout({ children }) {
  return (
    <html lang="en">
      <body style={{ fontFamily: 'system-ui', margin: 0, padding: '2rem', color: '#13233f' }}>
        {children}
      </body>
    </html>
  );
}
JS,
            'app/page.js' => <<<'JS'
export default function Home() {
  return (
    <main>
      <h1>{{PROJECT_NAME}}</h1>
      <p>Next.js 14 with the App Router is running in Docker.</p>
      <p>Edit <code>app/page.js</code> to start.</p>
    </main>
  );
}
JS,
            'README.md' => "# {{PROJECT_NAME}}\n\nA minimal Next.js 14 (App Router) starter.\n\n## Local\n\n```bash\nnpm install\nnpm run dev\n```\n\n## Docker\n\n```bash\ndocker build -t {{PROJECT_SLUG}} .\ndocker run -p 3000:3000 {{PROJECT_SLUG}}\n```\n",
            '.gitignore' => "node_modules/\n.next/\n.env\n.env.local\nnpm-debug.log\n.DS_Store\n",
            'Dockerfile' => <<<'DOCKER'
FROM node:22-alpine
WORKDIR /app
ENV HOST=0.0.0.0
ENV PORT=3000
ENV NODE_ENV=production
COPY package*.json ./
RUN if [ -f package-lock.json ]; then npm ci --omit=dev=false; else npm install; fi
COPY . .
RUN npm run build || echo 'skip build'
EXPOSE 3000
CMD ["npm", "start"]
DOCKER,
            '.dockerignore' => "Dockerfile\n.dockerignore\n.htaccess\n.git\n.gitignore\nnode_modules\n.next\n.env\n.env.local\n.DS_Store\n",
        ],
    ];

    // ───────── react-vite ─────────
    $templates['react-vite'] = [
        'key' => 'react-vite',
        'label' => 'React + Vite',
        'description' => 'Vite + React SPA built to /dist and served by nginx:alpine in production.',
        'icon' => '⚛️',
        'stack' => 'node',
        'appPort' => 80,
        'recommendedServices' => [],
        'files' => [
            'package.json' => <<<'JSON'
{
  "name": "{{PROJECT_SLUG}}",
  "version": "0.1.0",
  "private": true,
  "type": "module",
  "scripts": {
    "dev": "vite",
    "build": "vite build",
    "start": "vite preview --host 0.0.0.0 --port 3000",
    "preview": "vite preview --host 0.0.0.0 --port 3000"
  },
  "dependencies": {
    "react": "^18.3.1",
    "react-dom": "^18.3.1"
  },
  "devDependencies": {
    "@vitejs/plugin-react": "^4.3.1",
    "vite": "^5.4.0"
  }
}
JSON,
            'vite.config.js' => <<<'JS'
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  server: { host: '0.0.0.0', port: 3000 }
});
JS,
            'index.html' => <<<'HTML'
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{PROJECT_NAME}}</title>
  </head>
  <body>
    <div id="root"></div>
    <script type="module" src="/src/main.jsx"></script>
  </body>
</html>
HTML,
            'src/main.jsx' => <<<'JSX'
import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App.jsx';

createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
);
JSX,
            'src/App.jsx' => <<<'JSX'
import { useState } from 'react';

export default function App() {
  const [count, setCount] = useState(0);
  return (
    <main style={{ fontFamily: 'system-ui', padding: '2rem', color: '#13233f' }}>
      <h1>{{PROJECT_NAME}}</h1>
      <p>Vite + React is running.</p>
      <button onClick={() => setCount((c) => c + 1)}>Clicked {count} times</button>
    </main>
  );
}
JSX,
            'README.md' => "# {{PROJECT_NAME}}\n\nVite + React SPA.\n\n```bash\nnpm install\nnpm run dev\n```\n\nProduction build (Docker uses nginx:alpine to serve the built assets):\n\n```bash\ndocker build -t {{PROJECT_SLUG}} .\ndocker run -p 8080:80 {{PROJECT_SLUG}}\n```\n",
            '.gitignore' => "node_modules/\ndist/\n.env\n.env.local\n.DS_Store\n",
            'Dockerfile' => <<<'DOCKER'
FROM node:22-alpine AS build
WORKDIR /app
COPY package*.json ./
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi
COPY . .
RUN npm run build

FROM nginx:alpine
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80
DOCKER,
            '.dockerignore' => "Dockerfile\n.dockerignore\n.htaccess\n.git\n.gitignore\nnode_modules\ndist\n.env\n.env.local\n.DS_Store\n",
        ],
    ];

    // ───────── python-flask ─────────
    $templates['python-flask'] = [
        'key' => 'python-flask',
        'label' => 'Python + Flask',
        'description' => 'Flask app on Python 3.13 (slim). Gunicorn-ready, listens on port 8000.',
        'icon' => '🧪',
        'stack' => 'python',
        'appPort' => 8000,
        'recommendedServices' => ['postgres', 'redis'],
        'files' => [
            'requirements.txt' => "Flask==3.0.3\ngunicorn==22.0.0\n",
            'app.py' => <<<'PY'
import os

from flask import Flask, jsonify

app = Flask(__name__)


@app.get("/")
def index():
    return (
        "<!doctype html><html><head><meta charset=\"utf-8\">"
        "<title>{{PROJECT_NAME}}</title></head>"
        "<body style=\"font-family:system-ui;padding:2rem;color:#13233f\">"
        "<h1>{{PROJECT_NAME}}</h1>"
        "<p>Flask is running inside Docker.</p>"
        "<p>Try <a href=\"/api/health\">/api/health</a>.</p>"
        "</body></html>"
    )


@app.get("/api/health")
def health():
    return jsonify(ok=True, project="{{PROJECT_SLUG}}")


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=int(os.environ.get("PORT", "8000")))
PY,
            'README.md' => "# {{PROJECT_NAME}}\n\nFlask app on Python 3.13.\n\n```bash\npython -m venv .venv && source .venv/bin/activate\npip install -r requirements.txt\npython app.py\n```\n\nIn Docker:\n\n```bash\ndocker build -t {{PROJECT_SLUG}} .\ndocker run -p 8000:8000 {{PROJECT_SLUG}}\n```\n",
            '.gitignore' => ".venv/\n__pycache__/\n*.pyc\n.env\n.DS_Store\n",
            'Dockerfile' => <<<'DOCKER'
FROM python:3.13-slim
WORKDIR /app
ENV PYTHONUNBUFFERED=1
ENV PORT=8000
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY . .
EXPOSE 8000
CMD ["sh", "-c", "gunicorn --bind 0.0.0.0:${PORT:-8000} --workers 2 app:app"]
DOCKER,
            '.dockerignore' => "Dockerfile\n.dockerignore\n.htaccess\n.git\n.gitignore\n.venv\n__pycache__\n*.pyc\n.env\n.DS_Store\n",
        ],
    ];

    // ───────── python-fastapi ─────────
    $templates['python-fastapi'] = [
        'key' => 'python-fastapi',
        'label' => 'Python + FastAPI',
        'description' => 'FastAPI app on Python 3.13 with uvicorn. Listens on port 8000.',
        'icon' => '⚡',
        'stack' => 'python',
        'appPort' => 8000,
        'recommendedServices' => ['postgres', 'redis'],
        'files' => [
            'requirements.txt' => "fastapi==0.115.0\nuvicorn[standard]==0.30.6\n",
            'main.py' => <<<'PY'
import os

from fastapi import FastAPI
from fastapi.responses import HTMLResponse

app = FastAPI(title="{{PROJECT_NAME}}")


@app.get("/", response_class=HTMLResponse)
def root() -> str:
    return (
        "<!doctype html><html><head><meta charset=\"utf-8\">"
        "<title>{{PROJECT_NAME}}</title></head>"
        "<body style=\"font-family:system-ui;padding:2rem;color:#13233f\">"
        "<h1>{{PROJECT_NAME}}</h1>"
        "<p>FastAPI is running inside Docker.</p>"
        "<p>OpenAPI docs at <a href=\"/docs\">/docs</a>.</p>"
        "</body></html>"
    )


@app.get("/api/health")
def health() -> dict[str, object]:
    return {"ok": True, "project": "{{PROJECT_SLUG}}"}


if __name__ == "__main__":
    import uvicorn

    uvicorn.run(app, host="0.0.0.0", port=int(os.environ.get("PORT", "8000")))
PY,
            'README.md' => "# {{PROJECT_NAME}}\n\nFastAPI app on Python 3.13.\n\n```bash\npython -m venv .venv && source .venv/bin/activate\npip install -r requirements.txt\nuvicorn main:app --reload\n```\n\nIn Docker:\n\n```bash\ndocker build -t {{PROJECT_SLUG}} .\ndocker run -p 8000:8000 {{PROJECT_SLUG}}\n```\n",
            '.gitignore' => ".venv/\n__pycache__/\n*.pyc\n.env\n.DS_Store\n",
            'Dockerfile' => <<<'DOCKER'
FROM python:3.13-slim
WORKDIR /app
ENV PYTHONUNBUFFERED=1
ENV PORT=8000
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY . .
EXPOSE 8000
CMD ["sh", "-c", "uvicorn main:app --host 0.0.0.0 --port ${PORT:-8000}"]
DOCKER,
            '.dockerignore' => "Dockerfile\n.dockerignore\n.htaccess\n.git\n.gitignore\n.venv\n__pycache__\n*.pyc\n.env\n.DS_Store\n",
        ],
    ];

    // ───────── chrome-extension (preserved) ─────────
    $manifestJson = json_encode([
        'manifest_version' => 3,
        'name' => '{{PROJECT_NAME}}',
        'version' => '1.0.0',
        'action' => ['default_popup' => 'popup.html'],
        'permissions' => ['storage', 'activeTab', 'scripting'],
        'background' => ['service_worker' => 'background.js'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    $templates['chrome-extension'] = [
        'key' => 'chrome-extension',
        'label' => 'Chrome Extension (MV3)',
        'description' => 'Manifest V3 starter for a Chrome extension. Loadable as an unpacked extension.',
        'icon' => '🧩',
        'stack' => 'other',
        'appPort' => 80,
        'recommendedServices' => [],
        'files' => [
            'manifest.json' => $manifestJson . "\n",
            'popup.html' => <<<'HTML'
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>{{PROJECT_NAME}}</title>
  <link rel="stylesheet" href="popup.css">
</head>
<body>
  <main>
    <h1>{{PROJECT_NAME}}</h1>
    <p>Load unpacked in Chrome → Extensions.</p>
    <button id="inspectBtn">Inspect Page</button>
    <script src="popup.js"></script>
  </main>
</body>
</html>
HTML,
            'popup.css' => "body{font-family:system-ui,sans-serif;min-width:280px;padding:1rem}\nbutton{padding:.6rem .9rem;border:none;border-radius:999px;background:#1a73e8;color:#fff;cursor:pointer}\n",
            'popup.js' => "document.getElementById('inspectBtn').addEventListener('click', async () => {\n  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });\n  console.log('active tab', tab && tab.url);\n});\n",
            'background.js' => "chrome.runtime.onInstalled.addListener(() => {\n  console.log('{{PROJECT_NAME}} installed');\n});\n",
            'README.md' => "# {{PROJECT_NAME}}\n\nManifest V3 Chrome extension starter.\n\n1. Open Chrome → Extensions → Developer Mode.\n2. Click Load unpacked.\n3. Select this folder.\n\nThis project is hosted by the deployment station as a downloadable bundle. It does not need to run inside a container.\n",
            '.gitignore' => "*.crx\n*.pem\n.DS_Store\n",
            'Dockerfile' => <<<'DOCKER'
# Chrome extensions are downloaded and loaded into the browser locally —
# this image just serves the source files for inspection.
FROM nginx:alpine
COPY . /usr/share/nginx/html
EXPOSE 80
DOCKER,
            '.dockerignore' => "Dockerfile\n.dockerignore\n.htaccess\n.git\n.gitignore\n.DS_Store\n",
        ],
    ];

    // ───────── Legacy aliases (kept so existing projects keep launching) ─────────
    $templates['pwa'] = [
        'key' => 'pwa',
        'label' => 'PWA Web App',
        'description' => 'Single-page PWA with a service worker. Served by nginx.',
        'icon' => '📱',
        'stack' => 'static',
        'appPort' => 80,
        'recommendedServices' => [],
        'files' => [
            'index.html' => <<<'HTML'
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{PROJECT_NAME}}</title>
  <link rel="manifest" href="manifest.webmanifest">
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <h1>{{PROJECT_NAME}}</h1>
  <p>PWA starter app.</p>
  <script src="app.js"></script>
</body>
</html>
HTML,
            'app.js' => "if ('serviceWorker' in navigator) {\n  navigator.serviceWorker.register('./sw.js').catch(() => {});\n}\n",
            'styles.css' => "body { font-family: sans-serif; margin: 2rem; }\n",
            'manifest.webmanifest' => <<<'JSON'
{
  "name": "{{PROJECT_NAME}}",
  "short_name": "{{PROJECT_SLUG}}",
  "start_url": "./",
  "display": "standalone",
  "background_color": "#ffffff",
  "theme_color": "#0b7285"
}
JSON,
            'sw.js' => "self.addEventListener('install', () => self.skipWaiting());\nself.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));\nself.addEventListener('fetch', () => {});\n",
            'README.md' => "# {{PROJECT_NAME}}\n\nPWA starter served by nginx in Docker.\n",
            '.gitignore' => ".DS_Store\nnode_modules\n*.log\n",
            'Dockerfile' => <<<'DOCKER'
FROM nginx:alpine
COPY . /usr/share/nginx/html
EXPOSE 80
DOCKER,
            '.dockerignore' => "Dockerfile\n.dockerignore\n.htaccess\n.git\n.gitignore\nREADME.md\nnode_modules\n.DS_Store\n",
        ],
    ];

    $templates['php'] = $templates['php-apache'];
    $templates['php']['key'] = 'php';
    $templates['php']['label'] = 'PHP App Starter';

    $templates['static-js'] = [
        'key' => 'static-js',
        'label' => 'Static JS Site',
        'description' => 'Plain JS + CSS site, no framework. Served by nginx.',
        'icon' => '🟨',
        'stack' => 'static',
        'appPort' => 80,
        'recommendedServices' => [],
        'files' => [
            'index.html' => "<!doctype html>\n<html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>{{PROJECT_NAME}}</title><link rel=\"stylesheet\" href=\"styles.css\"></head><body><h1>{{PROJECT_NAME}}</h1><p>Static JS starter.</p><script src=\"app.js\"></script></body></html>\n",
            'styles.css' => "body { font-family: sans-serif; margin: 2rem; }\n",
            'app.js' => "console.log('{{PROJECT_NAME}} ready');\n",
            'README.md' => "# {{PROJECT_NAME}}\n\nVanilla JS static site served by nginx.\n",
            '.gitignore' => ".DS_Store\nnode_modules\n*.log\n",
            'Dockerfile' => <<<'DOCKER'
FROM nginx:alpine
COPY . /usr/share/nginx/html
EXPOSE 80
DOCKER,
            '.dockerignore' => "Dockerfile\n.dockerignore\n.htaccess\n.git\n.gitignore\nREADME.md\nnode_modules\n.DS_Store\n",
        ],
    ];

    // `node` legacy key — alias of node-express for backwards compat.
    $templates['node'] = $templates['node-express'];
    $templates['node']['key'] = 'node';
    $templates['node']['label'] = 'Node Starter';

    // `scraper-builder` — retained as static site (legacy)
    $templates['scraper-builder'] = [
        'key' => 'scraper-builder',
        'label' => 'Web Scraper Builder',
        'description' => 'Legacy hybrid dashboard + Chrome capture scaffold. Edit prompts and structure under Admin → Templates; project settings only cover env, GitHub, and Docker.',
        'icon' => '🕷️',
        'stack' => 'static',
        'appPort' => 80,
        'recommendedServices' => [],
        'files' => [
            'README.md' => "# {{PROJECT_NAME}}\n\nLegacy scraper-builder scaffold. Includes a dashboard/ folder and an extension/ folder.\n",
            'dashboard/index.html' => "<!doctype html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>{{PROJECT_NAME}}</title><link rel=\"stylesheet\" href=\"styles.css\"></head><body><main><h1>{{PROJECT_NAME}}</h1><form><label>Target Domain<input placeholder=\"example.com\"></label><label>Goal<textarea placeholder=\"Lead generation, product monitor, local SEO, etc.\"></textarea></label><button type=\"button\">Generate Scraper Plan</button></form></main></body></html>\n",
            'dashboard/styles.css' => "body{font-family:system-ui,sans-serif;margin:2rem;background:#f8fbff}form{display:grid;gap:1rem;max-width:720px}input,textarea{width:100%;padding:.75rem;border:1px solid #cdd8ea;border-radius:.75rem}button{padding:.8rem 1rem;background:#1a73e8;color:#fff;border:none;border-radius:999px}\n",
            'extension/manifest.json' => "{\n  \"manifest_version\": 3,\n  \"name\": \"{{PROJECT_NAME}} Capture\",\n  \"version\": \"1.0.0\",\n  \"permissions\": [\"storage\", \"activeTab\", \"scripting\"],\n  \"action\": { \"default_popup\": \"popup.html\" }\n}\n",
            'extension/popup.html' => "<!doctype html><html><body><h1>{{PROJECT_NAME}}</h1><p>Capture starter.</p></body></html>\n",
            'prompts/system-prompt.md' => "Build a scraper for DOMAIN using GOAL, outputting structured JSON.\n",
            '.gitignore' => ".DS_Store\nnode_modules\n*.log\n",
            'Dockerfile' => <<<'DOCKER'
FROM nginx:alpine
COPY ./dashboard /usr/share/nginx/html
EXPOSE 80
DOCKER,
            '.dockerignore' => "Dockerfile\n.dockerignore\n.htaccess\n.git\n.gitignore\nnode_modules\n.DS_Store\n",
        ],
    ];

    // `windows-app` — retained as static scaffold (no docker build feasible)
    $templates['windows-app'] = [
        'key' => 'windows-app',
        'label' => 'Windows App',
        'description' => 'Visual Studio WPF scaffold (legacy). Not built inside Docker.',
        'icon' => '🪟',
        'stack' => 'other',
        'appPort' => 80,
        'recommendedServices' => [],
        'files' => [
            'README.md' => "# {{PROJECT_NAME}}\n\nWindows desktop scaffold for Visual Studio / C#. The station stores and edits these files but does not compile Windows apps in-browser. The included Dockerfile only serves a placeholder page.\n",
            '{{PROJECT_SLUG}}.sln' => "Microsoft Visual Studio Solution File, Format Version 12.00\n# Visual Studio Version 17\n",
            '{{PROJECT_SLUG}}/App.xaml' => "<Application x:Class=\"{{PROJECT_NAME}}.App\" xmlns=\"http://schemas.microsoft.com/winfx/2006/xaml/presentation\" xmlns:x=\"http://schemas.microsoft.com/winfx/2006/xaml\" StartupUri=\"MainWindow.xaml\"></Application>\n",
            '{{PROJECT_SLUG}}/MainWindow.xaml' => "<Window x:Class=\"{{PROJECT_NAME}}.MainWindow\" xmlns=\"http://schemas.microsoft.com/winfx/2006/xaml/presentation\" xmlns:x=\"http://schemas.microsoft.com/winfx/2006/xaml\" Title=\"{{PROJECT_NAME}}\" Height=\"450\" Width=\"800\"><Grid><TextBlock Text=\"{{PROJECT_NAME}} starter\" VerticalAlignment=\"Center\" HorizontalAlignment=\"Center\" FontSize=\"28\"/></Grid></Window>\n",
            '{{PROJECT_SLUG}}/MainWindow.xaml.cs' => "using System.Windows;\nnamespace {{PROJECT_NAME}} { public partial class MainWindow : Window { public MainWindow() { InitializeComponent(); } } }\n",
            '.gitignore' => "bin/\nobj/\n*.user\n.DS_Store\n",
            'Dockerfile' => <<<'DOCKER'
FROM nginx:alpine
RUN echo '<!doctype html><meta charset=utf-8><title>Windows app</title><body style="font-family:system-ui;padding:40px;color:#13233f"><h1>Windows app placeholder</h1><p>This project is a Windows desktop scaffold and is not built inside Docker.</p></body>' > /usr/share/nginx/html/index.html
EXPOSE 80
DOCKER,
            '.dockerignore' => "Dockerfile\n.dockerignore\n.htaccess\n.git\n.gitignore\nbin\nobj\n*.user\n.DS_Store\n",
        ],
    ];

    $cached = $templates;
    return $cached;
}

/**
 * Returns the labels for built-in templates (back-compat shim used by
 * older callers expecting [key => label] map).
 */
function station_builtin_template_catalog(): array
{
    $catalog = [];
    foreach (station_builtin_template_definitions() as $key => $template) {
        $catalog[$key] = (string) ($template['label'] ?? $key);
    }
    return $catalog;
}

/**
 * Load custom templates from admin settings. Schema:
 *
 *   customTemplates: {
 *     [key]: { label, description, icon, stack, appPort, recommendedServices, files }
 *   }
 *
 * Returns only templates that have a key + label + at least one file.
 *
 * @return array<string, array<string, mixed>>
 */
function station_custom_template_definitions(): array
{
    $settings = station_admin_settings();
    $custom = isset($settings['customTemplates']) && is_array($settings['customTemplates']) ? $settings['customTemplates'] : [];
    $definitions = [];

    foreach ($custom as $key => $template) {
        if (!is_string($key) || !is_array($template)) {
            continue;
        }
        $safeKey = station_safe_name($key);
        $label = trim((string) ($template['label'] ?? ''));
        $files = isset($template['files']) && is_array($template['files']) ? $template['files'] : [];
        if ($safeKey === '' || $label === '' || $files === []) {
            continue;
        }
        $recommended = [];
        if (isset($template['recommendedServices']) && is_array($template['recommendedServices'])) {
            foreach ($template['recommendedServices'] as $svc) {
                if (is_string($svc) && $svc !== '') {
                    $recommended[] = $svc;
                }
            }
        }
        $definitions[$safeKey] = [
            'key' => $safeKey,
            'label' => $label,
            'description' => (string) ($template['description'] ?? ''),
            'icon' => (string) ($template['icon'] ?? '🧰'),
            'stack' => (string) ($template['stack'] ?? 'other'),
            'appPort' => (int) ($template['appPort'] ?? 80),
            'recommendedServices' => $recommended,
            'files' => $files,
            'custom' => true,
            'updatedAt' => (string) ($template['updatedAt'] ?? ''),
        ];
    }

    return $definitions;
}

/**
 * Return all available template definitions (built-in + custom).
 * Custom templates override built-in templates of the same key.
 *
 * @return array<string, array<string, mixed>>
 */
function station_template_definitions(): array
{
    $defs = [];
    foreach (station_builtin_template_definitions() as $key => $template) {
        $template['custom'] = false;
        $defs[$key] = $template;
    }
    foreach (station_custom_template_definitions() as $key => $template) {
        $defs[$key] = $template;
    }
    return $defs;
}

/**
 * Look up a single template definition by key. Returns null if missing.
 */
function station_template_definition(string $key): ?array
{
    $defs = station_template_definitions();
    $safe = station_safe_name($key);
    if (isset($defs[$key])) {
        return $defs[$key];
    }
    if ($safe !== $key && isset($defs[$safe])) {
        return $defs[$safe];
    }
    return null;
}

/**
 * Back-compat: returns [key => label] map for select inputs.
 */
function station_template_catalog(): array
{
    $catalog = [];
    foreach (station_template_definitions() as $key => $template) {
        $catalog[$key] = (string) ($template['label'] ?? $key);
    }
    return $catalog;
}

/**
 * Render a template's `files` map with `{{PROJECT_NAME}}` /
 * `{{PROJECT_SLUG}}` placeholders substituted. The keys are also
 * substituted so templates can use placeholders inside file paths.
 *
 * @return array<string, string>
 */
function station_template_render_files(array $template, string $projectName): array
{
    $files = isset($template['files']) && is_array($template['files']) ? $template['files'] : [];
    $slug = station_safe_name($projectName);
    $rendered = [];
    foreach ($files as $relative => $content) {
        if (!is_string($relative) || !is_string($content)) {
            continue;
        }
        $path = str_replace(
            ['{{PROJECT_NAME}}', '{{PROJECT_SLUG}}'],
            [$projectName, $slug],
            $relative
        );
        $body = str_replace(
            ['{{PROJECT_NAME}}', '{{PROJECT_SLUG}}'],
            [$projectName, $slug],
            $content
        );
        $rendered[$path] = $body;
    }
    return $rendered;
}

/**
 * Legacy compatibility helper used by older callers. Resolves the
 * template definition and returns its rendered files map.
 *
 * @return array<string, string>
 */
function station_template_files(string $type, string $projectName): array
{
    $definition = station_template_definition($type);
    if ($definition === null) {
        // Fallback — empty array means upload.php will error gracefully.
        return [];
    }
    return station_template_render_files($definition, $projectName);
}
