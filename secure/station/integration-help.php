<?php declare(strict_types=1); require_once __DIR__ . '/lib/auth.php'; station_require_login(); if (!station_can_build(station_current_user())) { header('Location: station.php'); exit; } ?>
<!doctype html>
<html lang="en">
<head>
	<?= station_pwa_head_html('Integration Help', 'Reference notes for premium services and credential setup.') ?>
</head>
<body class="station-body">
	<main class="station-shell">
		<header class="topbar card">
			<div>
				<p class="kicker">Docs</p>
				<h1>Integration Setup Help</h1>
				<p>Reference notes for premium services and credential setup.</p>
			</div>
			<nav class="nav-pills">
				<a href="station.php">Dashboard</a>
				<a href="user-settings.php">User Settings</a>
			</nav>
		</header>
		<section class="grid-two">
			<article id="workspace-api" class="card"><h2>Workspace API</h2><p>Session-authenticated JSON endpoints (same login cookie as the dashboard):</p><ul><li><code>GET project-workspace-api.php?project=SLUG&amp;action=list</code> — file index (paths, sizes, modified times).</li><li><code>GET project-workspace-api.php?project=SLUG&amp;action=file&amp;path=relative/file.php</code> — read text files Station already allows in the web editor.</li><li><code>POST</code> with JSON <code>{"path":"...","content":"..."}</code> (or form fields) — save a file; requires <strong>builder</strong> role or higher.</li></ul><p><strong>Cursor in the browser:</strong> Cursor does not provide an embeddable &quot;Cursor Web&quot; editor inside third-party sites like Deployment Station. For AI-assisted workflows, use <a href="https://cursor.com/docs" target="_blank" rel="noreferrer">Cursor Cloud Agents / the TypeScript SDK</a> against a git remote, or call this API from your own trusted automation while signed in.</p></article>
			<article id="github" class="card"><h2>GitHub</h2><p>Create a personal access token with repo scopes as needed. Store only the token you actually use for Station workflows.</p></article>
			<article id="vscode" class="card"><h2>VS Code</h2><p>Use your preferred VS Code share/sync endpoint, repository URL, or remote workspace notes. This field is intentionally flexible.</p></article>
			<article id="chatgpt" class="card"><h2>ChatGPT</h2><p>Add the API key or workspace identifier you use for your automation workflows. If you only use the web app, keep it disabled.</p></article>
			<article id="codex" class="card"><h2>Codex</h2><p>Add the coding API key or workspace reference used in your build workflows. If not configured yet, leave disabled and the related features stay greyed out.</p></article>
		</section>
	</main>
	<?= station_pwa_register_html() ?>
</body>
</html>
