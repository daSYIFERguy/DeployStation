# DeployStation — Product Status & Roadmap

This document maps the product specification to what exists in the repository today and what is planned next. Percentages are rough engineering estimates, not commitments.

## Vision

DeployStation is a self-hosted deployment control plane: connect GitHub, scaffold Docker-ready projects from templates, build and run containers, and give builders a workspace while viewers get safe read-only access (including clipboard where enabled).

## Status by area

| Area | Status | Notes |
|------|--------|-------|
| Docker build / run / logs | **~85%** | `lib/docker.php`, docker actions, compose generation, admin Docker host view |
| Path routing (`/p/{slug}/`) | **~80%** | Nginx snippets and project URL helpers; host-specific setup still manual |
| Persistent volumes | **~60%** | Compose templates support volumes; not all stacks wired end-to-end |
| Project templates | **~75%** | Core stacks + scaffold (`DEPLOYSTATION.md`, CI, `.env.example`); extra stacks in `templates-extra.php` |
| Template scaffold for AI/deploy | **~90%** | `lib/template-scaffold.php` merged on every template render |
| Chrome extension template | **~85%** | Install splash, `extension/` tree, packaging script, nginx distro layout in `templates-extra.php` |
| Portable project export | **~85%** | `station_export_portable_project()`, `project-export.php`, UI in project settings |
| GitHub OAuth / import | **~55%** | OAuth and repo listing exist; not mandatory on first run for all roles |
| Mandatory GitHub by role | **~25%** | Onboarding hints; enforcement not global |
| Canonical repo / PR workflow in-app | **~10%** | No first-class PR approval UI |
| Workspace vs deployments split | **~40%** | Projects under station data; not separate `workspaces/` + `deployments/` trees |
| Workspace IDE (`viewer.php`) | **~70%** | File tree, editor, AI assist hooks |
| Legacy inline editor removal | **~50%** | `editor.php` may still exist; workspace is preferred path |
| Webhooks / job queue / rollback | **~15%** | Activity log only; no durable job runner |
| Collaborators & in-app review | **~5%** | Role-based access; no multi-user PR gate |
| Clipboard for viewers | **~95%** | FAB + `clipboard.php` |
| Global AI Assist / App Explorer | **~85%** | Needs OpenAI API key and billing on the host |
| Public docs & README hygiene | **~60%** | Ongoing; station README and this doc updated for open source |
| Sandbox VM (isolated preview) | **~5%** | Plan only — see [SANDBOX_VM_PLAN.md](./SANDBOX_VM_PLAN.md) |

## Recently completed (this branch)

- Global AI Assist FAB and App Explorer chat fixes
- UI contrast fixes (entrypoint picker, workspace file browser)
- Owner role preserved when changing passwords in Users admin
- Orphan container remove/prune on Admin Docker
- Template scaffold merge for all new projects
- Additional templates (Go, Django, Astro, Hono, WordPress, Bun, Chrome extension v2)
- Portable export zip with `EXPORT_README.md`

## Near-term roadmap (ordered)

1. **GitHub-first onboarding** — Require GitHub for builders; store remote URL on project metadata by default.
2. **Enforce scaffold on import** — Run `station_template_merge_scaffold()` when syncing from GitHub if files missing.
3. **Deployment records** — Separate deployment history from workspace tree (build id, image tag, rollback target).
4. **Webhook-driven deploy** — GitHub push → queue → `docker compose up --build`.
5. **Collaboration** — Project members table, reviewer role, optional “approve before deploy”.
6. **Docs pass** — Align `QUICKSTART.md`, `DOCKER_GUIDE.md`, `DEPLOYMENT_GUIDE.md` with current UI paths and public tone.
7. **Chrome extension CI** — Optional workflow to build `.zip` artifacts on tag.

## Non-goals (for now)

- Replacing GitHub with built-in git hosting
- Multi-tenant SaaS billing
- Kubernetes orchestration (Docker Compose remains the default)

## How to contribute against the roadmap

Pick an item from **Near-term roadmap**, open an issue describing acceptance criteria, and keep changes scoped to `secure/station/` unless the item is documentation-only.
