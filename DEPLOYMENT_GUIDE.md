# Complete Deployment Guide

Production deployment guide for Micro Deployment Station with and without Docker.

## Table of Contents
1. [System Requirements](#system-requirements)
2. [Apache Deployment](#apache-deployment)
3. [Nginx Deployment](#nginx-deployment)
4. [Docker Host Setup](#docker-host-setup)
5. [Cloud Platforms](#cloud-platforms)
6. [Security Best Practices](#security-best-practices)
7. [Monitoring & Maintenance](#monitoring--maintenance)

## System Requirements

### Minimum Specifications
- **OS**: Linux (Ubuntu 20.04+, CentOS 8+), macOS, or Windows Server
- **PHP**: 8.0+ (8.2+ recommended)
- **Web Server**: Apache 2.4+ or Nginx 1.18+
- **Storage**: 10GB+ for projects and data
- **RAM**: 2GB minimum (4GB+ with Docker services)
- **Docker** (Optional): Engine 20.10+ if using containers

### PHP Extensions Required
```
- json (built-in)
- spl (built-in)
- standard (built-in)
- session (built-in)
```

### PHP Configuration
```ini
file_uploads = On
upload_max_filesize = 512M
post_max_size = 512M
max_file_uploads = 50
max_execution_time = 300
memory_limit = 256M
```

## Apache Deployment

### 1. Apache Installation

```bash
# Ubuntu/Debian
sudo apt-get update
sudo apt-get install -y apache2 php php-cli php-fpm libapache2-mod-php

# Enable required modules
sudo a2enmod rewrite
sudo a2enmod php8.2  # or appropriate version

# Start services
sudo systemctl start apache2
sudo systemctl enable apache2
```

### 2. Virtual Host Configuration

Create `/etc/apache2/sites-available/deployment-station.conf`:

```apache
<VirtualHost *:80>
    ServerName deploy.example.com
    ServerAlias *.deploy.example.com
    
    DocumentRoot /var/www/deployment-station
    
    # Logging
    ErrorLog ${APACHE_LOG_DIR}/deploy-error.log
    CustomLog ${APACHE_LOG_DIR}/deploy-access.json combined
    
    # Enable rewrite engine
    <Directory /var/www/deployment-station>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
        
        # PHP execution
        AddType application/x-httpd-php .php
    </Directory>
    
    # Serve Station on /secure/station/
    Alias /secure /var/www/deployment-station/secure
    
    # Protected data directory - deny access
    <Directory /var/www/deployment-station/data>
        Require all denied
    </Directory>
</VirtualHost>

# HTTPS Configuration (Recommended)
<VirtualHost *:443>
    ServerName deploy.example.com
    ServerAlias *.deploy.example.com
    
    DocumentRoot /var/www/deployment-station
    
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/deploy.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/deploy.example.com/privkey.pem
    
    # ... same configuration as port 80 ...
</VirtualHost>

# Redirect HTTP to HTTPS
<VirtualHost *:80>
    ServerName deploy.example.com
    ServerAlias *.deploy.example.com
    Redirect permanent / https://deploy.example.com/
</VirtualHost>
```

### 3. Enable Site

```bash
sudo a2ensite deployment-station.conf
sudo apache2ctl configtest  # Should output "Syntax OK"
sudo systemctl restart apache2
```

### 4. Set Permissions

```bash
# Create project directory
sudo mkdir -p /var/www/deployment-station
cd /var/www/deployment-station

# Clone or extract Station
# git clone ... or unzip ...

# Set proper permissions
sudo chown -R www-data:www-data .
sudo chmod -R 755 secure/
sudo chmod -R 700 .secure-station-data/  # Data directory

# Allow www-data to write to secure directory
sudo chmod -R 775 secure/
```

### 5. Configure Data Directory

```bash
# Create secure data directory outside web root (recommended)
sudo mkdir -p /var/lib/deployment-station-data
sudo chown www-data:www-data /var/lib/deployment-station-data
sudo chmod 700 /var/lib/deployment-station-data

# Export environment variable for Apache
echo 'export STATION_DATA_DIR=/var/lib/deployment-station-data' | \
    sudo tee -a /etc/apache2/envvars

sudo systemctl restart apache2
```

## Nginx Deployment

### 1. Nginx Installation

```bash
# Ubuntu/Debian
sudo apt-get update
sudo apt-get install -y nginx php-fpm

# Start services
sudo systemctl start nginx
sudo systemctl start php8.2-fpm
sudo systemctl enable nginx php8.2-fpm
```

### 2. Server Configuration

Create `/etc/nginx/sites-available/deployment-station`:

```nginx
upstream php {
    server unix:/run/php/php8.2-fpm.sock;
}

server {
    listen 80;
    listen [::]:80;
    server_name deploy.example.com *.deploy.example.com;
    
    # Redirect to HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name deploy.example.com *.deploy.example.com;
    
    # SSL Configuration
    ssl_certificate /etc/letsencrypt/live/deploy.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/deploy.example.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    
    # Root directory
    root /var/www/deployment-station;
    index index.php index.html;
    
    # Logging
    access_log /var/log/nginx/deploy-access.log combined;
    error_log /var/log/nginx/deploy-error.log;
    
    # Block access to data directory
    location ~ /\.secure-station-data/ {
        deny all;
        return 403;
    }
    
    # Station PHP files
    location /secure/station/ {
        try_files $uri $uri/ /secure/station/index.php?$query_string;
        
        location ~ \.php$ {
            fastcgi_pass php;
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include fastcgi_params;
            fastcgi_param STATION_DATA_DIR /var/lib/deployment-station-data;
        }
    }
    
    # Project access with rewrite (access control)
    location /secure/ {
        try_files $uri @project_handler;
    }
    
    location @project_handler {
        rewrite ^/secure/([a-zA-Z0-9_-]+)/(.*)$ /secure/station/project-serve.php?project=$1&path=$2 last;
    }
    
    # PHP execution for all .php files
    location ~ \.php$ {
        fastcgi_pass php;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_param STATION_DATA_DIR /var/lib/deployment-station-data;
    }
    
    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    
    # Gzip compression
    gzip on;
    gzip_types text/css text/javascript application/json text/xml;
}
```

### 3. Enable Configuration

```bash
sudo ln -s /etc/nginx/sites-available/deployment-station \
    /etc/nginx/sites-enabled/

# Check syntax
sudo nginx -t  # Should output "test is successful"

# Restart Nginx
sudo systemctl restart nginx
```

### 4. Set Permissions

```bash
# Create directories
sudo mkdir -p /var/www/deployment-station
sudo mkdir -p /var/lib/deployment-station-data

# Set ownership
sudo chown -R www-data:www-data /var/www/deployment-station
sudo chown -R www-data:www-data /var/lib/deployment-station-data

# Set permissions
sudo chmod -R 755 /var/www/deployment-station
sudo chmod 700 /var/lib/deployment-station-data
```

### 5. PHP-FPM Configuration

Edit `/etc/php/8.2/fpm/pool.d/www.conf`:

```ini
[www]
user = www-data
group = www-data
listen = /run/php/php8.2-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

; Increase limits
pm = dynamic
pm.max_children = 20
pm.start_servers = 5
pm.min_spare_servers = 3
pm.max_spare_servers = 10
pm.max_requests = 1000

; Security
security.limit_extensions = .php
```

Restart PHP-FPM:
```bash
sudo systemctl restart php8.2-fpm
```

## Docker Host Setup

### 1. Docker Installation

```bash
# Ubuntu/Debian
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh

# Add to docker group (optional, for non-root access)
sudo usermod -aG docker $USER
newgrp docker

# Enable at boot
sudo systemctl enable docker
```

### 2. Docker Daemon Configuration

Edit `/etc/docker/daemon.json`:

```json
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  },
  "storage-driver": "overlay2",
  "storage-opts": [
    "overlay2.override_kernel_check=true"
  ],
  "metrics-addr": "0.0.0.0:9323",
  "experimental": false
}
```

```bash
sudo systemctl restart docker
```

### 3. Docker Compose Installation

```bash
# Latest version
sudo curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
sudo chmod +x /usr/local/bin/docker-compose

# Verify
docker-compose --version
```

### 4. Create Docker Network

```bash
# Create dedicated network for Station
docker network create deployment-station-network
```

### 5. Storage for Volumes

```bash
# Create volume directory
sudo mkdir -p /var/lib/docker-volumes
sudo chown $USER:docker /var/lib/docker-volumes

# Update docker-compose.yml to use:
volumes:
  mysql-data:
    driver: local
    driver_opts:
      type: none
      o: bind
      device: /var/lib/docker-volumes/mysql-data
```

## Cloud Platforms

### AWS EC2 Deployment

```bash
#!/bin/bash
# Launch script for AWS EC2 (Ubuntu 20.04)

# Update system
sudo apt-get update && sudo apt-get upgrade -y

# Install dependencies
sudo apt-get install -y \
    apache2 php php-cli php-fpm php-json \
    curl wget git unzip \
    docker.io docker-compose

# Add user to docker group
sudo usermod -aG docker ubuntu

# Start services
sudo systemctl enable apache2 docker
sudo systemctl start apache2 docker

# Clone/extract Station
cd /var/www
sudo git clone https://github.com/your-repo/deployment-station .
sudo chown -R www-data:www-data .

# Configure Apache
sudo cp secure/root-index.php.example secure/index.php
sudo a2enmod rewrite
sudo systemctl restart apache2

# Create data directory
sudo mkdir -p /var/lib/deployment-station-data
sudo chown www-data:www-data /var/lib/deployment-station-data
```

### DigitalOcean App Platform

Create `app.yaml`:

```yaml
name: deployment-station
services:
- name: web
  github:
    repo: your-username/deployment-station
  build_command: "cd secure/station && php -S 0.0.0.0:8080"
  http_port: 8080
  source_dir: secure/station
  envs:
  - key: STATION_DATA_DIR
    value: /mnt/data
  volumes:
  - name: data
    path: /mnt/data
```

### Heroku Deployment

Create `Procfile`:

```
web: vendor/bin/heroku-php-apache2 secure/
```

Create `composer.json`:

```json
{
  "require": {
    "php": "^8.0"
  }
}
```

Deploy:
```bash
heroku login
heroku create my-deployment-station
git push heroku main
```

## Security Best Practices

### 1. SSL/TLS Certificates

Use Let's Encrypt for free HTTPS:

```bash
sudo apt-get install certbot python3-certbot-apache  # Apache
# or
sudo apt-get install certbot python3-certbot-nginx   # Nginx

# Obtain certificate
sudo certbot certonly --standalone -d deploy.example.com

# Auto-renewal
sudo systemctl enable certbot.timer
```

### 2. Firewall Configuration

```bash
# UFW (Ubuntu)
sudo ufw enable
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 3306/tcp (MySQL only if needed)  # Restrict to specific IPs
```

### 3. Security Headers

Apache (via `.htaccess` in `/secure/station/`):

```apache
# Security headers
Header set X-Frame-Options "SAMEORIGIN"
Header set X-Content-Type-Options "nosniff"
Header set X-XSS-Protection "1; mode=block"
Header set Referrer-Policy "strict-origin-when-cross-origin"
```

### 4. Data Directory Protection

```bash
# Deny all access to data directory  
chmod 700 /.secure-station-data

# Or configure web server to block
# (see Apache/Nginx configs above)
```

### 5. Regular Backups

```bash
#!/bin/bash
BACKUP_DIR="/backups/deployment-station"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

# Backup data
tar -czf "$BACKUP_DIR/data_$TIMESTAMP.tar.gz" /.secure-station-data/

# Backup projects
tar -czf "$BACKUP_DIR/projects_$TIMESTAMP.tar.gz" /var/www/deployment-station/

# Backup databases (if using Docker)
docker-compose exec mysql mysqldump -u root -p app_db | \
    gzip > "$BACKUP_DIR/mysql_$TIMESTAMP.sql.gz"

# Keep only last 30 days
find "$BACKUP_DIR" -name "*.tar.gz" -mtime +30 -delete
```

## Monitoring & Maintenance

### 1. Health Checks

```bash
#!/bin/bash
# Monitor Station availability

while true; do
    STATUS=$(curl -s -o /dev/null -w "%{http_code}" https://deploy.example.com/secure/station/)
    if [ "$STATUS" != "200" ]; then
        echo "WARNING: Station returned $STATUS"
        # Send alert, restart service, etc.
    fi
    sleep 300
done
```

### 2. Disk Space Monitoring

```bash
#!/bin/bash
# Alert if projects directory exceeds threshold

THRESHOLD=80  # 80%
USAGE=$(df -h /var/www/deployment-station | tail -1 | awk '{print $5}' | cut -d'%' -f1)

if [ $USAGE -gt $THRESHOLD ]; then
    echo "WARNING: Disk usage is ${USAGE}%"
    # Send alert, archive old projects, etc.
fi
```

### 3. Log Rotation

Create `/etc/logrotate.d/deployment-station`:

```
/var/log/nginx/deploy-*.log /var/log/apache2/deploy-*.log {
    daily
    missingok
    rotate 30
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
    sharedscripts
    postrotate
        [ -f /var/run/nginx.pid ] && kill -USR1 `cat /var/run/nginx.pid`
        [ -f /var/run/apache2.pid ] && kill -HUP `cat /var/run/apache2.pid`
    endscript
}
```

### 4. Cron Job Maintenance

```bash
# Daily backup (run as root)
0 2 * * * /usr/local/bin/backup-deployment-station.sh

# Weekly log cleanup
0 3 * * 0 find /var/www/deployment-station/.secure-station-data -name "*.log" -mtime +30 -delete

# Docker cleanup
0 4 * * 0 docker system prune -f
```

---

**Last Updated**: 2026-05-09
**Version**: 4.0 (Docker-Enhanced)
