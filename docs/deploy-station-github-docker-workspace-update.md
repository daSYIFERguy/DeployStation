# Deploy Station GitHub, Docker, Workspace, Collaboration, and Clipboard Update

## Product Direction

Deploy Station should become a GitHub-backed project deployment host with Docker-based runtimes and Station-managed access controls.

Core rules:

- GitHub setup is mandatory for owner/admin/builder users.
- Viewer users are exempt from GitHub setup.
- New projects should default to private GitHub repositories.
- Deploy Station keeps the most recent local working copy for deploy/runtime.
- Docker is the primary runtime for cloned/deployed apps.
- Project visibility remains controlled by Deploy Station: private, public, admin/builder, or viewer-allowed.
- Runtime app data must not be stored in GitHub and must not be overwritten by code updates.
- Remove the inline source editor from the primary workflow and replace it with Cursor/GitHub links.
- Clipboard and transfer tools remain available to viewers, builders, admins, and owners.

## GitHub Storage Note

GitHub is appropriate as the source-of-truth storage for private code repositories, but it should not be treated as unlimited runtime storage.

Use GitHub for:

- source code
- templates
- Dockerfiles
- workflow files
- documentation
- example environment files

Do not use GitHub for:

- app uploads
- production databases
- generated assets
- logs
- backups
- cache
- clipboard files
- Docker volumes

Large binaries should use Git LFS or external object storage if needed.

## 1. Mandatory GitHub Setup

### First-run setup

During owner/root setup, require GitHub configuration before Station is usable.

Required setup:

- GitHub account connection method.
- Preferred implementation: GitHub App or OAuth App.
- Fallback for simple installs: PAT/token stored outside web root.
- Default GitHub owner/org.
- Default repository visibility: private.
- Default branch: main.
- Webhook secret.
- Station public URL for webhook callbacks.

Store secrets and tokens in the Station data directory, not in public project folders.

Example system config:

```json
{
  "github": {
    "enabled": true,
    "authMode": "github_app",
    "defaultOwner": "username-or-org",
    "defaultVisibility": "private",
    "defaultBranch": "main",
    "webhookSecretConfigured": true
  }
}
```

### User setup

Each non-viewer Station user should connect their own GitHub account.

Example user config:

```json
{
  "username": "alice",
  "github": {
    "connected": true,
    "login": "alice-gh",
    "defaultOwner": "alice-gh",
    "canCreatePrivateRepos": true
  }
}
```

If GitHub is not connected, non-viewer users cannot create, sync, edit, or deploy projects.

## 2. GitHub Requirement By Role

| Role | GitHub Required? | Reason |
|---|---:|---|
| Owner/root | Yes | Manages Station, default GitHub config, repo creation, integrations |
| Admin/builder | Yes | Creates projects, syncs code, deploys, pushes/pulls |
| Viewer | No | Can only view/run allowed projects and use clipboard tools |

Viewer users should not be blocked by GitHub setup because they do not create, clone, push, pull, fork, or deploy code.

### Login behavior

If a non-viewer user has no GitHub account connected, show:

```text
GitHub setup required before you can create, edit, sync, or deploy projects.
```

If a viewer has no GitHub account connected, no warning is required.

## 3. Project Source Model

Every project should have source metadata.

```json
{
  "slug": "my-app",
  "owner": "alice",
  "source": {
    "provider": "github",
    "state": "github-connected",
    "repoUrl": "git@github.com:alice-gh/my-app.git",
    "repoOwner": "alice-gh",
    "repoName": "my-app",
    "branch": "main",
    "workspacePath": "/station-data/workspaces/my-app",
    "lastLocalCommit": "",
    "lastRemoteCommit": "",
    "lastDeployedCommit": ""
  }
}
```

Supported source states:

- `github-connected`
- `github-pending-push`
- `local-uncommitted-changes`
- `remote-update-available`
- `conflict-needs-review`
- `legacy-local`

New projects should not start as local-only unless GitHub setup fails and an owner/admin explicitly enables legacy/local mode.

## 4. Workspace And Deployment Layout

Use separate locations for source, runtime, and data.

```text
Station data
  workspaces/
    project-a/       Git working tree/source
  deployments/
    project-a/       deployment metadata/logs
  clipboard/
    images/
    files/
  backups/
    volumes/

Docker
  images            built from workspaces
  containers        running apps
  volumes           persistent app data
```

Do not edit or store app data inside the public/live source directory.

## 5. New Project Flow

### New project from template

```text
Create project
  -> create private GitHub repo
  -> generate template files locally
  -> git init / commit
  -> push to GitHub
  -> keep workspace as Git working tree
  -> build Docker image
  -> run container
  -> configure Station launch route
```

Generated files should include:

- `README.md`
- `.gitignore`
- `.env.example`
- `Dockerfile` when the template supports Docker
- optional `.github/workflows/deploy.yml`

Do not commit:

- `.env`
- `.env.local`
- databases
- uploads
- cache
- runtime data
- logs
- backups
- clipboard files

## 6. Existing Project / Import Flow

Users can paste a GitHub repo URL.

```text
Connect repo
  -> verify access
  -> clone branch into Station workspace
  -> detect runtime
  -> build Docker image
  -> run container
  -> configure launch route
```

Runtime detection should check for:

- `Dockerfile`
- `docker-compose.yml`
- `compose.yml`
- `package.json`
- `composer.json`
- static HTML/PHP files

If no Dockerfile exists, Station may generate a basic Docker runtime based on project type/template.

## 7. Save Another User-Owned Project To Current User GitHub

Add an option:

```text
Save/copy this project to my GitHub
```

Use cases:

- Project was originally owned by another Station user.
- Project came from a shared/local legacy copy.
- Current user wants their own private repo copy.

Flow:

```text
Current user clicks "Save to my GitHub"
  -> Station grabs most recent local workspace state
  -> creates new private repo under current user's GitHub account
  -> commits current files
  -> pushes to new repo
  -> creates copied project owned by current user
```

Safer default: create a new copied project owned by the current user instead of switching the original project source. Avoid overwriting the original owner's repo.

## 8. Shared Project Collaboration Model

Use one canonical project repo plus optional personal forks/copies.

```text
Canonical project repo
  owner/project
        ^
        | pull requests / merge
        |
User A private repo          User B private repo
alice/project-copy           bob/project-copy
```

Deploy Station should deploy only from the canonical project repo by default.

### Project source

```json
{
  "project": "crm-app",
  "canonicalRepo": "company/crm-app",
  "deployBranch": "main"
}
```

### User working repo

```json
{
  "user": "alice",
  "personalRepo": "alice/crm-app",
  "baseRepo": "company/crm-app",
  "branch": "alice/work"
}
```

Changes come back through PRs:

```text
Alice pushes to alice/crm-app
  -> opens PR to company/crm-app

Bob pushes to bob/crm-app
  -> opens PR to company/crm-app

Canonical repo merges approved changes
  -> Deploy Station detects update
  -> owner/maintainer deploys latest
```

If true GitHub private forks are awkward for the install/account type, use private copies with an upstream remote:

```bash
origin   = alice/crm-app-copy
upstream = company/crm-app
```

Station buttons for personal repos:

- Sync my repo from project
- Push my changes
- Create PR
- Resolve conflict in Cursor
- Optional preview deploy from my branch

Station should not auto-resolve code conflicts. If conflicts occur, show:

```text
Your repo has conflicts with the project source.
Open in Cursor to resolve.
```

## 9. PR Approval And Co-author Permissions

A shared project has one canonical GitHub repo. All users may work in their own branch/fork/copy, but changes only reach the live deploy source after approval.

Default approval authority:

- Project owner
- Station root/owner
- Explicit project co-authors/maintainers

### Project-level collaborators

Project collaborators are separate from global Station roles.

```json
{
  "project": "my-app",
  "owner": "alice",
  "collaborators": [
    {
      "username": "bob",
      "role": "maintainer"
    },
    {
      "username": "carol",
      "role": "contributor"
    },
    {
      "username": "dave",
      "role": "viewer"
    }
  ]
}
```

### Project roles

| Project Role | Can Edit | Can Submit PR | Can Approve PR | Can Deploy | Can Manage Collaborators |
|---|---:|---:|---:|---:|---:|
| Owner | Yes | Yes | Yes | Yes | Yes |
| Maintainer/co-author | Yes | Yes | Yes | Optional Yes | Optional Yes |
| Contributor | Yes | Yes | No | No | No |
| Viewer | No | No | No | No | No |

Co-authors/maintainers can review and approve PRs without automatically gaining full Station admin powers.

Recommended GitHub branch rules for canonical `main`:

- Require pull request before merging.
- Require approval from CODEOWNERS or project maintainers.
- Optional: require status checks.
- Disallow direct pushes except owner/admin emergency override.

Approval metadata:

```json
{
  "approval": {
    "enabled": true,
    "requiredApprovals": 1,
    "approverRoles": ["owner", "maintainer"],
    "allowSelfApproval": false
  }
}
```

Generate CODEOWNERS for protected projects:

```text
* @owner-github @maintainer-github
```

## 10. Git Sync Features

Project page should show Git state and actions.

Actions:

- Pull latest from GitHub
- Push local changes to GitHub
- Commit local changes
- View GitHub repo
- Open in Cursor
- Check for updates
- Deploy latest
- Save/copy to my GitHub
- Create PR
- Sync my repo from project

Update detection:

```bash
git fetch origin main
git rev-parse HEAD
git rev-parse origin/main
```

If remote differs, show:

```text
New version available
[View GitHub] [Pull latest] [Deploy latest]
```

If local has uncommitted changes, do not overwrite silently.

## 11. Remove Inline Source Editor

Remove or disable Station's inline source editor from navigation.

Replace editor links with:

- Open in Cursor
- Open GitHub repo
- Open GitHub file browser

Existing `editor.php` should either be removed from navigation or changed to a notice:

```text
Editing is now handled through Cursor/GitHub.
[Open in Cursor] [Open GitHub Repo]
```

Rationale:

- Cursor and Station share the GitHub codebase.
- Avoid divergent edits outside Git.
- Keep Deploy Station focused on build, deploy, health, access, runtime, and transfer tools.

## 12. Docker Runtime

Each deployable project should run in Docker.

Runtime metadata:

```json
{
  "runtime": {
    "type": "docker",
    "image": "station/my-app:latest",
    "containerName": "station-my-app",
    "network": "station-projects",
    "internalPort": 3000,
    "healthPath": "/",
    "status": "running"
  }
}
```

Required actions:

- Build image
- Rebuild image
- Start container
- Stop container
- Restart container
- View logs
- Inspect status
- Deploy latest commit
- Roll back to previous image/commit

Containers should run on an internal Docker network only. Do not expose project containers directly to the public internet.

## 13. Docker Routing / Launch URLs

Existing private/public project visibility must work with Docker apps.

Request flow:

```text
Browser
  -> Deploy Station route/auth layer
  -> project launch router/proxy
  -> Docker container internal port
```

Access modes:

- private: Station login required
- public: no login required
- admin/builder: privileged users only
- viewer-allowed: assigned viewers can launch/run

Needed component:

```text
project-proxy.php or nginx/caddy/traefik dynamic routing integration
```

Routes should map:

```text
/secure/{project}/
  -> container station-{project}:internalPort
```

or:

```text
/projects/{project}/
  -> container
```

The router must preserve:

- path
- query string
- request method
- safe headers
- websocket support if reverse proxy layer supports it

## 14. Persistent App Data

Do not lose saved app data on updates.

Separate:

```text
source code = GitHub/workspace
runtime image = Docker build
app data = Docker volumes / Station data dir
```

Project volume metadata:

```json
{
  "data": {
    "volumes": [
      {
        "name": "station-my-app-data",
        "mountPath": "/app/data"
      },
      {
        "name": "station-my-app-uploads",
        "mountPath": "/app/uploads"
      }
    ]
  }
}
```

On deploy/update:

- rebuild image
- reuse same volumes
- reuse same environment
- never delete volumes unless user explicitly chooses destructive delete

Add:

- Backup volume
- Restore volume
- Data size display
- Last backup timestamp

## 15. Safe Deployment Flow

Deploy latest should work like:

```text
fetch latest
  -> check local dirty state
  -> build new Docker image with commit tag
  -> start replacement container
  -> health check
  -> if healthy: promote new container
  -> if failed: keep old container running
```

Track deployment history:

```json
{
  "deployments": [
    {
      "commit": "abc123",
      "image": "station/my-app:abc123",
      "status": "active",
      "deployedAt": "..."
    }
  ]
}
```

Rollback should restart a previous known-good image with the same data volumes.

## 16. GitHub Webhooks

Add webhook endpoint:

```text
/secure/station/github-webhook.php
```

Webhook should:

- verify signature
- identify repo/branch
- match project
- record remote update
- optionally auto-pull/deploy if enabled

Default behavior:

- auto-deploy off
- show "new version available"

Per-project setting:

```json
{
  "github": {
    "autoDeploy": false,
    "autoPull": false
  }
}
```

## 17. Health And System Configuration

Add a Station health page for host readiness.

Checks:

- Docker installed
- Docker daemon reachable
- Station user has Docker permissions or safe helper service exists
- Git installed
- GitHub auth configured
- GitHub API reachable
- Webhook public URL configured
- HTTPS status
- Disk free space
- Memory
- CPU/load
- PHP required extensions
- Writable Station data dir
- Writable workspace dir
- Writable logs dir
- Reverse proxy config valid
- Project Docker network exists
- Background worker/cron running if used

Show status:

```text
OK / Warning / Error
```

Admin repair buttons where safe:

- create Docker network
- recheck GitHub auth
- rotate webhook secret
- clear stale locks
- prune unused images with confirmation

## 18. Background Jobs And Locks

Long-running operations should not block web requests.

Operations needing job handling:

- clone repo
- pull latest
- push changes
- docker build
- deploy
- backup volume
- restore volume
- health check sweep

Job record:

```json
{
  "id": "...",
  "project": "my-app",
  "type": "docker_build",
  "status": "running",
  "startedAt": "...",
  "finishedAt": "",
  "logFile": "..."
}
```

Prevent concurrent destructive operations with per-project locks.

## 19. Viewer Permissions

Viewers may:

- See assigned/allowed projects.
- Launch projects if access rules allow.
- Use public/private run links after Station authentication.
- View basic project info if permitted.
- Use clipboard and transfer tools.
- Upload photos/files to clipboard.
- Copy clipboard items to device clipboard.
- Download clipboard images/files.

Viewers may not:

- Create projects.
- Connect GitHub repos.
- Clone repos.
- Push/pull code.
- Deploy/rebuild containers.
- View secrets.
- Edit source files.
- Open Cursor/GitHub edit links unless explicitly granted a higher role.
- Access Docker logs unless explicitly granted.
- Manage project settings.

Viewer-visible navigation:

- Dashboard
- Allowed Projects
- Launch
- Clipboard

Hide:

- GitHub source controls
- Docker controls
- deploy controls
- source settings
- secrets

## 20. Clipboard And Transfer Tools

Viewer users should have access to the Station clipboard because they may need to move text, URLs, notes, screenshots, images, and small files between Deploy Station, running projects, Cursor/IDE, and local devices.

Clipboard access:

| Role | Clipboard Access | Notes |
|---|---:|---|
| Owner/root | Yes | Full clipboard, file/image save, admin cleanup |
| Admin/builder | Yes | Full clipboard for development/deploy workflows |
| Viewer | Yes | Clipboard + photo/file transfer only |
| Public anonymous user | No by default | Optional future share mode only |

### Shared web clipboard

Supported item types:

- plain text
- URLs
- markdown snippets
- code snippets
- JSON/config snippets
- images/photos
- small files
- screenshots

Clipboard item:

```json
{
  "id": "clip_123",
  "type": "text|url|image|file|code",
  "title": "API response",
  "contentPath": "clipboard/clip_123.txt",
  "mimeType": "text/plain",
  "createdBy": "alice",
  "createdAt": "...",
  "project": "my-app",
  "visibility": "private"
}
```

Clipboard scope:

- personal clipboard
- project clipboard
- station-wide clipboard, admin only

Default: viewers see their personal clipboard and clipboard items attached to projects they can access.

### Copy web clipboard to device clipboard

Add one-click buttons:

```text
Copy to Device Clipboard
Paste from Device
```

For text/code/URLs, use the browser Clipboard API:

```js
navigator.clipboard.writeText(text)
```

Browser requirements:

- Clipboard writes usually require HTTPS.
- Clipboard writes must be triggered by a user action/click.
- Some browsers may ask for permission.
- Fallback: show text in a textarea with "Select All".

For image copying, support where possible:

```js
navigator.clipboard.write([
  new ClipboardItem({ "image/png": blob })
])
```

Fallbacks:

- Download image
- Open image in new tab
- Long-press/save on mobile
- Copy image URL

### Save photos/images

Add image upload/save support to clipboard.

Sources:

- file picker
- drag/drop
- paste from device clipboard
- mobile camera capture
- screenshot upload

UI buttons:

```text
Upload Photo
Take Photo
Paste Image
Save Image
Copy Image
Download Image
```

Mobile input example:

```html
<input type="file" accept="image/*" capture="environment">
```

Store clipboard files in Station data storage, not project repos:

```text
.station-data/clipboard/images/
.station-data/clipboard/files/
```

Do not commit clipboard images/files to GitHub.

### Browser paste support

Add paste listener for clipboard panel:

```js
document.addEventListener("paste", async (event) => {
  // handle text, images, files
});
```

Supported paste actions:

- paste text into web clipboard
- paste screenshot/image into web clipboard
- paste copied file if browser supports it

### Clipboard security

Clipboard can contain sensitive information, so add safeguards:

- Require login for private clipboard.
- Viewer clipboard access only within allowed projects.
- Do not expose clipboard items publicly.
- Store uploads outside public web root.
- Serve clipboard files through authenticated download endpoint.
- Add delete button.
- Add optional auto-expiration.
- Log clipboard uploads/downloads if audit logging exists.
- Limit max file size.
- Restrict dangerous file types or force download.

Recommended limits:

```text
Text item max: 1 MB
Image/file item max: configurable, default 25 MB
Auto-expire: optional, default off for project clipboard
```

## 21. UI Changes

### Dashboard

Show per project:

- owner
- GitHub repo
- branch
- local changes status
- update available badge
- container status
- visibility
- last deployed commit
- launch button
- deploy button for authorized users

### Project settings

Sections:

1. GitHub Source
   - repo URL
   - branch
   - owner
   - current commit
   - remote commit
   - connect/copy/push/pull buttons

2. Runtime
   - Dockerfile/compose detection
   - internal port
   - health check path
   - build/restart/log buttons

3. Data
   - volumes
   - backup/restore
   - data paths

4. Access
   - private/public/admin/viewer assignment

5. Cursor/GitHub
   - Open in Cursor
   - Open GitHub repo

6. Collaborators
   - add Station users
   - choose project role
   - optionally sync permissions to GitHub

### Pull requests panel

Show PRs for canonical repo:

- PR title
- author
- branch
- checks status
- approval status
- changed files summary
- Open PR on GitHub
- Approve PR, for owner/maintainers
- Merge PR, for owner/maintainers
- Deploy after merge, for authorized deployers

Contributors see their PRs and statuses. Viewers see none.

## 22. Backend Modules To Add

Suggested files:

```text
lib/github.php
lib/git.php
lib/docker.php
lib/deployments.php
lib/jobs.php
lib/health.php
lib/project-runtime.php
github-webhook.php
project-sync.php
project-deploy.php
project-logs.php
project-health.php
system-health.php
project-proxy.php
project-collaborators.php
project-pull-requests.php
clipboard.php
clipboard-upload.php
clipboard-download.php
```

Extend existing project metadata functions instead of replacing everything at once.

## 23. Security Requirements

- Store tokens/secrets outside web root.
- Never commit `.env`, secrets, uploaded data, app data, or clipboard files.
- Validate repo URLs.
- Verify webhook signatures.
- Escape all shell args.
- Prefer allowlisted command wrappers over arbitrary command fields.
- Docker containers should be isolated on a private network.
- Public/private launch must go through Station access controls.
- Do not expose the Docker socket to arbitrary PHP code.
- Restrict who can build, deploy, stop, and restart containers.
- Log all deploy, Git, PR, and sensitive clipboard operations.
- Enforce project-level permissions separately from global Station roles.

## 24. Migration For Existing Projects

For existing local projects:

- mark source as `legacy-local`
- show setup banner:
  - Save this project to GitHub
  - Connect existing repo
  - Keep local legacy mode, owner/admin only
- allow owner/admin to create private GitHub repo from current local files
- preserve current visibility settings
- preserve existing project folders until Docker migration succeeds
- preserve existing clipboard behavior and extend it for viewer access

## 25. Recommended Implementation Order

1. Mandatory GitHub setup for owner/admin/builder users, with viewer exemption.
2. Git-backed project metadata and private repo creation.
3. Replace inline editor links with Cursor/GitHub links.
4. Clone/pull/push project actions.
5. Canonical repo, personal repo/copy, and PR approval model.
6. Project collaborators/co-authors with approval permissions.
7. Docker build/run/start/stop/logs.
8. Docker launch routing with private/public/viewer access.
9. Persistent volumes and backup/restore.
10. Health/system configuration page.
11. Webhooks and update-available badges.
12. Safer deploys with health checks and rollback.
13. Clipboard upgrades for viewers, photos, paste/upload, and copy-to-device.

