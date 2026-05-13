# Docker Deployment Guide

Complete guide for deploying Micro Deployment Station projects with Docker containers.

## Overview

The Docker integration allows projects to be deployed in isolated containers with automatic configuration of services like databases, caching layers, and message queues.

### Benefits
- **Consistency**: Same environment in dev and production
- **Isolation**: Projects don't interfere with each other
- **Scalability**: Easy to add/remove services
- **Security**: Encrypted credential storage
- **Portability**: Works anywhere Docker is installed

## Quick Start

### 1. Enable Docker in Admin Settings

Access `/secure/station/admin-settings.php?tab=docker` and:
- ✅ Check "Enable Docker Container Deployment"
- ✅ Select Docker Compose version (default: 3.9)
- ✅ Choose default database (MySQL, PostgreSQL, MongoDB, or None)
- ✅ Enable services you want available (MySQL, PostgreSQL, Redis, etc.)
- ✅ Save settings

### 2. Configure Project Services

Navigate to project settings and click "Docker Configuration" to:
- ✅ Enable "Deploy in Docker Container"
- ✅ Select services your project needs
- ✅ Set database credentials
- ✅ Choose service versions
- ✅ Save configuration

The system automatically:
- Encrypts&stores credentials securely
- Generates `docker-compose.yml` in project root
- Sets environment variables for database connections

### 3. Deploy the Project

```bash
cd /path/to/project
docker-compose up -d
```

Services start automatically with credentials from environment variables.

## Available Services

### MySQL 8.0 / MariaDB

**Best for**: PHP applications, WordPress, traditional SQL databases

**Versions Available**: 8.0, 5.7, mariadb-11, mariadb-10.6

**Credentials Configured**:
```
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=app_db
DB_USERNAME=admin
DB_PASSWORD=(your_password)
```

**Connection String** (PHP/Laravel):
```php
'mysql' => [
    'driver' => 'mysql',
    'host' => $_ENV['DB_HOST'],
    'port' => $_ENV['DB_PORT'],
    'database' => $_ENV['DB_DATABASE'],
    'username' => $_ENV['DB_USERNAME'],
    'password' => $_ENV['DB_PASSWORD'],
]
```

### PostgreSQL 15

**Best for**: Modern applications, PostGIS, advanced SQL features

**Versions Available**: 15, 14, 13, 12

**Credentials Configured**:
```
DATABASE_URL=postgresql://user:password@postgres:5432/app_db
POSTGRES_USER=admin
POSTGRES_PASSWORD=(your_password)
POSTGRES_DB=app_db
```

**Connection String** (Node.js with Sequelize):
```javascript
const sequelize = new Sequelize(process.env.DATABASE_URL, {
  dialect: 'postgres',
  logging: false,
});
```

### Redis 7

**Best for**: Session storage, caching, real-time features

**Versions Available**: 7, 6

**Credentials Configured**:
```
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=(optional)
```

**Connection** (PHP/Laravel):
```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'),
    'default' => [
        'host' => env('REDIS_HOST', 'localhost'),
        'password' => env('REDIS_PASSWORD', null),
        'port' => env('REDIS_PORT', 6379),
    ],
]
```

### MongoDB 7.0

**Best for**: NoSQL/document databases, flexible schemas

**Versions Available**: 7.0, 6.0, 5.0

**Credentials Configured**:
```
MONGO_URL=mongodb://admin:password@mongodb:27017/app_db
MONGO_INITDB_ROOT_USERNAME=admin
MONGO_INITDB_ROOT_PASSWORD=(your_password)
```

**Connection** (Node.js with Mongoose):
```javascript
mongoose.connect(process.env.MONGO_URL, {
  useNewUrlParser: true,
  useUnifiedTopology: true,
});
```

### Elasticsearch 8.0

**Best for**: Full-text search, analytics, logging

**Versions Available**: 8.0, 7.17

**Credentials Configured**:
```
ELASTIC_HOST=elasticsearch
ELASTIC_PORT=9200
ELASTIC_USERNAME=elastic
ELASTIC_PASSWORD=(your_password)
```

### RabbitMQ

**Best for**: Message queues, asynchronous tasks, job processing

**Versions Available**: latest, 3.12, 3.11

**Credentials Configured**:
```
RABBITMQ_HOST=rabbitmq
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASS=(your_password)
```

## Docker Compose Format

Generated `docker-compose.yml` structure:

```yaml
version: '3.9'

services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - .:/var/www/html
    environment:
      APP_ENV: production
      DB_HOST: mysql
      # ... auto-populated credentials
    networks:
      - app-network
    depends_on:
      - mysql
      - redis

  mysql:
    image: mysql:8.0
    ports:
      - "3306:3306"
    environment:
      MYSQL_ROOT_PASSWORD: (encrypted)
      MYSQL_DATABASE: app_db
      MYSQL_USER: app
      MYSQL_PASSWORD: (encrypted)
    volumes:
      - mysql-data:/var/lib/mysql
    networks:
      - app-network
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 10s
      timeout: 5s
      retries: 5

  redis:
    image: redis:7
    ports:
      - "6379:6379"
    networks:
      - app-network
    volumes:
      - redis-data:/data

networks:
  app-network:
    driver: bridge

volumes:
  mysql-data: {}
  redis-data: {}
```

## User Application Dockerfile

Create a `Dockerfile` in your project root:

### PHP Application (Apache)
```dockerfile
FROM php:8.2-apache

# Install extensions
RUN docker-php-ext-install pdo pdo_mysql mysqli redis

# Enable Apache modules
RUN a2enmod rewrite

# Set document root
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf

# Copy application
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html

WORKDIR /var/www/html

EXPOSE 80 443
CMD ["apache2-foreground"]
```

### Node.js Application
```dockerfile
FROM node:18-alpine

WORKDIR /app

COPY package*.json ./
RUN npm ci --only=production

COPY . .

EXPOSE 3000

CMD ["npm", "start"]
```

### Python Application
```dockerfile
FROM python:3.11-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

EXPOSE 5000

CMD ["python", "-m", "flask", "run", "--host=0.0.0.0"]
```

## Credential Security

Credentials are encrypted using base64 encoding and stored in:
```
/.secure-station-data/project-credentials/{project-slug}.json
```

### For Production:
Implement proper encryption (AES-256):
```php
// Update station_encrypt_data() in lib/docker.php
function station_encrypt_data(array $data): array {
    $key = hash('sha256', $_ENV['ENCRYPTION_KEY'], true);
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt(
        json_encode($data),
        'AES-256-CBC',
        $key,
        0,
        $iv
    );
    return [
        'data' => base64_encode($iv . $encrypted),
        'algorithm' => 'AES-256-CBC',
    ];
}
```

## Managing Services

### Start Services
```bash
docker-compose up -d
```

### Stop Services
```bash
docker-compose down
```

### View Logs
```bash
docker-compose logs -f [service-name]
```

### Execute Commands in Container
```bash
# MySQL
docker-compose exec mysql mysql -u root -p app_db

# Redis
docker-compose exec redis redis-cli

# App Container
docker-compose exec app bash
```

### Database Backups
```bash
# MySQL Backup
docker-compose exec mysql mysqldump -u root -p app_db > backup.sql

# PostgreSQL Backup  
docker-compose exec postgres pg_dump -U admin app_db > backup.sql

# MongoDB Backup
docker-compose exec mongodb mongodump --archive > backup.archive
```

## Production Checklist

- [ ] Use strong passwords (20+ chars) for all services
- [ ] Set `APP_DEBUG=false` in environment
- [ ] Configure proper database backups
- [ ] Use volume mounts for persistent data
- [ ] Set resource limits in docker-compose.yml
- [ ] Configure health checks
- [ ] Use secrets management for sensitive data
- [ ] Enable log rotation
- [ ] Configure automated monitoring
- [ ] Document your Docker setup

### Production docker-compose.yml additions
```yaml
mysql:
  # ... existing config
  deploy:
    resources:
      limits:
        cpus: '2'
        memory: 2G
  restart: always
```

## Troubleshooting

### Container won't start
```bash
docker-compose logs [service-name]
```

### Connection refused
```bash
# Check service is running
docker-compose ps

# Check network connectivity
docker-compose exec app ping mysql
```

### Database permission denied
Credentials likely copied incorrectly. Decrypt and verify:
```bash
cat /.secure-station-data/project-credentials/{slug}.json
```

### Port conflicts
Change port mappings in `docker-compose.yml`:
```yaml
mysql:
  ports:
    - "3307:3306"  # Use 3307 locally, 3306 in container
```

## Integration with Deployment Station

### Access Project Services from App Container

Services are accessible by hostname within the Docker network:
- `mysql:3306` (MySQL)
- `postgres:5432` (PostgreSQL)
- `redis:6379` (Redis)
- `mongodb:27017` (MongoDB)
- `elasticsearch:9200` (Elasticsearch)
- `rabbitmq:5672` (RabbitMQ)

All credentials auto-populated as environment variables.

### Health Checks

Services include health checks. Monitor status:
```bash
docker-compose ps

# Output:
# NAME              STATE      PORTS
# project_app_1     Up 2m
# project_mysql_1   Up 2m (healthy)
# project_redis_1   Up 2m (healthy)
```

## Advanced: Custom Services

To add custom services, edit `lib/docker.php`:

```php
function station_docker_services(): array {
    return [
        // ... existing services
        'my-custom-service' => [
            'name' => 'My Custom Service',
            'icon' => '⚙️',
            'description' => 'Custom application service',
            'versions' => ['1.0', '2.0'],
            'defaultVersion' => '2.0',
            'requiredEnvVars' => [
                'CUSTOM_KEY' => 'Required environment key',
            ],
        ]
    ];
}
```

Then implement handling in `station_build_service_compose_block()`.

## References

- [Docker Documentation](https://docs.docker.com/)
- [Docker Compose Reference](https://docs.docker.com/compose/compose-file/)
- [Service-Specific Docs](../docs/services/)

---

**Last Updated**: 2026-05-09
