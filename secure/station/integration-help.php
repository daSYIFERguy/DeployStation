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
				<p><strong>OAuth (recommended):</strong> If the station owner configured a GitHub OAuth App under Admin → GitHub, <strong>Sign in with GitHub</strong> and <strong>Connect GitHub</strong> under User Settings use the same authorization: <code>repo</code>, <code>workflow</code> (for <code>.github/workflows/</code>), <code>read:user</code>, <code>read:org</code>, and <code>user:email</code>. Approve every permission so behavior matches a classic PAT. If pushes fail on workflow files, revoke the app under GitHub → Settings → Applications → Authorized OAuth Apps and sign in again.</p>
				<p><strong>Personal access token:</strong> You can still create a <a href="https://github.com/settings/tokens" target="_blank" rel="noreferrer">token</a> with the <code>repo</code> scope and paste it under User Settings. If pushes fail on workflow files, add the <code>workflow</code> scope (classic PAT) or grant Actions write on a fine-grained token. Leave the token field blank when saving to keep an existing stored token.</p>
				<p><strong>Repo list API:</strong> <code>GET github-repos-api.php?pages=4</code> — JSON list of repositories visible to your token (used by the “Create project → GitHub” dialog).</p>
			</article>
			<article id="workspace-api" class="card">
				<h2>Workspace API</h2>
				<p>Session-authenticated JSON endpoints (same login cookie as the dashboard):</p>
				<ul>
					<li><code>GET …&amp;action=browse&amp;dir=</code> — list one directory (folders + files).</li>
					<li><code>GET …&amp;action=file&amp;path=</code> — read a text file.</li>
					<li><code>GET …&amp;action=list</code> — flat recursive file index (legacy).</li>
					<li><code>POST</code> JSON <code>{"action":"save","path":"…","content":"…"}</code> — save file (builder).</li>
					<li><code>POST</code> JSON <code>mkdir</code>, <code>delete</code>, <code>rename</code> — folder/file ops (builder).</li>
					<li><code>POST</code> multipart <code>action=upload&amp;dir=</code> + file field — upload (builder).</li>
				</ul>
				<p>The <strong>Project Viewer</strong> (<code>viewer.php</code>) is a full UI on top of this API, with optional embedded SSH terminal iframe.</p>
			</article>
		</section>
		<section class="grid-two" style="margin-top: 20px;">
			<article id="storage" class="card">
				<h2>MariaDB &amp; configuration storage</h2>
				<p>Deployment Station keeps projects on disk and stores station configuration, user profiles, and project metadata as <strong>JSON files</strong> under its data directory. That keeps installs simple and works well for a single host.</p>
				<p>If you need multi-server coordination, heavy reporting, or concurrent writes from many workers, introducing <strong>MariaDB/MySQL</strong> (or another database) for <em>metadata only</em> can make sense — but it is a larger migration: you would replace the JSON read/write helpers with SQL and run migrations. The on-disk project trees would typically stay as they are.</p>
			</article>
			<article id="ai-ides" class="card">
				<h2>Copilot, Cursor, and the Files page</h2>
				<p><strong>GitHub Copilot</strong> does not ship a public HTTP API that lets a third-party PHP app embed “Copilot chat” inside your own UI with the same guarantees as VS Code or Cursor. Copilot is tied to supported editors and GitHub’s own surfaces.</p>
				<p>For AI-assisted editing, practical options are: open the same repo in <strong>github.dev</strong> or <strong>VS Code Desktop</strong>, use <strong>Cursor</strong> with the repo linked to your GitHub account, or call an external model API from automation you control (not Copilot’s proprietary UI).</p>
				<p>On the <strong>Files / Editor</strong> screens, when a project has GitHub owner/name saved, Station shows links to the repo on GitHub, <strong>github.dev</strong>, and a <strong>Open in Cursor</strong> link using the <code>cursor://vscode-vfs/github/owner/repo</code> pattern. Whether that link opens Cursor depends on your OS registering the Cursor protocol handler.</p>
			</article>
		</section>
	</main>
	<?= station_pwa_register_html() ?>
</body>
</html>
