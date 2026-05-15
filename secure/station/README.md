# DeployStation (Micro Deployment Station)

Self-hosted control panel for uploading or scaffolding projects, connecting GitHub, and running apps in Docker behind your reverse proxy.

## Features

- **Authentication** — File-based users and roles (owner, admin, builder, viewer); no database required.
- **Projects** — Zip/folder upload, templates, or GitHub import with metadata and access control.
- **Docker** — Compose generation, service add-ons (MySQL, PostgreSQL, Redis, etc.), build/run/logs from the UI.
- **Workspace** — File browser and editor for builders; viewers can use clipboard where enabled.
- **AI** — Optional OpenAI-powered App Explorer and global Assist (requires API key in station settings).
- **Export** — Portable zip bundle for redeploying on another host (see project Settings → Administration).

## Requirements

- PHP 8.x with common extensions (`zip`, `mbstring`, `json`, `curl`)
- Docker Engine 20.10+ and Compose v2 plugin on the host
- Reverse proxy (Nginx, Caddy, or Traefik) for TLS and routing
- Writable data directory (default: `.secure-station-data/` beside the web root, or `STATION_DATA_DIR`)

See [SERVER_REQUIREMENTS.md](../../SERVER_REQUIREMENTS.md) at the repository root for a host checklist.

## Quick start

1. Deploy this directory as `/secure/station/` (or your chosen path).
2. Point the web server at `index.php` for the station URL.
3. Open the station URL in a browser and complete first-run setup (owner account).
4. In **Admin → Docker**, enable container deployment and select available services.
5. Create a project from a template or upload; configure Docker in project settings; build and run.

Detailed walkthrough: [QUICKSTART.md](../../QUICKSTART.md). Docker reference: [DOCKER_GUIDE.md](../../DOCKER_GUIDE.md).

## Data layout

| Path | Purpose |
|------|---------|
| `config.json` | Users, roles, integrations |
| `projects.json` | Project registry |
| `project-settings/` | Per-project env and Docker config |
| `archives/` | Backups and portable exports |
| `activity.log` | Recent actions |

Override the data root:

```bash
export STATION_DATA_DIR=/var/lib/deploystation
```

Protect this directory from direct HTTP access (`.htaccess` or equivalent).

## Templates

Starter templates live in `lib/templates.php` with shared scaffold in `lib/template-scaffold.php` (adds `DEPLOYSTATION.md`, `.env.example`, CI workflow, and Docker deploy hints). Additional stacks are in `lib/templates-extra.php`.

Every new template project includes machine-readable context for AI assistants and redeploy tooling.

## Routing

- Station UI: served by PHP under `/secure/station/`.
- Deployed apps: typically exposed under `/p/{project-slug}/` via generated Nginx snippets (`nginx-project-auth.php`).

Do not block the station path with a static-only `location` that prevents PHP execution.

## Security notes

- Use strong passwords and HTTPS in production.
- Store OpenAI and GitHub tokens only in the protected data directory.
- Rotate credentials used by generated `docker-compose.yml` files.
- Review role assignments; **owner** is required for station-wide settings.

## Documentation

| Document | Description |
|----------|-------------|
| [docs/PRODUCT_STATUS_AND_ROADMAP.md](../../docs/PRODUCT_STATUS_AND_ROADMAP.md) | Spec vs implementation |
| [docs/SANDBOX_VM_PLAN.md](../../docs/SANDBOX_VM_PLAN.md) | Future preview environments |
| [DEPLOYMENT_GUIDE.md](../../DEPLOYMENT_GUIDE.md) | Production deployment |
| [DOCKER_GUIDE.md](../../DOCKER_GUIDE.md) | Container configuration |

## License

See the repository root `LICENSE` if present; otherwise treat as private until a license file is added.
