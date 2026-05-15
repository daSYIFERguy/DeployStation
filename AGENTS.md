# AGENTS.md

## Cursor Cloud specific instructions

### Project overview

Deploy Station is a pure PHP web application (no frameworks, no build step, no package managers). It serves as a self-hosted project deployment dashboard with file-based JSON storage (no database).

### Running the dev server

```
php -S 0.0.0.0:8080 -t /workspace
```

The app is served under `/secure/station/`. Open `http://localhost:8080/secure/station/` in a browser.

### First-run setup

On a fresh data directory, navigating to the station URL redirects to `setup.php`. Set a station name and root password (min 8 chars). After setup, sign in at `index.php`.

The data directory (`/workspace/secure/.secure-station-data/`) stores config, user profiles, projects metadata, clipboard, and activity logs. Delete it to reset the station to first-run state.

### PHP requirements

PHP 8.1+ with extensions: `session`, `json`, `zip`, `mbstring`, `fileinfo`. Install via:
```
sudo apt-get install -y php php-cli php-json php-zip php-mbstring php-fileinfo php-xml
```

### Linting

There is no dedicated linter config. Run PHP syntax checks with:
```
find /workspace/secure -name '*.php' -exec php -l {} \;
```

### Testing

There are no automated test suites in this codebase. Manual testing through the browser is the primary verification method. Key flows to test:
- First-run setup → login → dashboard
- Project creation (zip upload, folder upload, single file, template)
- Project launch, file viewer, editor
- Clipboard sync (text, file upload, image)
- User management (add users, reset passwords)

### Key directories

| Path | Purpose |
|------|---------|
| `secure/station/` | Main application PHP files |
| `secure/station/lib/` | Core libraries (bootstrap, auth, projects, templates) |
| `secure/station/assets/` | Static CSS |
| `secure/.secure-station-data/` | Runtime data dir (created automatically) |
| `docs/` | Product direction docs |
