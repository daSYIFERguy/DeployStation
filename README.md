# DeployStation

Self-hosted deployment control plane: scaffold Docker-ready projects, connect GitHub, build and run containers, and manage access from a PHP dashboard.

## Repository layout

| Path | Description |
|------|-------------|
| `secure/station/` | DeployStation web application |
| `docs/` | Product roadmap and future plans |
| `QUICKSTART.md` | First-time setup |
| `DOCKER_GUIDE.md` | Docker integration reference |
| `SERVER_REQUIREMENTS.md` | Host packages and sizing |

## Get started

1. Read [SERVER_REQUIREMENTS.md](./SERVER_REQUIREMENTS.md).
2. Deploy [secure/station/](./secure/station/) behind PHP-FPM and a reverse proxy.
3. Follow [QUICKSTART.md](./QUICKSTART.md).

Station-specific details: [secure/station/README.md](./secure/station/README.md).

## Status

See [docs/PRODUCT_STATUS_AND_ROADMAP.md](./docs/PRODUCT_STATUS_AND_ROADMAP.md) for what is implemented versus planned (GitHub-first onboarding, webhooks, sandbox previews, collaboration).
