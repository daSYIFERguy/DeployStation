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

## Important Server Settings

For uploads and zip extraction, ensure PHP config is sufficient:

- `file_uploads = On`
- `upload_max_filesize` large enough for your expected zip/folder payloads
- `post_max_size` >= upload_max_filesize
- `max_file_uploads` increased if large folder uploads
- `max_execution_time` high enough for extraction

## Ownership Tracking

Every deployed project writes metadata with:

- `slug`
- `owner`
- `sourceType`
- `createdAt`

Displayed in dashboard project table.
