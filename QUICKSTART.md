# Quick Start Guide - Docker Deployment

Get your Deployment Station up and running with Docker in minutes.

## Step 1: Enable Docker in Admin Settings

1. **Login to your Station** → `/secure/station/`
2. **Go to Admin Settings** → Click the gear icon (if in dashboard)
3. **Navigate to Docker tab** → Use left sidebar navigation
4. **Enable Docker**:
   - ✅ Check "Enable Docker Container Deployment"
   - ✅ Select Docker Compose version (3.9 default is fine)
   - ✅ Choose default database (MySQL recommended for beginners)
   - ✅ Check services you want available (at least MySQL and Redis)
   - ✅ Click "Save Docker Settings"

## Step 2: Install Docker (Host Only)

Your hosting server needs Docker installed:

```bash
# Check if Docker is already installed
docker --version
docker-compose --version

# If not installed:
curl -fsSL https://get.docker.com | sh
```

## Step 3: Upload Your First Project

1. **Create new project** via Dashboard
2. **Upload your code** (PHP, Node.js, or other app)
3. **Choose a project** from dashboard
4. **Access project settings**
5. **Click "Docker Configuration"**

## Step 4: Configure Services for Project

1. **Check "Deploy in Docker Container"**
2. **Select MySQL**
   - Set root password (e.g., `mysecurepass123`)
   - Keep database name as `app_db`
   - Optional: Create app user
3. **Select Redis** (for caching/sessions - optional)
   - Set Redis password if desired
4. **Save Configuration**

The system auto-generates `docker-compose.yml` in your project root.

## Step 5: Deploy the Project

```bash
# SSH into your server
ssh user@your-server.com

# Navigate to project
cd /path/to/your/project

# Start all services
docker-compose up -d

# Check services are running
docker-compose ps
# Output should show "healthy" status
```

## Step 6: Access Your Application

```
🌐 Your app: http://your-project.com
🗄️  MySQL: db.your-server.com:3306
⚡ Redis: cache.your-server.com:6379
```

Use credentials from your Docker configuration.

---

## Common Tasks

### View Service Logs

```bash
# All services
docker-compose logs -f

# Just MySQL
docker-compose logs -f mysql

# Just your app
docker-compose logs -f app
```

### Connect to Database

```bash
# MySQL
docker-compose exec mysql mysql -u root -p app_db
# (Enter your password when prompted)

# PostgreSQL
docker-compose exec postgres psql -U postgres -d app_db

# MongoDB
docker-compose exec mongodb mongosh -u admin -p --password
```

### Backup Database

```bash
# MySQL
docker-compose exec mysql mysqldump -u root -p app_db > backup.sql

# PostgreSQL
docker-compose exec postgres pg_dump -U admin app_db > backup.sql
```

### Restart Services

```bash
# Restart all
docker-compose restart

# Restart just MySQL
docker-compose restart mysql

# Restart your app
docker-compose restart app
```

### Stop Services

```bash
# Stop all (data stays)
docker-compose stop

# Stop & remove containers (data persists in volumes)
docker-compose down

# Full cleanup (WARNING: deletes everything!)
docker-compose down -v
```

---

## What Gets Configured Automatically

When you save Docker configuration, the system:

✅ **Encrypts credentials** and stores securely
✅ **Generates docker-compose.yml** in your project
✅ **Creates environment variables**:
  - `DB_HOST=mysql`
  - `DB_DATABASE=app_db`
  - `DB_USERNAME=admin`
  - `DB_PASSWORD=<your_password>`
  - `REDIS_HOST=redis`
  - `APP_ENV=production`
  - And many more...

✅ **Sets up service networking** so services talk to each other
✅ **Creates volumes** for database persistence
✅ **Configures health checks** on databases

## Where to Find Things

| Item | Location |
|------|----------|
| Admin Settings | `/secure/station/admin-settings.php` |
| Docker Config | `/secure/station/docker-config.php?project=slug` |
| Credentials | `/.secure-station-data/project-credentials/` |
| Docker Compose | `/path/to/project/docker-compose.yml` |
| Docker Guide | `/DOCKER_GUIDE.md` |
| Deployment Guide | `/DEPLOYMENT_GUIDE.md` |

## Troubleshooting

### Services won't start
```bash
docker-compose logs
# Look for error messages, usually credential-related
```

### Connection refused
```bash
# Check if services are running
docker-compose ps

# Make sure app container can reach database
docker-compose exec app ping mysql
docker-compose exec app ping redis
```

### Wrong password
1. Edit `/docker-compose.yml` in your project
2. Update `MYSQL_ROOT_PASSWORD` environment variable
3. Restart: `docker-compose restart mysql`

### Port conflicts
Edit `docker-compose.yml` and change port mappings:
```yaml
mysql:
  ports:
    - "3307:3306"  # Use 3307 instead of 3306
```

### Need to update credentials
1. Go back to Docker Configuration page
2. Update credentials
3. Save (regenerates docker-compose.yml)
4. Restart: `docker-compose restart`

---

## Recommended Docker Compose

For most projects, you want:

```yaml
MySQL 8.0 ✅        (Databases)
Redis 7 ✅          (Caching/Sessions)
PostgreSQL ⚪       (Alternative to MySQL)
MongoDB ⚪          (If using NoSQL)
Elasticsearch ⚪    (If needing search)
RabbitMQ ⚪         (If using message queues)
```

✅ = Recommended for most projects
⚪ = Use only if your app needs it

---

## Sample Application Setup

### PHP/Laravel
```
Docker Config:
- MySQL 8.0
- Redis 7

Environment Variables:
DB_CONNECTION=mysql
DB_HOST=mysql
DB_DATABASE=app_db
CACHE_DRIVER=redis
REDIS_HOST=redis
```

### Node.js/Express
```
Docker Config:
- MongoDB 7.0
- Redis 7

Environment Variables:
MONGODB_URI=mongodb://admin:pass@mongodb:27017/app_db
REDIS_URL=redis://:pass@redis:6379/0
```

### Python/Django
```
Docker Config:
- PostgreSQL 15
- Redis 7

Environment Variables:
DATABASE_URL=postgresql://admin:pass@postgres:5432/app_db
REDIS_URL=redis://:pass@redis:6379/0
```

---

## Next Steps

1. **Read the full guides**:
   - [Docker Guide](../DOCKER_GUIDE.md) - Complete service reference
   - [Deployment Guide](../DEPLOYMENT_GUIDE.md) - Production setup

2. **Create a test project**:
   - Upload sample app (PHP or Node.js)
   - Configure MySQL + Redis
   - Deploy and test

3. **Set up monitoring**:
   - Check logs regularly
   - Set up backups
   - Monitor disk space

4. **Move to production**:
   - Use strong passwords
   - Enable HTTPS
   - Configure backups
   - Set up monitoring

---

## Support

For detailed information, see:
- 📖 [README.md](../README.md) - Feature overview
- 🐳 [DOCKER_GUIDE.md](../DOCKER_GUIDE.md) - Docker reference
- 🚀 [DEPLOYMENT_GUIDE.md](../DEPLOYMENT_GUIDE.md) - Production deployment

**Last Updated**: May 9, 2026
