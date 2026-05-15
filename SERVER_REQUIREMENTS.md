# Ubuntu/Debian Server Requirements

DeployStation runs as a PHP control panel on the host; application projects are built and run in Docker containers behind your reverse proxy.

## Required host packages

- `git`: clone GitHub repositories into local project folders.
- `curl` and `ca-certificates`: install Docker and fetch release assets.
- Docker Engine, Docker CLI, containerd, Buildx, and Compose plugin.
- Nginx, Caddy, or Traefik as the public reverse proxy. Existing Nginx is fine.
- PHP-FPM and PHP extensions for the current app: `php-fpm`, `php-zip`, `php-mbstring`, `php-json`, `php-curl`.

## Docker packages

Use Docker's official apt repository, then install:

```bash
sudo apt install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
```

Verify:

```bash
sudo systemctl status docker
sudo docker run hello-world
docker compose version
```

## TypeScript/Node apps

If every TypeScript app builds and runs inside its own Docker image, Node.js does not have to be installed on the host.

Install Node.js on the host only when the DeployStation control app itself becomes TypeScript, or when you want to build apps outside Docker:

```bash
node --version
npm --version
```

For production, prefer one of these:

- Build TypeScript apps inside Docker images.
- Build in CI/GitHub Actions and deploy the built image.
- Install Node.js LTS on the host only for the DeployStation webapp/runtime.

## Security notes

- Do not expose database container ports publicly. Databases should stay on Docker networks unless there is a very specific reason.
- Do not give the public PHP/Nginx process direct unrestricted Docker access long term. Use a local worker/agent for Docker jobs.
- If a temporary prototype needs Docker access, put only the worker user in the `docker` group, not every web-facing user.
- Keep public firewall openings to `80/tcp` and `443/tcp`; use Nginx/Caddy/Traefik to route project subdomains or paths.
- Docker can interact with firewall rules. Put explicit rules in the `DOCKER-USER` chain if you need host-level blocking.

## Current PHP prototype extras

For the PHP app as it exists now:

```bash
sudo apt install git unzip php-zip php-mbstring php-curl
```

Make sure PHP allows:

- `proc_open` for Git import.
- Enough `upload_max_filesize` and `post_max_size` for project uploads.
- Write access from the PHP user to the project directory and `.secure-station-data`.
