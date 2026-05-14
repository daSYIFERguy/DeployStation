<?php declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
station_require_login();
if (!station_can_build(station_current_user())) {
    header('Location: station.php');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
	<?= station_pwa_head_html('GitHub & API Help', 'GitHub tokens and workspace API reference for Deployment Station.') ?>
</head>
<body class="station-body">
	<main class="station-shell">
		<header class="topbar card">
			<div>
				<p class="kicker">Docs</p>
				<h1>GitHub &amp; API help</h1>
				<p>GitHub is the supported code connection. Use the workspace API from trusted automation while signed in.</p>
			</div>
			<nav class="nav-pills">
				<a href="station.php">Dashboard</a>
				<a href="user-settings.php">User settings</a>
			</nav>
		</header>
		<section class="grid-two">
			<article id="github" class="card">
				<h2>GitHub</h2>
				<p>Create a <a href="https://github.com/settings/tokens" target="_blank" rel="noreferrer">personal access token</a> with the repository scopes you need. Paste it under User Settings → GitHub. Store only the token you use for this server.</p>
			</article>
			<article id="workspace-api" class="card">
				<h2>Workspace API</h2>
				<p>Session-authenticated JSON endpoints (same login cookie as the dashboard):</p>
				<ul>
					<li><code>GET project-workspace-api.php?project=SLUG&amp;action=list</code> — file index (paths, sizes, modified times).</li>
					<li><code>GET project-workspace-api.php?project=SLUG&amp;action=file&amp;path=relative/file.php</code> — read text files Station already allows in the web editor.</li>
					<li><code>POST</code> with JSON <code>{"path":"...","content":"..."}</code> (or form fields) — save a file; requires <strong>builder</strong> role or higher.</li>
				</ul>
			</article>
		</section>
	</main>
	<?= station_pwa_register_html() ?>
</body>
</html>
