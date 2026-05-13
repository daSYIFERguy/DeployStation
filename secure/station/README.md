# Micro Deployment Station

This folder provides a secured micro deployment and devops center for your `/secure/station/` path.

## Key Features

### Core Deployment
- **First-run setup**: Creates `root` owner account with secure password hash (no database required).
- **Login-protected dashboard**: Secure access with user management.
- **Multiple upload modes**:
  - Zip upload + auto-extract
  - Folder upload (webkitdirectory)
  - Single file upload
  - Template project generation (PHP, Node.js, static JS, PWA)
- **Project metadata**: Ownership tracking, visibility controls, source type tracking.
- **File management**: Web-based file viewer and editor.
- **Activity logging**: Recent activity tracking with configurable retention.
- **User admin panel**: Add users, reset passwords, view login history.

### Docker & Container Support
- **Docker container deployment**: Deploy projects inside isolated Docker containers.
- **Service orchestration**: Automatic Docker Compose generation for multiple services.
- **Multi-database support**:
  - MySQL 8.0 / MariaDB
  - PostgreSQL 15
  - MongoDB 7.0
  - Redis (caching/sessions)
  - Elasticsearch (search/analytics)
  - RabbitMQ (message queue)
- **Credential management**: Encrypted storage of database passwords and API keys.
- **Environment configuration**: Auto-generated environment variables for database connection strings.
- **Health checks**: Built-in health monitoring for critical services.
- **Volume management**: Persistent data storage for databases and caches.

### Branding & Customization
- **Modern tabbed settings interface**: Organized admin panel with icon-based navigation.
- **Custom branding**: Station heading, subheading, and theme color.
- **App icons**: Upload custom favicon and PWA icons (192x192, 512x512, maskable).
- **Responsive design**: Works perfectly on desktop, tablet, and mobile.

### Integrations
- **GitHub integration**: Import projects from GitHub repositories.
- **VS Code Remote**: Open projects directly in VS Code.
- **AI features**: ChatGPT and GitHub Codex integration support.

## Architecture

### File-Based Storage (No Database)
- Config stored in `/.secure-station-data/config.json`
- Project metadata in `/.secure-station-data/projects.json`
- Activity log in `/.secure-station-data/activity.log`
- User profiles in `/.secure-station-data/user-profiles/`
- Project settings in `/.secure-station-data/project-settings/`
- Encrypted credentials in `/.secure-station-data/project-credentials/`

### Secure Directory
The `.secure-station-data` directory receives:
- `.htaccess` deny rule (Apache)
- fallback `index.html` (defense in depth)

Can be overridden with environment variable:
```bash
STATION_DATA_DIR=/absolute/private/path
```

## Docker Deployment Requirements

### Host Requirements
- Docker Engine 20.10+ installed
- Docker Compose 1.29+
- Sufficient disk space for database volumes
- Network access for service communication

### Port Requirements (if exposing services)
- 3306: MySQL
- 5432: PostgreSQL
- 6379: Redis
- 27017: MongoDB
- 9200: Elasticsearch
- 5672: RabbitMQ

Most services communicate via Docker's internal network (`app-network`) and don't require exposed ports.

### Deployment Flow

1. **User uploads/creates project**
2. **Admin enables Docker in settings** (`/secure/station/admin-settings.php?tab=docker`)
3. **User configures services** via Docker configuration page
4. **Station generates `docker-compose.yml`** in project directory
5. **User (or admin) runs**: `docker-compose up -d` in the project directory
6. **Services start** with encrypted credentials auto-populated as environment variables

## First Run Setup

1. Deploy this folder as `/secure/station/`
2. Add `/secure/index.php` redirect (see `root-index.php.example`)
3. Open `/secure/station/`
4. Creates account and sets root password
5. Login and access dashboard

## Configuration

### Admin Settings Page
Navigate to **Admin Settings** > **Docker** to configure:
- Enable/disable Docker container deployment
- Select available services (MySQL, PostgreSQL, Redis, etc.)
- Set default database for new projects
- Configure Docker Compose version

### Project Docker Configuration
Navigate to any project settings and select:
- Services needed (database, cache, message queue)
- Database credentials (auto-encrypted)
- Service versions
- Environment variables

## Server Settings

For uploads and extraction, ensure PHP configuration:
```ini
file_uploads = On
upload_max_filesize = 512M
post_max_size = 512M
max_file_uploads = 50
max_execution_time = 300
```

## Web Server Configuration

### Apache (Recommended for simplicity)
Station works with Apache out of the box. Projects can be served directly from `/secure/project-name/`.

### Nginx
Use the helper at `nginx-project-auth.php` to generate routing blocks:
```php
require_once 'nginx-project-auth.php';
echo station_nginx_project_route_snippet();
```

Include the output in your Nginx configuration for access control rewriting.

**Important**: Do NOT use a `location ^~ /secure/station/` block for Station itself. This breaks Station's PHP execution. Only use it for project rewriting.

## Ownership & Access Control

- Every project stores metadata:
  - `slug`: Project identifier
  - `owner`: Creating user
  - `sourceType`: upload, template, github
  - `createdAt`: Creation timestamp
- Ownership is tracked and displayed in dashboard
- Owner has full control; other users have read-only or configured access

## Security

- Credentials encrypted with base64 (use proper encryption in production)
- Data directory protected with `.htaccess`
- Database passwords never stored in plain text
- Session-based authentication
- CSRF protection on forms
- Input sanitization on all user inputs

## Extensibility

### Adding Custom Services
Edit `/secure/station/lib/docker.php` and add to `station_docker_services()`:
```php
'custom-service' => [
    'name' => 'Custom Service',
    'icon' => '🔧',
    'description' => 'Custom application',
    'versions' => ['1.0', '2.0'],
    'defaultVersion' => '2.0',
    'requiredEnvVars' => ['SERVICE_KEY' => 'Description'],
]
```

### Modifying Docker Compose Template
Edit `/secure/station/lib/docker.php`'s `station_generate_docker_compose()` function to customize generated `docker-compose.yml`.

## Deployment Scripts

Recommended script for automated project deployment:
```bash
#!/bin/bash
PROJECT_DIR=$1
cd "$PROJECT_DIR"

# Pull latest config
docker-compose pull

# Start services
docker-compose up -d

# Run initialization (if exists)
if [ -f init.sh ]; then
    docker-compose exec app bash init.sh
fi

echo "Project deployed: $PROJECT_DIR"
```

## Troubleshooting

**Services won't start**: Check `docker-compose logs`
```bash
docker-compose logs -f [service-name]
```

**No database connection**: Verify environment variables in docker-compose.yml match app config.

**Port conflicts**: Change exposed ports in `docker-compose.yml` or use `--port` flag.

**Credentials not working**: Decrypt and verify in `.secure-station-data/project-credentials/`

## Development vs Production

### Development Setup
```bash
docker-compose up
# Services accessible on localhost:PORT
```

### Production Setup
- Use volume mounts for persistent data
- Configure proper passwording for all services
- Use reverse proxy (nginx) with SSL
- Monitor service health
- Set up automated backups for database volumes

```bash
docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

## API Endpoints

### Docker Management (via form submissions)
- POST `/secure/station/docker-config.php` - Configure project services
- GET `/secure/station/docker-config.php?project=slug` - View configuration

### Project Metadata
- GET `/secure/station/project-viewer.php?project=slug` - View files
- GET `/secure/station/editor.php?project=slug` - Edit files

## Support & Docs

For deployment guides and advanced configuration, see:
- `DEPLOYMENT_GUIDE.md` - Docker, nginx, Apache, and cloud deployment
- `DOCKER_GUIDE.md` - Complete Docker containerization reference
- Service-specific docs in `/docs/services/`

---

**Version**: 4.0 (Docker-Enhanced)
**Last Updated**: 2026-05-09
