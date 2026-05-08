<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function station_template_catalog(): array
{
    return [
        'pwa' => 'New PWA Web App',
        'static-html' => 'New Static HTML Site',
        'chrome-extension' => 'New Chrome Extension',
        'scraper-builder' => 'New Web Scraper Builder',
        'windows-app' => 'New Windows App',
        'php' => 'PHP App Starter',
        'static-js' => 'Static JS Site',
        'node' => 'Node Starter'
    ];
}

function station_template_files(string $type, string $projectName): array
{
    $safeName = station_h($projectName);

    if ($type === 'static-html') {
        return [
            'index.html' => "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>{$safeName}</title><link rel=\"stylesheet\" href=\"styles.css\"></head><body><header class=\"hero\"><div><p class=\"eyebrow\">Launch faster</p><h1>{$safeName}</h1><p class=\"lede\">A polished static site starter for blog, ecommerce teaser, or service company websites.</p><a class=\"cta\" href=\"#contact\">Get Started</a></div></header><section class=\"grid\"><article class=\"card\"><h2>Services</h2><p>Describe your main offer.</p></article><article class=\"card\"><h2>Portfolio</h2><p>Showcase key work.</p></article><article class=\"card\"><h2>Contact</h2><p id=\"contact\">Put a form, email, or CTA here.</p></article></section></body></html>\n",
            'styles.css' => ":root{--bg:#f7f9fc;--ink:#1f2937;--brand:#1a73e8;--card:#fff;}*{box-sizing:border-box}body{margin:0;font-family:system-ui,sans-serif;background:linear-gradient(180deg,#fff,#f7f9fc);color:var(--ink)}.hero{padding:5rem 1.5rem 3rem;background:radial-gradient(circle at top left,#e8f0fe,transparent 40%)}.eyebrow{text-transform:uppercase;letter-spacing:.12em;color:#1a73e8;font-size:.75rem}.lede{max-width:45rem}.cta{display:inline-block;background:var(--brand);color:#fff;padding:.8rem 1rem;border-radius:999px;text-decoration:none}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;padding:1.5rem}.card{background:var(--card);border:1px solid #dbe3f0;border-radius:1rem;padding:1rem;box-shadow:0 10px 25px rgba(26,115,232,.08)}\n"
        ];
    }

    if ($type === 'chrome-extension') {
        return [
            'manifest.json' => json_encode([
                'manifest_version' => 3,
                'name' => $projectName,
                'version' => '1.0.0',
                'action' => ['default_popup' => 'popup.html'],
                'permissions' => ['storage', 'activeTab', 'scripting'],
                'background' => ['service_worker' => 'background.js']
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            'popup.html' => "<!doctype html><html><head><meta charset=\"utf-8\"><title>{$safeName}</title><link rel=\"stylesheet\" href=\"popup.css\"></head><body><main><h1>{$safeName}</h1><p>Load unpacked in Chrome extensions.</p><button id=\"inspectBtn\">Inspect Page</button><script src=\"popup.js\"></script></main></body></html>\n",
            'popup.css' => "body{font-family:system-ui,sans-serif;min-width:280px;padding:1rem}button{padding:.6rem .9rem;border:none;border-radius:999px;background:#1a73e8;color:#fff;cursor:pointer}\n",
            'popup.js' => "document.getElementById('inspectBtn').addEventListener('click', async () => { const [tab] = await chrome.tabs.query({active:true,currentWindow:true}); console.log('active tab', tab?.url); });\n",
            'background.js' => "chrome.runtime.onInstalled.addListener(() => { console.log('{$projectName} installed'); });\n",
            'README.md' => "# {$projectName}\n\n1. Open Chrome -> Extensions -> Developer Mode.\n2. Click Load unpacked.\n3. Select this folder.\n"
        ];
    }

    if ($type === 'scraper-builder') {
        return [
            'README.md' => "# {$projectName}\n\nStarter for an AI-assisted scraper platform combining a Chrome extension capture layer and a web dashboard.\n\nPrompts to collect:\n- target domain\n- business goal\n- output schema\n- crawl depth rules\n- pagination hints\n\nThis starter includes the structure and prompts, but you will still define target-specific extraction logic.\n",
            'dashboard/index.html' => "<!doctype html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>{$safeName}</title><link rel=\"stylesheet\" href=\"styles.css\"></head><body><main><h1>{$safeName}</h1><form><label>Target Domain<input placeholder=\"example.com\"></label><label>Goal<textarea placeholder=\"Lead generation, product monitor, local SEO, etc.\"></textarea></label><label>Extraction Prompt<textarea placeholder=\"Describe the fields and workflow.\"></textarea></label><button type=\"button\">Generate Scraper Plan</button></form></main></body></html>\n",
            'dashboard/styles.css' => "body{font-family:system-ui,sans-serif;margin:2rem;background:#f8fbff}form{display:grid;gap:1rem;max-width:720px}input,textarea{width:100%;padding:.75rem;border:1px solid #cdd8ea;border-radius:.75rem}button{padding:.8rem 1rem;background:#1a73e8;color:#fff;border:none;border-radius:999px}\n",
            'extension/manifest.json' => json_encode([
                'manifest_version' => 3,
                'name' => $projectName . ' Capture',
                'version' => '1.0.0',
                'permissions' => ['storage', 'activeTab', 'scripting'],
                'action' => ['default_popup' => 'popup.html']
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            'extension/popup.html' => "<!doctype html><html><body><h1>{$safeName}</h1><p>Capture starter for scraper workflows.</p></body></html>\n",
            'prompts/system-prompt.md' => "Build a scraper for DOMAIN using GOAL, outputting structured JSON and respecting crawl limits.\n"
        ];
    }

    if ($type === 'windows-app') {
        return [
            'README.md' => "# {$projectName}\n\nThis is a Windows desktop starter scaffold for Visual Studio / C#.\n\nRecommended workflow:\n1. Clone or download locally.\n2. Open in Visual Studio on Windows.\n3. Build and test there, or later connect a CI pipeline.\n\nThe station can store and edit these files, but it does not compile Windows apps in-browser.\n",
            station_safe_name($projectName) . '.sln' => "Microsoft Visual Studio Solution File, Format Version 12.00\n# Visual Studio Version 17\n",
            station_safe_name($projectName) . '/App.xaml' => "<Application x:Class=\"{$projectName}.App\" xmlns=\"http://schemas.microsoft.com/winfx/2006/xaml/presentation\" xmlns:x=\"http://schemas.microsoft.com/winfx/2006/xaml\" StartupUri=\"MainWindow.xaml\"></Application>\n",
            station_safe_name($projectName) . '/MainWindow.xaml' => "<Window x:Class=\"{$projectName}.MainWindow\" xmlns=\"http://schemas.microsoft.com/winfx/2006/xaml/presentation\" xmlns:x=\"http://schemas.microsoft.com/winfx/2006/xaml\" Title=\"{$projectName}\" Height=\"450\" Width=\"800\"><Grid><TextBlock Text=\"{$projectName} starter\" VerticalAlignment=\"Center\" HorizontalAlignment=\"Center\" FontSize=\"28\"/></Grid></Window>\n",
            station_safe_name($projectName) . '/MainWindow.xaml.cs' => "using System.Windows;\nnamespace {$projectName} { public partial class MainWindow : Window { public MainWindow() { InitializeComponent(); } } }\n"
        ];
    }

    if ($type === 'php') {
        return [
            'index.php' => "<?php\n\ndeclare(strict_types=1);\n\n?><!doctype html>\n<html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>{$safeName}</title></head><body><h1>{$safeName}</h1><p>PHP starter project is live.</p></body></html>\n"
        ];
    }

    if ($type === 'static-js') {
        return [
            'index.html' => "<!doctype html>\n<html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>{$safeName}</title><link rel=\"stylesheet\" href=\"styles.css\"></head><body><h1>{$safeName}</h1><p>Static JS starter.</p><script src=\"app.js\"></script></body></html>\n",
            'styles.css' => "body { font-family: sans-serif; margin: 2rem; }\n",
            'app.js' => "console.log('{$projectName} ready');\n"
        ];
    }

    if ($type === 'node') {
        return [
            'package.json' => json_encode([
                'name' => station_safe_name($projectName),
                'version' => '1.0.0',
                'private' => true,
                'main' => 'server.js',
                'scripts' => [
                    'start' => 'node server.js'
                ]
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            'server.js' => "const http = require('http');\nconst port = process.env.PORT || 3000;\nhttp.createServer((req, res) => {\n  res.writeHead(200, { 'Content-Type': 'text/plain' });\n  res.end('{$projectName} node starter running\\n');\n}).listen(port, () => {\n  console.log('listening on ' + port);\n});\n"
        ];
    }

    return [
        'index.html' => "<!doctype html>\n<html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>{$safeName}</title><link rel=\"manifest\" href=\"manifest.webmanifest\"><link rel=\"stylesheet\" href=\"styles.css\"></head><body><h1>{$safeName}</h1><p>PWA starter app.</p><script src=\"app.js\"></script></body></html>\n",
        'app.js' => "if ('serviceWorker' in navigator) {\n  navigator.serviceWorker.register('./sw.js').catch(() => {});\n}\n",
        'styles.css' => "body { font-family: sans-serif; margin: 2rem; }\n",
        'manifest.webmanifest' => json_encode([
            'name' => $projectName,
            'short_name' => station_safe_name($projectName),
            'start_url' => './',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#0b7285'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        'sw.js' => "self.addEventListener('install', () => self.skipWaiting());\nself.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));\nself.addEventListener('fetch', () => {});\n"
    ];
}
