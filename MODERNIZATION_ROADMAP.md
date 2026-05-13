# DeployStation Modernization Roadmap

## Recommendation

Move the product toward a TypeScript web app, with an optional small Go host agent later if Docker host control needs a tighter single-binary boundary.

TypeScript is the better first target because DeployStation is mostly product UI, real-time project state, clipboard sync, GitHub/OpenAI integrations, and Docker orchestration workflows. Those all benefit from shared types between browser and server, mature web libraries, and a faster UI iteration loop. Go is still a good fit for a separate local agent that owns privileged host actions, but it does not need to be the main app on day one.

## Target Shape

- Web app: TypeScript, React, server-rendered or Vite-powered admin UI.
- API: TypeScript Fastify/Nest style service with typed request/response schemas.
- Job worker: queue-backed deployment worker for uploads, builds, GitHub import, Docker image build, and container lifecycle tasks.
- Host boundary: never expose the Docker socket directly to browser-facing routes. Browser requests create audited jobs; a worker/agent runs the Docker operation.
- Realtime: WebSocket or SSE for build logs, deployment status, project file updates, and shared clipboard sync.
- Storage: start with SQLite/Postgres for users/projects/jobs/secrets metadata; store project files on disk or object storage.
- Reverse proxy: Caddy/Traefik/Nginx maps generated project URLs to running containers.

## Core Product Modules

- Auth and roles: owner, admin, builder, viewer.
- Projects: upload zip/folder, import GitHub repo, create from template, inspect files.
- Editor: browser editor backed by safe file APIs and Git operations.
- Deployments: Dockerfile detection, generated Dockerfile, build image, run container, stream logs, rollback/stop/restart.
- Dependencies: one primary app container per project, optional services by compose profile.
- GitHub: connect repo, clone/import, commit, branch, open PR.
- OpenAI/Codex: code-assist jobs that run against a project workspace and produce patches, commits, or PRs.
- Clipboard: shared text/files per user with device sync and history.
- Audit: all privileged changes append to a tamper-resistant event log.

## Migration Path

1. Stabilize the PHP prototype enough to keep using it: shared navigation, admin settings, Docker config path, and docs.
2. Define API contracts in TypeScript first: project, user, deployment, clipboard, integration, job, audit event.
3. Build a TypeScript shell beside the PHP app and proxy only one module at a time.
4. Move clipboard and deployment jobs first, because they need realtime behavior and typed state.
5. Move project upload/import/build next.
6. Move admin/user settings last after behavior is clear.
7. Retire PHP once the TypeScript app owns auth, project metadata, and deployment jobs.

## Security Rules

- Do not let arbitrary web requests run shell commands directly.
- Do not mount `/var/run/docker.sock` into the public web process.
- Put every deployment operation behind a job queue, role check, project ownership check, and audit event.
- Give every project container CPU, memory, network, and disk limits.
- Keep secrets out of generated Compose files whenever possible; inject from server-managed env files or Docker secrets.
- Treat uploaded projects as untrusted code until built and run in a constrained environment.
