# Micro Deployment Station

This folder provides a secured micro deployment and devops center for your `/secure/station/` path.

## Included Features

- First-run setup that creates `root` owner account and password hash (no database).
- Login-protected dashboard.
- Upload modes:
  - Zip upload + auto extract to new project folder.
  - Folder upload (webkitdirectory browser mode).
  - Single file upload into a new project folder.
  - Template project generation for: PHP, static JS, Node, PWA.
- Docker launch preparation for uploaded or generated web apps.
- Project metadata with ownership (`owner` shown in dashboard table).
- Project visibility controls (`private` / `public`).
- Viewer page to browse and preview project files.
- Editor page for text/code file edits.
- Recent activity log in dashboard.
- Owner-only user admin:
  - Add admin users.
  - Reset user passwords.
  - View last login time per user.

## Storage and Security

- Config and metadata are stored in a hidden file-based data directory:
  - Default: `../.secure-station-data` (sibling to `station/`).
- Data directory is created automatically on first run with:
  - `.htaccess` deny file.
  - fallback `index.html`.

You can override data path with environment variable:

- `STATION_DATA_DIR=/absolute/private/path`

This is recommended if you can place it fully outside web root.

## First Run

1. Deploy this folder as `/secure/station/`.
2. Add `/secure/index.php` redirect to `/secure/station/` (see `root-index.php.example`).
3. Open `/secure/station/`.
4. If not initialized, it redirects to `setup.php`.
5. Set station name and root password.
6. Login at `index.php`.

## No Database Requirement

- Station itself does not use MariaDB.
- Uploaded projects can still contain and use MariaDB apps independently.

## Docker Project Launches

Station can prepare and launch projects with Docker when the web server user can access a working Docker CLI and daemon.

- New uploads/templates include a "Prepare Docker launch" option.
- Projects with an existing `Dockerfile` are detected and Docker launch is enabled automatically.
- Project settings include Docker runtime, image/container names, host/container ports, launch path, and generated Dockerfile controls.
- Opening `launch.php?project=...` starts or opens the Docker app when Docker launch is enabled; otherwise it falls back to Station's direct project serving.
- `.env.local` generated from project environment variables is passed to `docker run --env-file`.

If Docker is unavailable to PHP, the launch center shows the equivalent CLI commands so the project can still be built manually.

## Important Server Settings

For uploads and zip extraction, ensure PHP config is sufficient:

- `file_uploads = On`
- `upload_max_filesize` large enough for your expected zip/folder payloads
- `post_max_size` >= upload_max_filesize
- `max_file_uploads` increased if large folder uploads
- `max_execution_time` high enough for extraction

## Nginx Production Routing

If you run production on Nginx, direct project URLs like `/secure/my-project/` must be rewritten to Station's access-control handler.

Use the owner-only helper at `nginx-project-auth.php` to generate the exact `location` block for your mount path.

Do not use a `location ^~ /secure/station/` block for Station itself. That prevents Nginx's PHP regex location from handling files like `setup.php` and `index.php`, which makes browsers download PHP files instead of executing them.

For the project rewrite itself, use named captures in the Nginx `location` block and forward those variables into `project-serve.php`. Using `$1` and `$2` inside a separate `rewrite` can fail because those captures are not reliably inherited from the `location` regex.

Without that rewrite, Nginx will serve project folders directly from disk and bypass Station access control entirely.

## Ownership Tracking

Every deployed project writes metadata with:

- `slug`
- `owner`
- `sourceType`
- `createdAt`

Displayed in dashboard project table.
