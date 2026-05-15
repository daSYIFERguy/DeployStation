<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Docker service and container management for Deployment Station
 * Handles Docker Compose generation, service configuration, and container orchestration
 */

const STATION_DOCKER_PORT_RANGE_START = 8100;
const STATION_DOCKER_PORT_RANGE_END = 8999;

/**
 * Get available Docker services with metadata
 */
function station_docker_services(): array
{
    return [
        'mysql' => [
            'name' => 'MySQL',
            'icon' => '🐬',
            'description' => 'Relational database (MySQL 8.x).',
            'versions' => ['8.0', '5.7'],
            'defaultVersion' => '8.0',
            'requiredEnvVars' => [
                'MYSQL_ROOT_PASSWORD' => 'Root password for database',
                'MYSQL_DATABASE' => 'Default database name (optional)',
                'MYSQL_USER' => 'Database user (optional)',
                'MYSQL_PASSWORD' => 'Database user password (optional)',
            ],
        ],
        'mariadb' => [
            'name' => 'MariaDB',
            'icon' => '🐚',
            'description' => 'MariaDB — community fork of MySQL.',
            'versions' => ['11', '10.11', '10.6'],
            'defaultVersion' => '11',
            'requiredEnvVars' => [
                'MARIADB_ROOT_PASSWORD' => 'Root password for database',
                'MARIADB_DATABASE' => 'Default database name (optional)',
                'MARIADB_USER' => 'Database user (optional)',
                'MARIADB_PASSWORD' => 'Database user password (optional)',
            ],
        ],
        'postgres' => [
            'name' => 'PostgreSQL',
            'icon' => '🐘',
            'description' => 'Advanced open-source relational database.',
            'versions' => ['16', '15', '14', '13'],
            'defaultVersion' => '16',
            'requiredEnvVars' => [
                'POSTGRES_USER' => 'Username for database superuser',
                'POSTGRES_PASSWORD' => 'Password for database superuser',
                'POSTGRES_DB' => 'Default database name (optional)',
            ],
        ],
        'redis' => [
            'name' => 'Redis',
            'icon' => '⚡',
            'description' => 'In-memory data store for caching and sessions.',
            'versions' => ['7.4', '7.2', '7', '6'],
            'defaultVersion' => '7.4',
            'requiredEnvVars' => [
                'REDIS_PASSWORD' => 'Optional password',
            ],
        ],
        'mongodb' => [
            'name' => 'MongoDB',
            'icon' => '🍃',
            'description' => 'NoSQL document database.',
            'versions' => ['7.0', '6.0', '5.0'],
            'defaultVersion' => '7.0',
            'requiredEnvVars' => [
                'MONGO_INITDB_ROOT_USERNAME' => 'Root username for MongoDB',
                'MONGO_INITDB_ROOT_PASSWORD' => 'Root password for MongoDB',
                'MONGO_INITDB_DATABASE' => 'Default database name (optional)',
            ],
        ],
        'elasticsearch' => [
            'name' => 'Elasticsearch',
            'icon' => '🔍',
            'description' => 'Search and analytics engine.',
            'versions' => ['8.15.0', '8.13.0', '8.0.0', '7.17.0'],
            'defaultVersion' => '8.15.0',
            'requiredEnvVars' => [
                'ELASTIC_USERNAME' => 'Username (default: elastic)',
                'ELASTIC_PASSWORD' => 'Password for elastic user',
            ],
        ],
        'rabbitmq' => [
            'name' => 'RabbitMQ',
            'icon' => '🐢',
            'description' => 'Message broker for asynchronous communication.',
            'versions' => ['3.13-management', '3.12-management', '3.11-management'],
            'defaultVersion' => '3.13-management',
            'requiredEnvVars' => [
                'RABBITMQ_DEFAULT_USER' => 'Default RabbitMQ username',
                'RABBITMQ_DEFAULT_PASS' => 'Default RabbitMQ password',
                'RABBITMQ_DEFAULT_VHOST' => 'Default virtual host (optional)',
            ],
        ],
        'meilisearch' => [
            'name' => 'Meilisearch',
            'icon' => '🔎',
            'description' => 'Lightning-fast, typo-tolerant search engine.',
            'versions' => ['v1.10', 'v1.9', 'v1.8'],
            'defaultVersion' => 'v1.10',
            'requiredEnvVars' => [
                'MEILI_MASTER_KEY' => 'Master API key (required in production).',
            ],
        ],
        'minio' => [
            'name' => 'MinIO',
            'icon' => '🪣',
            'description' => 'S3-compatible object storage. API on :9000, console on :9001.',
            'versions' => ['latest'],
            'defaultVersion' => 'latest',
            'requiredEnvVars' => [
                'MINIO_ROOT_USER' => 'Console / API root user.',
                'MINIO_ROOT_PASSWORD' => 'Console / API root password.',
            ],
        ],
        'mailpit' => [
            'name' => 'Mailpit',
            'icon' => '📬',
            'description' => 'SMTP test server with web UI. Catch outbound mail in dev.',
            'versions' => ['latest', 'v1.20'],
            'defaultVersion' => 'latest',
            'requiredEnvVars' => [],
        ],
        'memcached' => [
            'name' => 'Memcached',
            'icon' => '🧠',
            'description' => 'High-performance distributed memory cache.',
            'versions' => ['1.6-alpine', '1.6'],
            'defaultVersion' => '1.6-alpine',
            'requiredEnvVars' => [],
        ],
        'clickhouse' => [
            'name' => 'ClickHouse',
            'icon' => '📊',
            'description' => 'Columnar OLAP database for analytics.',
            'versions' => ['latest', '24.8', '24.3'],
            'defaultVersion' => 'latest',
            'requiredEnvVars' => [
                'CLICKHOUSE_USER' => 'Default user.',
                'CLICKHOUSE_PASSWORD' => 'Default user password.',
                'CLICKHOUSE_DB' => 'Default database name.',
            ],
        ],
        'influxdb' => [
            'name' => 'InfluxDB',
            'icon' => '📈',
            'description' => 'Time-series database for metrics & events.',
            'versions' => ['2.7'],
            'defaultVersion' => '2.7',
            'requiredEnvVars' => [
                'DOCKER_INFLUXDB_INIT_USERNAME' => 'Initial admin username.',
                'DOCKER_INFLUXDB_INIT_PASSWORD' => 'Initial admin password.',
                'DOCKER_INFLUXDB_INIT_ORG' => 'Initial organization name.',
                'DOCKER_INFLUXDB_INIT_BUCKET' => 'Initial bucket name.',
                'DOCKER_INFLUXDB_INIT_ADMIN_TOKEN' => 'Initial admin token.',
            ],
        ],
        'adminer' => [
            'name' => 'Adminer',
            'icon' => '🗃️',
            'description' => 'Web-based database admin tool. Auto-connects to other DB services on the app network.',
            'versions' => ['latest', '4'],
            'defaultVersion' => 'latest',
            'requiredEnvVars' => [],
        ],
        'mongo-express' => [
            'name' => 'Mongo Express',
            'icon' => '🍀',
            'description' => 'Web-based MongoDB admin UI. Best paired with the MongoDB service.',
            'versions' => ['latest', '1.0'],
            'defaultVersion' => 'latest',
            'requiredEnvVars' => [
                'ME_CONFIG_BASICAUTH_USERNAME' => 'UI login user.',
                'ME_CONFIG_BASICAUTH_PASSWORD' => 'UI login password.',
            ],
        ],
        'pgadmin' => [
            'name' => 'pgAdmin',
            'icon' => '🐘',
            'description' => 'Web-based PostgreSQL admin UI. Best paired with the PostgreSQL service.',
            'versions' => ['latest'],
            'defaultVersion' => 'latest',
            'requiredEnvVars' => [
                'PGADMIN_DEFAULT_EMAIL' => 'Login email (also used as admin user).',
                'PGADMIN_DEFAULT_PASSWORD' => 'Admin password.',
            ],
        ],
        'caddy' => [
            'name' => 'Caddy',
            'icon' => '🌳',
            'description' => 'Automatic HTTPS web server / reverse proxy. Mounts a Caddyfile when present.',
            'versions' => ['2-alpine', '2'],
            'defaultVersion' => '2-alpine',
            'requiredEnvVars' => [],
        ],
        'neo4j' => [
            'name' => 'Neo4j',
            'icon' => '🕸️',
            'description' => 'Graph database. Bolt on :7687, browser on :7474.',
            'versions' => ['5', '4.4'],
            'defaultVersion' => '5',
            'requiredEnvVars' => [
                'NEO4J_AUTH' => 'Login credentials in the form user/password.',
            ],
        ],
        'kafka' => [
            'name' => 'Kafka',
            'icon' => '📨',
            'description' => 'Apache Kafka (Bitnami image, KRaft mode — no Zookeeper).',
            'versions' => ['3.7', '3.6'],
            'defaultVersion' => '3.7',
            'requiredEnvVars' => [
                'KAFKA_CFG_NODE_ID' => 'Node ID (defaults to 0).',
                'KAFKA_CFG_PROCESS_ROLES' => 'controller,broker for single-node KRaft.',
            ],
        ],
        'nats' => [
            'name' => 'NATS',
            'icon' => '📡',
            'description' => 'Lightweight cloud-native messaging system.',
            'versions' => ['2.10-alpine', '2.10'],
            'defaultVersion' => '2.10-alpine',
            'requiredEnvVars' => [],
        ],
        'typesense' => [
            'name' => 'Typesense',
            'icon' => '🔡',
            'description' => 'Open-source typo-tolerant search engine.',
            'versions' => ['27.0', '0.25.2'],
            'defaultVersion' => '27.0',
            'requiredEnvVars' => [
                'TYPESENSE_API_KEY' => 'API key (required).',
            ],
        ],
        'prometheus' => [
            'name' => 'Prometheus',
            'icon' => '📐',
            'description' => 'Metrics scraping and time-series storage. Auto-creates a stub config.',
            'versions' => ['latest', 'v2.54.1'],
            'defaultVersion' => 'latest',
            'requiredEnvVars' => [],
        ],
        'grafana' => [
            'name' => 'Grafana',
            'icon' => '📊',
            'description' => 'Dashboards for metrics, logs, and traces.',
            'versions' => ['latest', '10.4.0'],
            'defaultVersion' => 'latest',
            'requiredEnvVars' => [
                'GF_SECURITY_ADMIN_PASSWORD' => 'Initial admin password (user is "admin").',
            ],
        ],
    ];
}

/**
 * Get Docker service by key
 */
function station_docker_service(string $serviceKey): ?array
{
    $services = station_docker_services();
    return $services[$serviceKey] ?? null;
}

/**
 * Get Docker settings from admin config
 */
function station_docker_settings(): array
{
    $settings = station_admin_settings();
    $stored = isset($settings['dockerSettings']) && is_array($settings['dockerSettings'])
        ? $settings['dockerSettings']
        : [];
    $serviceDefaults = array_fill_keys(array_keys(station_docker_services()), true);
    $storedServices = isset($stored['services']) && is_array($stored['services'])
        ? $stored['services']
        : [];
    $services = [];
    foreach ($serviceDefaults as $serviceKey => $defaultEnabled) {
        $services[$serviceKey] = array_key_exists($serviceKey, $storedServices)
            ? (bool) $storedServices[$serviceKey]
            : $defaultEnabled;
    }
    $composeVersion = (string) ($stored['composeVersion'] ?? '3.9');
    if (!in_array($composeVersion, ['3.8', '3.9'], true)) {
        $composeVersion = '3.9';
    }
    $defaultDatabase = (string) ($stored['defaultDatabase'] ?? 'mysql');
    if (!array_key_exists($defaultDatabase, station_docker_database_options())) {
        $defaultDatabase = 'mysql';
    }

    return [
        'enabled' => (bool) ($stored['enabled'] ?? false),
        'composeVersion' => $composeVersion,
        'defaultDatabase' => $defaultDatabase,
        'services' => $services,
        'dockerBinaryPath' => trim((string) ($stored['dockerBinaryPath'] ?? '')),
        'composeBinaryPath' => trim((string) ($stored['composeBinaryPath'] ?? '')),
    ];
}

/**
 * Check if Docker is enabled in settings
 */
function station_docker_enabled(): bool
{
    $settings = station_docker_settings();
    return (bool) ($settings['enabled'] ?? false);
}

/**
 * Get Docker Compose version
 */
function station_docker_compose_version(): string
{
    return (string) (station_docker_settings()['composeVersion'] ?? '3.9');
}

/**
 * Get database selection for container projects
 */
function station_docker_database_options(): array
{
    return [
        'mysql' => 'MySQL 8.0 / MariaDB',
        'postgres' => 'PostgreSQL 16',
        'mongodb' => 'MongoDB 7.0',
        'none' => 'No database',
    ];
}

function station_normalize_docker_port(mixed $value, int $default = 80): int
{
    $port = (int) $value;
    if ($port < 1 || $port > 65535) {
        return $default;
    }
    return $port;
}

function station_detect_project_container_port(string $projectSlug): int
{
    $projectPath = station_projects_dir() . '/' . station_safe_name($projectSlug);
    if (is_file($projectPath . '/package.json')) {
        $raw = (string) @file_get_contents($projectPath . '/package.json');
        $pkg = $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($pkg)) {
            $deps = array_merge(
                is_array($pkg['dependencies'] ?? null) ? $pkg['dependencies'] : [],
                is_array($pkg['devDependencies'] ?? null) ? $pkg['devDependencies'] : []
            );
            if (isset($deps['meshcentral']) || str_contains(strtolower((string) ($pkg['name'] ?? '')), 'meshcentral')) {
                return 4430;
            }
        }
        return 3000;
    }
    if (is_file($projectPath . '/requirements.txt') || is_file($projectPath . '/pyproject.toml')) {
        return 8000;
    }
    if (is_file($projectPath . '/Gemfile')) {
        return 3000;
    }
    return 80;
}

/**
 * Find an unused host port for a project. Returns the previously-allocated
 * port for this slug if found, otherwise picks the next free port within
 * the configured range and persists it.
 */
function station_allocate_project_host_port(string $projectSlug): int
{
    $slug = station_safe_name($projectSlug);
    $allocPath = station_data_dir() . '/docker-port-allocations.json';
    $allocations = station_read_json($allocPath, []);
    if (isset($allocations[$slug])) {
        return station_normalize_docker_port($allocations[$slug], STATION_DOCKER_PORT_RANGE_START);
    }

    $taken = array_values(array_map('intval', $allocations));
    for ($port = STATION_DOCKER_PORT_RANGE_START; $port <= STATION_DOCKER_PORT_RANGE_END; $port++) {
        if (in_array($port, $taken, true)) {
            continue;
        }
        if (station_port_is_in_use_locally($port)) {
            continue;
        }
        $allocations[$slug] = $port;
        station_write_json($allocPath, $allocations);
        return $port;
    }

    $fallback = STATION_DOCKER_PORT_RANGE_START + (crc32($slug) % (STATION_DOCKER_PORT_RANGE_END - STATION_DOCKER_PORT_RANGE_START));
    $allocations[$slug] = $fallback;
    station_write_json($allocPath, $allocations);
    return $fallback;
}

function station_port_is_in_use_locally(int $port): bool
{
    $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.15);
    if (is_resource($sock)) {
        fclose($sock);
        return true;
    }
    return false;
}

/**
 * Ensure a Dockerfile exists for a project. Detects common stacks.
 * When $force is true, the existing Dockerfile is overwritten.
 */
function station_ensure_project_dockerfile(string $projectSlug, bool $force = false): void
{
    $projectPath = station_projects_dir() . '/' . station_safe_name($projectSlug);
    $dockerfilePath = $projectPath . '/Dockerfile';
    if (!is_dir($projectPath)) {
        return;
    }

    if (!is_file($dockerfilePath) || $force) {
        $dockerfile = station_build_project_dockerfile($projectPath);
        @file_put_contents($dockerfilePath, $dockerfile, LOCK_EX);

        $ignorePath = $projectPath . '/.dockerignore';
        if (!is_file($ignorePath)) {
            @file_put_contents($ignorePath, station_default_dockerignore(), LOCK_EX);
        }
    }

    if (is_file($projectPath . '/composer.json') || is_file($projectPath . '/index.php')) {
        station_write_php_nginx_companion_files($projectPath, $force);
    }

    station_merge_station_entries_into_project_dockerignore($projectPath);
}

/**
 * Dockerfile body used for PHP projects. Pure nginx + php-fpm — no Apache.
 */
function station_php_nginx_dockerfile_text(): string
{
    return <<<'DOCKER'
# Generated by Deployment Station. PHP runs under nginx + php-fpm + supervisord.
FROM php:8.3-fpm-alpine

RUN apk add --no-cache nginx supervisor \
 && mkdir -p /run/nginx /var/www/html /var/log/supervisor

# Database drivers most starter apps want. Failing is non-fatal so the image
# still builds on systems where the extension is unavailable.
RUN docker-php-ext-install pdo_mysql mysqli || true

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf

COPY . /var/www/html/
RUN rm -rf /var/www/html/docker \
 && chown -R www-data:www-data /var/www/html

EXPOSE 80
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
DOCKER;
}

/**
 * Write the nginx + supervisord config files the PHP Dockerfile copies into the
 * image. Lives under `docker/` so it stays out of the project's web docroot.
 */
function station_write_php_nginx_companion_files(string $projectPath, bool $overwrite = false): void
{
    $dockerDir = $projectPath . '/docker';
    if (!is_dir($dockerDir) && !@mkdir($dockerDir, 0755, true) && !is_dir($dockerDir)) {
        return;
    }

    $nginxConf = $dockerDir . '/nginx.conf';
    if ($overwrite || !is_file($nginxConf)) {
        @file_put_contents($nginxConf, station_php_nginx_nginx_conf(), LOCK_EX);
    }

    $supervisor = $dockerDir . '/supervisord.conf';
    if ($overwrite || !is_file($supervisor)) {
        @file_put_contents($supervisor, station_php_nginx_supervisord_conf(), LOCK_EX);
    }
}

function station_php_nginx_nginx_conf(): string
{
    return <<<'NGX'
# Generated by Deployment Station. Edit freely — copied into the container at build time.
server {
    listen 80 default_server;
    server_name _;
    root /var/www/html;
    index index.php index.html index.htm;

    client_max_body_size 32m;
    charset utf-8;
    server_tokens off;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        include fastcgi_params;
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
    }

    location ~ /\.(?:ht|git) { deny all; }
}
NGX;
}

function station_php_nginx_supervisord_conf(): string
{
    return <<<'SUP'
; Generated by Deployment Station. Runs nginx and php-fpm side by side.
[supervisord]
nodaemon=true
user=root
logfile=/dev/null
logfile_maxbytes=0
pidfile=/run/supervisord.pid

[program:php-fpm]
command=php-fpm -F
autorestart=true
priority=10
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:nginx]
command=nginx -g "daemon off;"
autorestart=true
priority=20
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
SUP;
}

function station_default_dockerignore(): string
{
    return <<<'IGNORE'
.git
.gitignore
.env
.env.*
node_modules
npm-debug.log
yarn-error.log
.DS_Store
.vscode
.idea
*.log
dist
build
coverage
docker-compose*.yml
Dockerfile*
.dockerignore
# Written by Deployment Station for host Apache routing — breaks php-apache if copied in.
.htaccess
IGNORE;
}

/**
 * Ensure `.dockerignore` excludes Station host-only files that break containers if COPY'd.
 */
function station_merge_station_entries_into_project_dockerignore(string $projectPath): void
{
    $ignorePath = $projectPath . '/.dockerignore';
    if (!is_file($ignorePath)) {
        return;
    }

    $c = (string) @file_get_contents($ignorePath);
    if ($c === '') {
        return;
    }

    if (preg_match('/(^|\r?\n)\s*\.htaccess\s*(?:\r?\n|$)/', $c)) {
        return;
    }

    $append = "\n# Host-only (Deployment Station). Do not COPY into php-apache images.\n.htaccess\n";
    @file_put_contents($ignorePath, rtrim($c) . $append, LOCK_EX);
}

function station_infer_docker_stack(string $projectSlug): string
{
    $projectPath = station_projects_dir() . '/' . station_safe_name($projectSlug);
    if (!is_dir($projectPath)) {
        return 'other';
    }

    if (is_file($projectPath . '/package.json')) {
        return 'node';
    }
    if (is_file($projectPath . '/requirements.txt')) {
        return 'python';
    }
    if (is_file($projectPath . '/composer.json') || is_file($projectPath . '/index.php')) {
        return 'php';
    }

    return 'static';
}

function station_build_project_dockerfile(string $projectPath): string
{
    if (is_file($projectPath . '/package.json')) {
        $pkg = @json_decode((string) @file_get_contents($projectPath . '/package.json'), true);
        $scripts = is_array($pkg) && isset($pkg['scripts']) && is_array($pkg['scripts']) ? $pkg['scripts'] : [];
        $hasStart = isset($scripts['start']);
        $hasBuild = isset($scripts['build']);
        $hasDev = isset($scripts['dev']);
        $runCommand = $hasStart ? 'npm start' : ($hasDev ? 'npm run dev' : 'node index.js');

        $buildStep = $hasBuild ? "RUN npm run build || echo 'skip build'\n" : '';
        return <<<DOCKER
FROM node:22-alpine
WORKDIR /app
ENV HOST=0.0.0.0
ENV PORT=3000
ENV NODE_ENV=production
COPY package*.json ./
RUN if [ -f package-lock.json ]; then npm ci --omit=dev=false; else npm install; fi
COPY . .
{$buildStep}EXPOSE 3000
CMD ["sh", "-c", "{$runCommand}"]
DOCKER;
    }

    if (is_file($projectPath . '/requirements.txt')) {
        return <<<'DOCKER'
FROM python:3.13-slim
WORKDIR /app
ENV PYTHONUNBUFFERED=1
ENV PORT=8000
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY . .
EXPOSE 8000
CMD ["sh", "-c", "if [ -f manage.py ]; then python manage.py runserver 0.0.0.0:8000; elif [ -f app.py ]; then python app.py; elif [ -f main.py ]; then python main.py; else python -m http.server 8000; fi"]
DOCKER;
    }

    if (is_file($projectPath . '/composer.json') || is_file($projectPath . '/index.php')) {
        return station_php_nginx_dockerfile_text();
    }

    // Generic static site
    return <<<'DOCKER'
FROM nginx:alpine
COPY . /usr/share/nginx/html
# Provide a fallback index.html if the project doesn't include one
RUN [ -f /usr/share/nginx/html/index.html ] || \
    echo '<!doctype html><meta charset=utf-8><title>Project</title><body style="font-family:system-ui;padding:40px;color:#13233f"><h1>Project is running</h1><p>Replace this with your own <code>index.html</code>.</p></body>' > /usr/share/nginx/html/index.html
EXPOSE 80
DOCKER;
}

/**
 * Generate Docker Compose file for a project
 *
 * @param array $projectConfig Configuration with 'services' and 'credentials' arrays
 * @return string YAML content for docker-compose.yml
 */
function station_generate_docker_compose(array $projectConfig, string $projectSlug = ''): string
{
    $services = [];
    $appPort = station_normalize_docker_port($projectConfig['appPort'] ?? 80);
    $hostPort = station_normalize_docker_port($projectConfig['hostPort'] ?? $appPort, $appPort);
    $appVolumes = [];
    if (!empty($projectConfig['devMount'])) {
        $stack = strtolower(trim((string) ($projectConfig['stack'] ?? '')));
        if ($stack === '' && $projectSlug !== '') {
            $stack = station_infer_docker_stack($projectSlug);
        }
        $mountTarget = match ($stack) {
            'php' => '/var/www/html',
            'static' => '/usr/share/nginx/html',
            default => '/app',
        };
        $appVolumes[] = '.' . ':' . $mountTarget;
        if ($appPort === 3000 && $stack === 'node') {
            $appVolumes[] = '/app/node_modules';
        }
    }

    $enabledServiceKeys = [];

    if (!empty($projectConfig['services'])) {
        foreach ($projectConfig['services'] as $serviceKey => $isEnabled) {
            if (!$isEnabled) {
                continue;
            }
            $serviceConfig = station_docker_service($serviceKey);
            if (!$serviceConfig) {
                continue;
            }
            $services[$serviceKey] = station_build_service_compose_block(
                $serviceKey,
                $serviceConfig,
                $projectConfig['credentials'][$serviceKey] ?? [],
                $projectConfig,
                $projectSlug
            );
            $enabledServiceKeys[] = $serviceKey;
        }

        // Wire up inter-service `depends_on:` (e.g. mongo-express → mongodb).
        foreach ($enabledServiceKeys as $serviceKey) {
            $dependencies = station_docker_service_dependencies($serviceKey, $projectConfig['services']);
            if ($dependencies === []) {
                continue;
            }
            $services[$serviceKey]['depends_on'] = $dependencies;
        }
    }

    $caddyFront = !empty($projectConfig['services']['caddy']);
    $extraVolumeLines = station_parse_docker_volume_lines((string) ($projectConfig['extraVolumes'] ?? ''));
    if ($extraVolumeLines !== []) {
        $appVolumes = array_merge($appVolumes, $extraVolumeLines);
    }

    $appService = [
        'build' => [
            'context' => '.',
            'dockerfile' => 'Dockerfile',
        ],
        'restart' => 'unless-stopped',
        'environment' => array_merge(['PORT' => (string) $appPort], station_build_app_env_vars($projectConfig)),
        'networks' => ['app-network'],
    ];
    if (!$caddyFront) {
        $appService['ports'] = ['127.0.0.1:' . $hostPort . ':' . $appPort];
    }
    if ($appVolumes !== []) {
        $appService['volumes'] = $appVolumes;
    }

    if ($enabledServiceKeys !== []) {
        $dependsOn = [];
        foreach ($enabledServiceKeys as $serviceKey) {
            $health = station_docker_service_health_check($serviceKey);
            $dependsOn[$serviceKey] = $health !== null
                ? ['condition' => 'service_healthy']
                : ['condition' => 'service_started'];
        }
        $appService['depends_on'] = $dependsOn;
    }

    $services['app'] = $appService;

    if ($caddyFront && isset($services['caddy'])) {
        $services['caddy']['ports'] = ['127.0.0.1:' . $hostPort . ':80'];
        $caddyDepends = isset($services['caddy']['depends_on']) && is_array($services['caddy']['depends_on'])
            ? $services['caddy']['depends_on']
            : [];
        $caddyDepends['app'] = ['condition' => 'service_started'];
        $services['caddy']['depends_on'] = $caddyDepends;
    }

    $compose = [
        'services' => $services,
        'networks' => [
            'app-network' => [
                'driver' => 'bridge',
            ],
        ],
    ];

    $volumes = [];
    foreach ($enabledServiceKeys as $serviceKey) {
        $serviceVolumes = station_docker_service_volumes($serviceKey);
        foreach ($serviceVolumes as $vol) {
            if (str_starts_with($vol, '.') || str_starts_with($vol, '/')) {
                continue;
            }
            $parts = explode(':', $vol, 2);
            $name = $parts[0];
            if ($name !== '') {
                $volumes[$name] = null;
            }
        }
    }

    if (!empty($volumes)) {
        $compose['volumes'] = $volumes;
    }

    $yaml = "# Generated by Deployment Station — do not edit by hand.\n";
    $yaml .= "# Re-run \"Configure Docker\" in the dashboard to regenerate.\n";
    return $yaml . station_array_to_yaml($compose);
}

/**
 * Build a single Docker service block for docker-compose
 */
function station_build_service_compose_block(
    string $serviceKey,
    array $serviceConfig,
    array $credentials = [],
    array $projectConfig = [],
    string $projectSlug = ''
): array {
    $block = [
        'image' => station_docker_service_image($serviceKey, (string) ($credentials['version'] ?? $serviceConfig['defaultVersion'])),
        'restart' => 'unless-stopped',
        'networks' => ['app-network'],
    ];

    $command = station_docker_service_command($serviceKey);
    if ($command !== null) {
        $block['command'] = $command;
    }

    $env = station_docker_service_env_vars($serviceKey, $credentials);
    if ($env) {
        $block['environment'] = $env;
    }

    $ports = station_docker_service_host_ports($serviceKey, $projectConfig, $projectSlug);
    if ($ports !== []) {
        $block['ports'] = $ports;
    }

    $volumes = station_docker_service_volumes($serviceKey);
    if ($volumes) {
        $block['volumes'] = $volumes;
    }

    $healthCheck = station_docker_service_health_check($serviceKey);
    if ($healthCheck) {
        $block['healthcheck'] = $healthCheck;
    }

    return $block;
}

/**
 * Optional `command:` overrides for services that need custom args
 * (e.g. MinIO has to be told which mode + console-address to use).
 */
function station_docker_service_command(string $serviceKey): array|string|null
{
    $commands = [
        'minio' => ['server', '/data', '--console-address', ':9001'],
        'meilisearch' => ['meilisearch', '--no-analytics'],
        'typesense' => ['--data-dir', '/data', '--enable-cors'],
    ];

    return $commands[$serviceKey] ?? null;
}

/**
 * Host port mappings to expose a service's web UI / inbound port to the
 * developer's machine. Only services with a useful UI or developer-facing
 * port are exposed; everything else stays on the internal app-network.
 *
 * @return string[]
 */
function station_docker_service_host_ports(string $serviceKey, array $projectConfig = [], string $projectSlug = ''): array
{
    // Caddy is wired as an internal reverse proxy; the project host port maps to
    // caddy:80 in station_generate_docker_compose(). Never bind 0.0.0.0:80 here —
    // it conflicts with nginx/Apache on the host and breaks multi-project hosts.
    if ($serviceKey === 'caddy') {
        return [];
    }

    $ports = [
        'mailpit' => ['127.0.0.1:8025:8025', '127.0.0.1:1025:1025'],
        'minio' => ['127.0.0.1:9000:9000', '127.0.0.1:9001:9001'],
        'adminer' => ['127.0.0.1:8080:8080'],
        'mongo-express' => ['127.0.0.1:8081:8081'],
        'pgadmin' => ['127.0.0.1:5050:80'],
        'neo4j' => ['127.0.0.1:7474:7474', '127.0.0.1:7687:7687'],
        'prometheus' => ['127.0.0.1:9090:9090'],
        'grafana' => ['127.0.0.1:3001:3000'],
        'rabbitmq' => ['127.0.0.1:15672:15672'],
    ];

    return $ports[$serviceKey] ?? [];
}

/**
 * Optional `depends_on:` declarations for services that need another
 * service to be up first (e.g. mongo-express needs mongodb to exist).
 *
 * @return string[]
 */
function station_docker_service_dependencies(string $serviceKey, array $enabledServices): array
{
    $deps = [];
    if ($serviceKey === 'mongo-express' && !empty($enabledServices['mongodb'])) {
        $deps[] = 'mongodb';
    }
    if ($serviceKey === 'pgadmin' && !empty($enabledServices['postgres'])) {
        $deps[] = 'postgres';
    }
    if ($serviceKey === 'caddy' && !empty($enabledServices['caddy'])) {
        $deps[] = 'app';
    }
    return $deps;
}

/**
 * Ensure any auxiliary config files a service needs are present in the
 * project directory before docker-compose starts the service. Called by
 * the compose generator after writing docker-compose.yml.
 *
 * Currently:
 *   - prometheus → writes a no-op prometheus.yml if missing.
 *   - caddy      → writes a starter Caddyfile if missing.
 */
function station_ensure_docker_service_stub_files(string $projectSlug, array $enabledServices, int $appPort = 80): void
{
    $projectPath = station_projects_dir() . '/' . station_safe_name($projectSlug);
    if (!is_dir($projectPath)) {
        return;
    }

    if (!empty($enabledServices['prometheus'])) {
        $promPath = $projectPath . '/prometheus.yml';
        if (!is_file($promPath)) {
            $stub = <<<'YAML'
# Auto-generated stub by Deployment Station — replace with real scrape jobs.
global:
  scrape_interval: 15s
  evaluation_interval: 15s

scrape_configs:
  - job_name: prometheus
    static_configs:
      - targets: ['localhost:9090']

YAML;
            @file_put_contents($promPath, $stub, LOCK_EX);
        }
    }

    if (!empty($enabledServices['caddy'])) {
        $caddyPath = $projectPath . '/Caddyfile';
        if (!is_file($caddyPath)) {
            $appPort = max(1, min(65535, $appPort));
            $stub = <<<CADDY
# Auto-generated by Deployment Station — edit routes as needed.
# Station nginx proxies to the project host port (not host :80).
:80 {
    reverse_proxy app:{$appPort}
}
CADDY;
            @file_put_contents($caddyPath, $stub, LOCK_EX);
        }
    }
}

/**
 * Get Docker image string for a service
 */
function station_docker_service_image(string $serviceKey, string $version): string
{
    $images = [
        'mysql' => 'mysql',
        'mariadb' => 'mariadb',
        'postgres' => 'postgres',
        'redis' => 'redis',
        'mongodb' => 'mongo',
        'elasticsearch' => 'docker.elastic.co/elasticsearch/elasticsearch',
        'rabbitmq' => 'rabbitmq',
        'meilisearch' => 'getmeili/meilisearch',
        'minio' => 'quay.io/minio/minio',
        'mailpit' => 'axllent/mailpit',
        'memcached' => 'memcached',
        'clickhouse' => 'clickhouse/clickhouse-server',
        'influxdb' => 'influxdb',
        'adminer' => 'adminer',
        'mongo-express' => 'mongo-express',
        'pgadmin' => 'dpage/pgadmin4',
        'caddy' => 'caddy',
        'neo4j' => 'neo4j',
        'kafka' => 'bitnami/kafka',
        'nats' => 'nats',
        'typesense' => 'typesense/typesense',
        'prometheus' => 'prom/prometheus',
        'grafana' => 'grafana/grafana',
    ];

    // Backwards-compat: older configs may still pass mariadb-X as the
    // "mysql" service version; redirect it to the real mariadb image.
    if ($serviceKey === 'mysql' && str_starts_with($version, 'mariadb-')) {
        return 'mariadb:' . substr($version, strlen('mariadb-'));
    }

    $image = $images[$serviceKey] ?? $serviceKey;
    return $image . ':' . $version;
}

/**
 * Get environment variables for a service from credentials
 */
function station_docker_service_env_vars(string $serviceKey, array $credentials): array
{
    $env = [];

    $pick = static function (array $source, string $key, string $default): string {
        $value = (string) ($source[$key] ?? '');
        return $value !== '' ? $value : $default;
    };

    switch ($serviceKey) {
        case 'mysql':
            $env['MYSQL_ROOT_PASSWORD'] = $pick($credentials, 'rootPassword', 'rootpass123');
            $env['MYSQL_DATABASE'] = $pick($credentials, 'database', 'app_db');
            if (!empty($credentials['user'])) {
                $env['MYSQL_USER'] = (string) $credentials['user'];
                $env['MYSQL_PASSWORD'] = $pick($credentials, 'password', 'password123');
            }
            break;

        case 'mariadb':
            $env['MARIADB_ROOT_PASSWORD'] = $pick($credentials, 'rootPassword', 'rootpass123');
            $env['MARIADB_DATABASE'] = $pick($credentials, 'database', 'app_db');
            if (!empty($credentials['user'])) {
                $env['MARIADB_USER'] = (string) $credentials['user'];
                $env['MARIADB_PASSWORD'] = $pick($credentials, 'password', 'password123');
            }
            break;

        case 'postgres':
            $env['POSTGRES_USER'] = $pick($credentials, 'user', 'postgres');
            $env['POSTGRES_PASSWORD'] = $pick($credentials, 'password', 'postgres');
            $env['POSTGRES_DB'] = $pick($credentials, 'database', 'app_db');
            break;

        case 'redis':
            if (!empty($credentials['password'])) {
                $env['REDIS_PASSWORD'] = (string) $credentials['password'];
            }
            break;

        case 'mongodb':
            $env['MONGO_INITDB_ROOT_USERNAME'] = $pick($credentials, 'user', 'root');
            $env['MONGO_INITDB_ROOT_PASSWORD'] = $pick($credentials, 'password', 'password123');
            $env['MONGO_INITDB_DATABASE'] = $pick($credentials, 'database', 'app_db');
            break;

        case 'elasticsearch':
            $env['discovery.type'] = 'single-node';
            $env['xpack.security.enabled'] = 'true';
            $env['ELASTIC_USERNAME'] = 'elastic';
            $env['ELASTIC_PASSWORD'] = $pick($credentials, 'password', 'changeme');
            $env['ES_JAVA_OPTS'] = '-Xms512m -Xmx512m';
            break;

        case 'rabbitmq':
            $env['RABBITMQ_DEFAULT_USER'] = $pick($credentials, 'user', 'guest');
            $env['RABBITMQ_DEFAULT_PASS'] = $pick($credentials, 'password', 'guest');
            break;

        case 'meilisearch':
            $env['MEILI_ENV'] = 'development';
            $env['MEILI_MASTER_KEY'] = $pick($credentials, 'masterKey', 'masterKey123');
            break;

        case 'minio':
            $env['MINIO_ROOT_USER'] = $pick($credentials, 'user', 'minioadmin');
            $env['MINIO_ROOT_PASSWORD'] = $pick($credentials, 'password', 'minioadmin');
            break;

        case 'clickhouse':
            $env['CLICKHOUSE_USER'] = $pick($credentials, 'user', 'default');
            $env['CLICKHOUSE_PASSWORD'] = $pick($credentials, 'password', 'clickhouse');
            $env['CLICKHOUSE_DB'] = $pick($credentials, 'database', 'app_db');
            $env['CLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT'] = '1';
            break;

        case 'influxdb':
            $env['DOCKER_INFLUXDB_INIT_MODE'] = 'setup';
            $env['DOCKER_INFLUXDB_INIT_USERNAME'] = $pick($credentials, 'user', 'admin');
            $env['DOCKER_INFLUXDB_INIT_PASSWORD'] = $pick($credentials, 'password', 'influxdb123');
            $env['DOCKER_INFLUXDB_INIT_ORG'] = $pick($credentials, 'org', 'station');
            $env['DOCKER_INFLUXDB_INIT_BUCKET'] = $pick($credentials, 'database', 'app_bucket');
            $env['DOCKER_INFLUXDB_INIT_ADMIN_TOKEN'] = $pick($credentials, 'adminToken', 'influxdb-admin-token');
            break;

        case 'mongo-express':
            $env['ME_CONFIG_MONGODB_SERVER'] = 'mongodb';
            $env['ME_CONFIG_MONGODB_PORT'] = '27017';
            $env['ME_CONFIG_MONGODB_ADMINUSERNAME'] = $pick($credentials, 'mongoUser', 'root');
            $env['ME_CONFIG_MONGODB_ADMINPASSWORD'] = $pick($credentials, 'mongoPassword', 'password123');
            $env['ME_CONFIG_BASICAUTH_USERNAME'] = $pick($credentials, 'user', 'admin');
            $env['ME_CONFIG_BASICAUTH_PASSWORD'] = $pick($credentials, 'password', 'admin123');
            break;

        case 'pgadmin':
            $env['PGADMIN_DEFAULT_EMAIL'] = $pick($credentials, 'user', 'admin@example.com');
            $env['PGADMIN_DEFAULT_PASSWORD'] = $pick($credentials, 'password', 'pgadmin123');
            $env['PGADMIN_LISTEN_PORT'] = '80';
            break;

        case 'neo4j':
            $neo4jUser = $pick($credentials, 'user', 'neo4j');
            $neo4jPassword = $pick($credentials, 'password', 'neo4jpass123');
            $env['NEO4J_AUTH'] = $neo4jUser . '/' . $neo4jPassword;
            break;

        case 'kafka':
            // KRaft single-node controller+broker configuration. Advanced
            // multi-broker setups should override these in admin-side YAML.
            $env['KAFKA_CFG_NODE_ID'] = '0';
            $env['KAFKA_CFG_PROCESS_ROLES'] = 'controller,broker';
            $env['KAFKA_CFG_CONTROLLER_QUORUM_VOTERS'] = '0@kafka:9093';
            $env['KAFKA_CFG_LISTENERS'] = 'PLAINTEXT://:9092,CONTROLLER://:9093';
            $env['KAFKA_CFG_ADVERTISED_LISTENERS'] = 'PLAINTEXT://kafka:9092';
            $env['KAFKA_CFG_LISTENER_SECURITY_PROTOCOL_MAP'] = 'CONTROLLER:PLAINTEXT,PLAINTEXT:PLAINTEXT';
            $env['KAFKA_CFG_CONTROLLER_LISTENER_NAMES'] = 'CONTROLLER';
            $env['KAFKA_CFG_INTER_BROKER_LISTENER_NAME'] = 'PLAINTEXT';
            $env['ALLOW_PLAINTEXT_LISTENER'] = 'yes';
            break;

        case 'typesense':
            $env['TYPESENSE_API_KEY'] = $pick($credentials, 'masterKey', 'typesensekey123');
            $env['TYPESENSE_DATA_DIR'] = '/data';
            break;

        case 'grafana':
            $env['GF_SECURITY_ADMIN_USER'] = $pick($credentials, 'user', 'admin');
            $env['GF_SECURITY_ADMIN_PASSWORD'] = $pick($credentials, 'password', 'grafana123');
            break;

        case 'mailpit':
        case 'memcached':
        case 'adminer':
        case 'caddy':
        case 'nats':
        case 'prometheus':
            // No environment variables required for these services.
            break;
    }

    return $env;
}

/**
 * Get volumes for a service
 */
function station_docker_service_volumes(string $serviceKey): array
{
    $volumes = [
        'mysql' => ['mysql-data:/var/lib/mysql'],
        'mariadb' => ['mariadb-data:/var/lib/mysql'],
        'postgres' => ['postgres-data:/var/lib/postgresql/data'],
        'mongodb' => ['mongodb-data:/data/db'],
        'redis' => ['redis-data:/data'],
        'elasticsearch' => ['elasticsearch-data:/usr/share/elasticsearch/data'],
        'rabbitmq' => ['rabbitmq-data:/var/lib/rabbitmq'],
        'meilisearch' => ['meilisearch-data:/meili_data'],
        'minio' => ['minio-data:/data'],
        'clickhouse' => ['clickhouse-data:/var/lib/clickhouse'],
        'influxdb' => ['influxdb-data:/var/lib/influxdb2'],
        'pgadmin' => ['pgadmin-data:/var/lib/pgadmin'],
        'caddy' => ['caddy-data:/data', 'caddy-config:/config'],
        'neo4j' => ['neo4j-data:/data'],
        'kafka' => ['kafka-data:/bitnami/kafka'],
        'typesense' => ['typesense-data:/data'],
        'prometheus' => ['prometheus-data:/prometheus', './prometheus.yml:/etc/prometheus/prometheus.yml:ro'],
        'grafana' => ['grafana-data:/var/lib/grafana'],
    ];

    return $volumes[$serviceKey] ?? [];
}

/**
 * Get health check for a service
 */
function station_docker_service_health_check(string $serviceKey): ?array
{
    $checks = [
        'mysql' => [
            'test' => ['CMD', 'mysqladmin', 'ping', '-h', 'localhost'],
            'interval' => '10s',
            'timeout' => '5s',
            'retries' => 10,
            'start_period' => '30s',
        ],
        'mariadb' => [
            'test' => ['CMD', 'healthcheck.sh', '--connect', '--innodb_initialized'],
            'interval' => '10s',
            'timeout' => '5s',
            'retries' => 10,
            'start_period' => '30s',
        ],
        'postgres' => [
            'test' => ['CMD-SHELL', 'pg_isready -U postgres'],
            'interval' => '10s',
            'timeout' => '5s',
            'retries' => 10,
            'start_period' => '20s',
        ],
        'redis' => [
            'test' => ['CMD', 'redis-cli', 'ping'],
            'interval' => '10s',
            'timeout' => '5s',
            'retries' => 10,
            'start_period' => '10s',
        ],
        'mongodb' => [
            'test' => ['CMD', 'mongosh', '--quiet', '--eval', 'db.runCommand({ ping: 1 }).ok'],
            'interval' => '10s',
            'timeout' => '5s',
            'retries' => 10,
            'start_period' => '30s',
        ],
        'elasticsearch' => [
            'test' => ['CMD-SHELL', 'curl -fsS http://localhost:9200/_cluster/health || exit 1'],
            'interval' => '15s',
            'timeout' => '5s',
            'retries' => 10,
            'start_period' => '40s',
        ],
        'rabbitmq' => [
            'test' => ['CMD', 'rabbitmq-diagnostics', 'ping'],
            'interval' => '15s',
            'timeout' => '5s',
            'retries' => 10,
            'start_period' => '30s',
        ],
        'meilisearch' => [
            'test' => ['CMD-SHELL', 'wget -qO- http://localhost:7700/health || exit 1'],
            'interval' => '10s',
            'timeout' => '5s',
            'retries' => 10,
            'start_period' => '20s',
        ],
        'minio' => [
            'test' => ['CMD-SHELL', 'curl -f http://localhost:9000/minio/health/live || exit 1'],
            'interval' => '15s',
            'timeout' => '5s',
            'retries' => 10,
            'start_period' => '30s',
        ],
        'mailpit' => [
            'test' => ['CMD-SHELL', 'wget -qO- http://localhost:8025/api/v1/info || exit 1'],
            'interval' => '15s',
            'timeout' => '5s',
            'retries' => 10,
            'start_period' => '15s',
        ],
        'clickhouse' => [
            'test' => ['CMD-SHELL', 'wget --no-verbose --tries=1 --spider http://localhost:8123/ping || exit 1'],
            'interval' => '15s',
            'timeout' => '5s',
            'retries' => 10,
            'start_period' => '30s',
        ],
        'influxdb' => [
            'test' => ['CMD-SHELL', 'curl -fsS http://localhost:8086/ping || exit 1'],
            'interval' => '15s',
            'timeout' => '5s',
            'retries' => 10,
            'start_period' => '30s',
        ],
        'neo4j' => [
            'test' => ['CMD-SHELL', 'wget --no-verbose --tries=1 --spider http://localhost:7474 || exit 1'],
            'interval' => '15s',
            'timeout' => '5s',
            'retries' => 10,
            'start_period' => '40s',
        ],
        'typesense' => [
            'test' => ['CMD-SHELL', 'wget -qO- http://localhost:8108/health || exit 1'],
            'interval' => '15s',
            'timeout' => '5s',
            'retries' => 10,
            'start_period' => '20s',
        ],
        'prometheus' => [
            'test' => ['CMD-SHELL', 'wget --no-verbose --tries=1 --spider http://localhost:9090/-/ready || exit 1'],
            'interval' => '15s',
            'timeout' => '5s',
            'retries' => 10,
            'start_period' => '20s',
        ],
        'grafana' => [
            'test' => ['CMD-SHELL', 'wget --no-verbose --tries=1 --spider http://localhost:3000/api/health || exit 1'],
            'interval' => '15s',
            'timeout' => '5s',
            'retries' => 10,
            'start_period' => '25s',
        ],
        // memcached/adminer/caddy/nats/mongo-express/pgadmin/kafka — no
        // health-check probes set here (kafka's KRaft startup is slow,
        // leaving it out keeps `depends_on: service_started`).
    ];

    return $checks[$serviceKey] ?? null;
}

/**
 * Build app service environment variables
 */
function station_build_app_env_vars(array $projectConfig): array
{
    $env = [
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'NODE_ENV' => 'production',
    ];

    $pickStr = static function (array $source, string $key, string $default = ''): string {
        $value = (string) ($source[$key] ?? '');
        return $value !== '' ? $value : $default;
    };

    if (!empty($projectConfig['services'])) {
        if (!empty($projectConfig['services']['mysql'])) {
            $creds = $projectConfig['credentials']['mysql'] ?? [];
            $env['DB_CONNECTION'] = 'mysql';
            $env['DB_HOST'] = 'mysql';
            $env['DB_PORT'] = '3306';
            $env['DB_DATABASE'] = $pickStr($creds, 'database', 'app_db');
            $env['DB_USERNAME'] = $pickStr($creds, 'user', 'root');
            $env['DB_PASSWORD'] = $pickStr($creds, 'password', '');
            $env['DB_ROOT_PASSWORD'] = $pickStr($creds, 'rootPassword', '');
        }

        if (!empty($projectConfig['services']['postgres'])) {
            $creds = $projectConfig['credentials']['postgres'] ?? [];
            $user = $pickStr($creds, 'user', 'postgres');
            $password = $pickStr($creds, 'password', 'postgres');
            $database = $pickStr($creds, 'database', 'app_db');
            $env['DATABASE_URL'] = 'postgresql://' . $user . ':' . $password . '@postgres:5432/' . $database;
            $env['PGHOST'] = 'postgres';
            $env['PGPORT'] = '5432';
            $env['PGUSER'] = $user;
            $env['PGPASSWORD'] = $password;
            $env['PGDATABASE'] = $database;
        }

        if (!empty($projectConfig['services']['redis'])) {
            $env['REDIS_HOST'] = 'redis';
            $env['REDIS_PORT'] = '6379';
            if (!empty($projectConfig['credentials']['redis']['password'])) {
                $env['REDIS_PASSWORD'] = (string) $projectConfig['credentials']['redis']['password'];
            }
        }

        if (!empty($projectConfig['services']['mongodb'])) {
            $creds = $projectConfig['credentials']['mongodb'] ?? [];
            $user = $pickStr($creds, 'user', 'root');
            $password = $pickStr($creds, 'password', 'password123');
            $database = $pickStr($creds, 'database', 'app_db');
            $env['MONGO_URL'] = 'mongodb://' . $user . ':' . $password . '@mongodb:27017/' . $database . '?authSource=admin';
            $env['MONGO_DATABASE'] = $database;
        }

        if (!empty($projectConfig['services']['elasticsearch'])) {
            $creds = $projectConfig['credentials']['elasticsearch'] ?? [];
            $env['ELASTICSEARCH_URL'] = 'http://elasticsearch:9200';
            $env['ELASTIC_USERNAME'] = 'elastic';
            $env['ELASTIC_PASSWORD'] = $pickStr($creds, 'password', 'changeme');
        }

        if (!empty($projectConfig['services']['rabbitmq'])) {
            $creds = $projectConfig['credentials']['rabbitmq'] ?? [];
            $user = $pickStr($creds, 'user', 'guest');
            $password = $pickStr($creds, 'password', 'guest');
            $env['RABBITMQ_URL'] = 'amqp://' . $user . ':' . $password . '@rabbitmq:5672';
        }

        if (!empty($projectConfig['services']['mariadb'])) {
            $creds = $projectConfig['credentials']['mariadb'] ?? [];
            $env['MARIADB_HOST'] = 'mariadb';
            $env['MARIADB_PORT'] = '3306';
            $env['MARIADB_DATABASE'] = $pickStr($creds, 'database', 'app_db');
            $env['MARIADB_USER'] = $pickStr($creds, 'user', 'root');
            $env['MARIADB_PASSWORD'] = $pickStr($creds, 'password', '');
            $env['MARIADB_ROOT_PASSWORD'] = $pickStr($creds, 'rootPassword', '');
        }

        if (!empty($projectConfig['services']['meilisearch'])) {
            $creds = $projectConfig['credentials']['meilisearch'] ?? [];
            $env['MEILISEARCH_URL'] = 'http://meilisearch:7700';
            $env['MEILI_MASTER_KEY'] = $pickStr($creds, 'masterKey', 'masterKey123');
        }

        if (!empty($projectConfig['services']['minio'])) {
            $creds = $projectConfig['credentials']['minio'] ?? [];
            $env['S3_ENDPOINT'] = 'http://minio:9000';
            $env['S3_ACCESS_KEY_ID'] = $pickStr($creds, 'user', 'minioadmin');
            $env['S3_SECRET_ACCESS_KEY'] = $pickStr($creds, 'password', 'minioadmin');
            $env['S3_USE_PATH_STYLE'] = 'true';
        }

        if (!empty($projectConfig['services']['mailpit'])) {
            $env['MAIL_HOST'] = 'mailpit';
            $env['MAIL_PORT'] = '1025';
            $env['MAIL_FROM'] = 'no-reply@example.com';
        }

        if (!empty($projectConfig['services']['memcached'])) {
            $env['MEMCACHED_HOST'] = 'memcached';
            $env['MEMCACHED_PORT'] = '11211';
        }

        if (!empty($projectConfig['services']['clickhouse'])) {
            $creds = $projectConfig['credentials']['clickhouse'] ?? [];
            $user = $pickStr($creds, 'user', 'default');
            $password = $pickStr($creds, 'password', 'clickhouse');
            $database = $pickStr($creds, 'database', 'app_db');
            $env['CLICKHOUSE_URL'] = 'http://' . $user . ':' . $password . '@clickhouse:8123/' . $database;
            $env['CLICKHOUSE_HOST'] = 'clickhouse';
            $env['CLICKHOUSE_PORT'] = '8123';
            $env['CLICKHOUSE_USER'] = $user;
            $env['CLICKHOUSE_PASSWORD'] = $password;
            $env['CLICKHOUSE_DB'] = $database;
        }

        if (!empty($projectConfig['services']['influxdb'])) {
            $creds = $projectConfig['credentials']['influxdb'] ?? [];
            $env['INFLUXDB_URL'] = 'http://influxdb:8086';
            $env['INFLUXDB_ORG'] = $pickStr($creds, 'org', 'station');
            $env['INFLUXDB_BUCKET'] = $pickStr($creds, 'database', 'app_bucket');
            $env['INFLUXDB_TOKEN'] = $pickStr($creds, 'adminToken', 'influxdb-admin-token');
        }

        if (!empty($projectConfig['services']['neo4j'])) {
            $creds = $projectConfig['credentials']['neo4j'] ?? [];
            $user = $pickStr($creds, 'user', 'neo4j');
            $password = $pickStr($creds, 'password', 'neo4jpass123');
            $env['NEO4J_URI'] = 'bolt://neo4j:7687';
            $env['NEO4J_USER'] = $user;
            $env['NEO4J_PASSWORD'] = $password;
        }

        if (!empty($projectConfig['services']['kafka'])) {
            // TODO: Kafka clients vary wildly in env var conventions.
            // We inject the bootstrap servers — additional KAFKA_*
            // settings should be supplied by the caller as needed.
            $env['KAFKA_BOOTSTRAP_SERVERS'] = 'kafka:9092';
            $env['KAFKA_BROKERS'] = 'kafka:9092';
        }

        if (!empty($projectConfig['services']['nats'])) {
            $env['NATS_URL'] = 'nats://nats:4222';
        }

        if (!empty($projectConfig['services']['typesense'])) {
            $creds = $projectConfig['credentials']['typesense'] ?? [];
            $env['TYPESENSE_HOST'] = 'typesense';
            $env['TYPESENSE_PORT'] = '8108';
            $env['TYPESENSE_PROTOCOL'] = 'http';
            $env['TYPESENSE_API_KEY'] = $pickStr($creds, 'masterKey', 'typesensekey123');
        }

        if (!empty($projectConfig['services']['grafana'])) {
            $env['GRAFANA_URL'] = 'http://grafana:3000';
        }

        if (!empty($projectConfig['services']['prometheus'])) {
            $env['PROMETHEUS_URL'] = 'http://prometheus:9090';
        }
    }

    foreach (station_parse_docker_env_lines((string) ($projectConfig['extraEnvironment'] ?? '')) as $key => $value) {
        $env[$key] = $value;
    }

    return $env;
}

/**
 * Parse KEY=value lines for extra app container environment variables.
 *
 * @return array<string, string>
 */
function station_parse_docker_env_lines(string $text): array
{
    $result = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
        $trimmed = trim((string) $line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $trimmed, 2);
        $safeKey = strtoupper(trim($key));
        if ($safeKey === '') {
            continue;
        }
        $result[$safeKey] = trim($value);
    }

    return $result;
}

/**
 * Parse docker-compose volume lines (host:container[:mode]).
 *
 * @return list<string>
 */
function station_parse_docker_volume_lines(string $text): array
{
    $volumes = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
        $trimmed = trim((string) $line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, ':')) {
            continue;
        }
        $volumes[] = $trimmed;
    }

    return $volumes;
}

/**
 * Operator-facing hint when docker compose fails with common host errors.
 */
function station_docker_failure_hint(string $output): string
{
    $out = strtolower($output);
    if (str_contains($out, 'address already in use') && (str_contains($out, ':80') || str_contains($out, '0.0.0.0:80'))) {
        return ' Port 80 on the host is already in use (usually nginx or Apache). '
            . 'Uncheck Caddy for this project and save — Station proxies via your project host port and /p/&lt;slug&gt;/ URL instead. '
            . 'Then open Docker → Rebuild image.';
    }
    if (str_contains($out, 'address already in use')) {
        return ' A host port in docker-compose is already taken. Change the project host port under Docker → Deployment, '
            . 'or stop the other container using that port.';
    }
    if (str_contains($out, 'timed out') || str_contains($out, 'timeout')) {
        return ' The build or start exceeded the server time limit (often Cloudflare or PHP). '
            . 'Run Rebuild from the Docker panel again, or SSH and run docker compose in the project folder.';
    }

    return '';
}

/**
 * Scan a cloned/uploaded workspace and suggest Docker services, stack, and app port.
 *
 * @return array{recommendedServices: list<string>, appPort: int, stack: string, notes: list<string>}
 */
function station_analyze_workspace_docker_hints(string $projectSlug): array
{
    $slug = station_safe_name($projectSlug);
    $projectPath = station_projects_dir() . '/' . $slug;
    $recommended = [];
    $notes = [];
    $stack = station_infer_docker_stack($slug);
    $appPort = station_detect_project_container_port($slug);

    if (!is_dir($projectPath)) {
        return ['recommendedServices' => [], 'appPort' => 80, 'stack' => 'other', 'notes' => []];
    }

    $composePath = station_find_project_compose_path($projectPath);
    if ($composePath !== null) {
        $yaml = (string) @file_get_contents($composePath);
        foreach (station_compose_yaml_collect_images($yaml) as $img) {
            $key = station_compose_image_to_station_service_key($img);
            if ($key !== null) {
                $recommended[] = $key;
            }
        }
        $notes[] = 'Found ' . basename($composePath) . ' in the repository.';
    }

    $haystack = '';
    foreach (['README.md', 'readme.md', 'package.json', 'docker-compose.yml', 'compose.yaml'] as $file) {
        $p = $projectPath . '/' . $file;
        if (is_file($p)) {
            $haystack .= "\n" . strtolower((string) @file_get_contents($p));
        }
    }
    if (str_contains($haystack, 'meshcentral')) {
        $recommended[] = 'mongodb';
        $stack = 'node';
        $appPort = 4430;
        $notes[] = 'MeshCentral detected — MongoDB and app port 4430 are typical.';
    }
    if (str_contains($haystack, 'wordpress') || is_file($projectPath . '/wp-config.php')) {
        $recommended[] = 'mysql';
        $stack = 'php';
        $notes[] = 'WordPress/PHP project — MySQL is a common pairing.';
    }
    if (str_contains($haystack, 'laravel') || is_file($projectPath . '/artisan')) {
        $recommended[] = 'mysql';
        $recommended[] = 'redis';
        $stack = 'php';
    }
    if (str_contains($haystack, 'next.js') || str_contains($haystack, '"next"')) {
        $stack = 'node';
        $appPort = 3000;
    }

    $recommended = array_values(array_unique($recommended));

    return [
        'recommendedServices' => $recommended,
        'appPort' => $appPort,
        'stack' => $stack,
        'notes' => array_values(array_unique($notes)),
    ];
}

/**
 * After GitHub clone or upload: enable Docker, pre-select services, write compose when appropriate.
 */
function station_bootstrap_docker_from_workspace(string $projectSlug): void
{
    if (!station_docker_enabled()) {
        return;
    }
    $slug = station_safe_name($projectSlug);
    if ($slug === '') {
        return;
    }

    station_try_bootstrap_native_docker_from_workspace($slug);

    $settings = station_project_settings($slug);
    $docker = isset($settings['docker']) && is_array($settings['docker']) ? $settings['docker'] : [];
    if (!empty($docker['nativeCompose'])) {
        return;
    }

    $hints = station_analyze_workspace_docker_hints($slug);
    $services = isset($docker['services']) && is_array($docker['services']) ? $docker['services'] : [];
    foreach ($hints['recommendedServices'] as $svc) {
        if (station_docker_service($svc) !== null) {
            $services[$svc] = true;
        }
    }

    $docker = array_merge($docker, [
        'containerized' => true,
        'services' => $services,
        'credentials' => isset($docker['credentials']) && is_array($docker['credentials']) ? $docker['credentials'] : [],
        'recommendedServices' => array_values(array_unique(array_merge(
            (array) ($docker['recommendedServices'] ?? []),
            $hints['recommendedServices']
        ))),
        'stack' => trim((string) ($docker['stack'] ?? '')) !== '' ? (string) $docker['stack'] : $hints['stack'],
        'appPort' => (int) ($docker['appPort'] ?? 0) > 0 ? (int) $docker['appPort'] : $hints['appPort'],
        'hostPort' => (int) ($docker['hostPort'] ?? 0) > 0 ? (int) $docker['hostPort'] : station_allocate_project_host_port($slug),
        'workspaceDockerNotes' => $hints['notes'],
    ]);

    $settings['docker'] = $docker;
    station_save_project_settings($slug, $settings);

    if (!empty($docker['containerized']) || $services !== []) {
        station_ensure_project_dockerfile($slug);
        station_ensure_docker_service_stub_files($slug, $services, (int) $docker['appPort']);
        $composePath = station_projects_dir() . '/' . $slug . '/docker-compose.yml';
        @file_put_contents($composePath, station_generate_docker_compose($docker, $slug), LOCK_EX);
    }
}

/**
 * Convert PHP array to YAML format
 * Simple YAML generator for docker-compose files
 */
function station_array_to_yaml(array $data, int $indent = 0): string
{
    $yaml = '';
    $prefix = str_repeat('  ', $indent);

    foreach ($data as $key => $value) {
        $isListItem = is_int($key);
        $keyPart = $isListItem ? '- ' : (string) $key . ': ';
        $linePrefix = $isListItem ? str_repeat('  ', max(0, $indent - 1)) . '  ' : $prefix;

        if (is_array($value)) {
            if (empty($value)) {
                $yaml .= $linePrefix . $keyPart . "{}\n";
            } elseif (station_is_sequential_array($value)) {
                $yaml .= $linePrefix . $keyPart . "\n";
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $yaml .= station_array_to_yaml([0 => $item], $indent + 1);
                    } else {
                        $yaml .= str_repeat('  ', $indent + 1) . '- ' . station_yaml_scalar($item) . "\n";
                    }
                }
            } else {
                $yaml .= $linePrefix . $keyPart . "\n";
                $yaml .= station_array_to_yaml($value, $indent + 1);
            }
        } elseif ($value === null) {
            $yaml .= $linePrefix . rtrim($keyPart) . "\n";
        } else {
            $yaml .= $linePrefix . $keyPart . station_yaml_scalar($value) . "\n";
        }
    }

    return $yaml;
}

/**
 * Check if array is sequential (list-like)
 */
function station_is_sequential_array(array $arr): bool
{
    if ($arr === []) {
        return false;
    }
    $keys = array_keys($arr);
    return $keys === range(0, count($arr) - 1);
}

/**
 * Format scalar value for YAML
 */
function station_yaml_scalar($value): string
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_int($value) || is_float($value)) {
        $s = is_float($value)
            ? rtrim(rtrim(sprintf('%.12F', $value), '0'), '.')
            : (string) $value;
        // Bare `3.9` in YAML parses as a float; Compose's JSON schema requires
        // some fields (e.g. legacy top-level `version`) to be strings.
        if (preg_match('/^\d+\.\d+$/', $s) === 1) {
            return "'" . str_replace("'", "''", $s) . "'";
        }
        return $s;
    }
    $value = (string) $value;

    if ($value === '' ||
        in_array(strtolower($value), ['true', 'false', 'null', 'yes', 'no', 'on', 'off'], true) ||
        preg_match('/^\d+$/', $value) === 1 ||
        preg_match('/^\d+\.\d+$/', $value) === 1 ||
        strpos($value, ':') !== false ||
        strpos($value, '#') === 0 ||
        strpos($value, '\n') !== false ||
        strpos($value, '"') !== false ||
        preg_match('/^[\s\-\[\]{}|>*&!%@`]/', $value) === 1) {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    return $value;
}

/**
 * Writable HOME directory used when shelling out to docker/compose.
 *
 * PHP-FPM's www-data inherits HOME=/var/www which is (a) not writable and
 * (b) outside /home — snap-installed docker hard-refuses anything outside
 * /home, and `docker` itself also wants to drop ~/.docker/config.json.
 * We park everything under the station data dir so it's permanent across
 * restarts but isolated from real user homes.
 */
function station_docker_runtime_home(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $base = function_exists('station_data_dir')
        ? station_data_dir() . '/docker-home'
        : sys_get_temp_dir() . '/deploy-station-docker-home';

    if (!is_dir($base)) {
        @mkdir($base, 0700, true);
    }

    foreach (['.docker', '.config', '.cache', '.local', '.local/share', '.local/state'] as $sub) {
        $path = $base . '/' . $sub;
        if (!is_dir($path)) {
            @mkdir($path, 0700, true);
        }
    }

    $cached = $base;
    return $cached;
}

/**
 * True if the resolved docker binary lives inside a snap install. Snap docker
 * has confined-home requirements that make it a poor fit for PHP-FPM; we
 * surface this in diagnostics so admins know to switch to docker-ce/docker.io.
 */
function station_docker_is_snap(?string $binary = null): bool
{
    $resolved = $binary ?? station_docker_binary();
    $resolved = (string) $resolved;
    if ($resolved === '') {
        return false;
    }
    if (str_starts_with($resolved, '/snap/') || str_starts_with($resolved, '/var/lib/snapd/')) {
        return true;
    }
    $real = @readlink($resolved);
    if (is_string($real) && (str_starts_with($real, '/snap/') || str_starts_with($real, '/var/lib/snapd/'))) {
        return true;
    }
    return false;
}

/**
 * Common locations to search for the docker binary regardless of the PHP
 * process' inherited PATH (which under php-fpm/apache is often just
 * /usr/bin:/bin and misses /usr/local/bin, snap, or homebrew installs).
 */
function station_docker_extra_path_entries(): array
{
    return [
        '/usr/local/sbin',
        '/usr/local/bin',
        '/opt/homebrew/bin',
        '/opt/homebrew/sbin',
        '/snap/bin',
        '/var/lib/snapd/snap/bin',
        '/usr/sbin',
        '/usr/bin',
        '/sbin',
        '/bin',
    ];
}

/**
 * Build the PATH environment to use when invoking docker-related commands.
 * Combines the inherited PATH (if any) with the extra entries above so that
 * docker installed in any common location is found.
 */
function station_docker_runtime_path(): string
{
    $inherited = (string) (getenv('PATH') ?: '');
    $extras = station_docker_extra_path_entries();
    $seen = [];
    $entries = [];

    foreach ($extras as $entry) {
        if ($entry === '' || isset($seen[$entry])) {
            continue;
        }
        $entries[] = $entry;
        $seen[$entry] = true;
    }
    if ($inherited !== '') {
        foreach (explode(PATH_SEPARATOR, $inherited) as $entry) {
            $entry = trim($entry);
            if ($entry === '' || isset($seen[$entry])) {
                continue;
            }
            $entries[] = $entry;
            $seen[$entry] = true;
        }
    }

    return implode(PATH_SEPARATOR, $entries);
}

/**
 * Resolve the absolute path to the docker binary. Checks (in order):
 *  1. An admin-configured `dockerBinaryPath` from admin settings.
 *  2. `command -v docker` using the augmented PATH.
 *  3. Known install locations (homebrew, snap, /usr/local/bin, /usr/bin).
 */
function station_docker_binary(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $override = '';
    if (function_exists('station_admin_settings')) {
        $settings = station_admin_settings();
        $configured = trim((string) ($settings['dockerSettings']['dockerBinaryPath'] ?? ''));
        if ($configured !== '') {
            $override = $configured;
        }
    }

    if ($override !== '' && is_file($override) && is_executable($override)) {
        $cached = $override;
        return $cached;
    }

    $runtimePath = station_docker_runtime_path();
    $shellCheck = @shell_exec('PATH=' . escapeshellarg($runtimePath) . ' command -v docker 2>/dev/null');
    if (is_string($shellCheck)) {
        $candidate = trim($shellCheck);
        if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
            $cached = $candidate;
            return $cached;
        }
    }

    foreach (['/usr/local/bin/docker', '/opt/homebrew/bin/docker', '/usr/bin/docker', '/snap/bin/docker', '/var/lib/snapd/snap/bin/docker'] as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            $cached = $candidate;
            return $cached;
        }
    }

    // Final fallback — bare "docker" still works if PHP later finds it on PATH.
    $cached = 'docker';
    return $cached;
}

/**
 * Candidate paths for the Compose v2 binary when it is not available as
 * `docker compose` (missing CLI plugin). The same binary is often installed
 * as `docker-compose` or under Docker's cli-plugins directory.
 *
 * @return list<string>
 */
function station_docker_compose_binary_candidates(): array
{
    $out = [];
    if (function_exists('station_docker_settings')) {
        $configured = trim((string) (station_docker_settings()['composeBinaryPath'] ?? ''));
        if ($configured !== '') {
            $out[] = $configured;
        }
    }
    return array_merge($out, [
        '/usr/local/bin/docker-compose',
        '/usr/bin/docker-compose',
        '/usr/libexec/docker/cli-plugins/docker-compose',
        '/usr/lib/docker/cli-plugins/docker-compose',
        '/usr/local/lib/docker/cli-plugins/docker-compose',
        '/opt/homebrew/bin/docker-compose',
    ]);
}

/**
 * Resolve the standalone docker-compose binary path (only used as a fallback
 * when the v2 compose plugin is not installed).
 */
function station_docker_compose_binary(): string
{
    static $cacheKey = null;
    static $cached = null;

    $settings = function_exists('station_docker_settings') ? station_docker_settings() : [];
    $key = trim((string) ($settings['composeBinaryPath'] ?? ''));
    if ($cached !== null && $cacheKey === $key) {
        return $cached;
    }

    foreach (station_docker_compose_binary_candidates() as $candidate) {
        if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
            $cached = $candidate;
            $cacheKey = $key;
            return $cached;
        }
    }

    $runtimePath = station_docker_runtime_path();
    $shellCheck = @shell_exec('PATH=' . escapeshellarg($runtimePath) . ' command -v docker-compose 2>/dev/null');
    if (is_string($shellCheck)) {
        $candidate = trim($shellCheck);
        if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
            $cached = $candidate;
            $cacheKey = $key;
            return $cached;
        }
    }

    $cached = 'docker-compose';
    $cacheKey = $key;
    return $cached;
}

/**
 * Detect which docker compose CLI invocation is available.
 * Returns the resolved binary path + plugin/standalone args, e.g.
 *   ['/usr/local/bin/docker', 'compose']  for the v2 plugin
 *   ['/usr/local/bin/docker-compose']     for legacy
 */
function station_docker_compose_cli(): array
{
    static $cacheKey = null;
    static $cached = null;

    $settings = station_docker_settings();
    $key = trim((string) ($settings['dockerBinaryPath'] ?? ''))
        . "\0" . trim((string) ($settings['composeBinaryPath'] ?? ''));
    if ($cached !== null && $cacheKey === $key) {
        return $cached;
    }

    $docker = station_docker_binary();
    $composeOverride = trim((string) (station_docker_settings()['composeBinaryPath'] ?? ''));

    // Admin explicitly pinned a standalone compose binary — honor it first.
    if ($composeOverride !== '' && is_file($composeOverride) && is_executable($composeOverride)) {
        $probe = station_run_shell_cmd([$composeOverride, 'version'], null, 5);
        if (($probe['code'] ?? 1) === 0) {
            $cached = [$composeOverride];
            $cacheKey = $key;
            return $cached;
        }
        // Fall through: bad path or broken binary — still try plugin + auto-detect.
    }

    $result = station_run_shell_cmd([$docker, 'compose', 'version'], null, 5);
    if (($result['code'] ?? 1) === 0) {
        $cached = [$docker, 'compose'];
        $cacheKey = $key;
        return $cached;
    }

    $standalone = station_docker_compose_binary();
    $result = station_run_shell_cmd([$standalone, 'version'], null, 5);
    if (($result['code'] ?? 1) === 0) {
        $cached = [$standalone];
        $cacheKey = $key;
        return $cached;
    }

    // Prefer standalone when we resolved a real path (often works when the
    // docker CLI plugin hook is broken but the v2 binary is on disk).
    if ($standalone !== 'docker-compose' && is_file($standalone) && is_executable($standalone)) {
        $cached = [$standalone];
        $cacheKey = $key;
        return $cached;
    }

    // Default to the v2 plugin invocation so error messages mention `docker compose`.
    $cached = [$docker, 'compose'];
    $cacheKey = $key;
    return $cached;
}

/**
 * Check whether the Docker engine itself responds.
 */
function station_docker_engine_available(): array
{
    $docker = station_docker_binary();
    $result = station_run_shell_cmd([$docker, 'info', '--format', '{{.ServerVersion}}'], null, 5);
    $output = trim((string) ($result['output'] ?? ''));
    $ok = ($result['code'] ?? 1) === 0;
    return [
        'ok' => $ok,
        'version' => $ok ? $output : '',
        'output' => $output,
        'binary' => $docker,
    ];
}

/**
 * Diagnostic snapshot of the current docker runtime — what the web server
 * sees right now. Useful when something is misconfigured (PATH, permissions,
 * missing socket, etc).
 */
function station_docker_runtime_diagnostics(): array
{
    $docker = station_docker_binary();
    $compose = station_docker_compose_cli();
    $engine = station_docker_engine_available();

    $whoami = trim((string) (function_exists('posix_getpwuid') && function_exists('posix_geteuid')
        ? (posix_getpwuid(posix_geteuid())['name'] ?? '')
        : (string) (getenv('USER') ?: '')));

    $socketPath = '/var/run/docker.sock';
    $socketExists = file_exists($socketPath);
    $socketReadable = $socketExists && is_readable($socketPath);
    $socketWritable = $socketExists && is_writable($socketPath);

    $isSnap = station_docker_is_snap($docker);
    $runtimeHome = station_docker_runtime_home();
    $inheritedHome = (string) (getenv('HOME') ?: '');

    $composeProbe = station_run_shell_cmd(array_merge($compose, ['version']), null, 8);
    $composeOk = ($composeProbe['code'] ?? 1) === 0;
    $composeProbeOut = trim((string) ($composeProbe['output'] ?? ''));

    return [
        'binary' => $docker,
        'binaryExists' => is_file($docker) || $docker !== 'docker',
        'binaryIsSnap' => $isSnap,
        'composeCommand' => implode(' ', $compose),
        'composeOk' => $composeOk,
        'composeVersionOutput' => $composeProbeOut,
        'runtimePath' => station_docker_runtime_path(),
        'inheritedPath' => (string) (getenv('PATH') ?: ''),
        'runtimeHome' => $runtimeHome,
        'inheritedHome' => $inheritedHome,
        'engineOk' => !empty($engine['ok']),
        'engineVersion' => (string) ($engine['version'] ?? ''),
        'engineOutput' => (string) ($engine['output'] ?? ''),
        'phpUser' => $whoami !== '' ? $whoami : 'unknown',
        'socketExists' => $socketExists,
        'socketReadable' => $socketReadable,
        'socketWritable' => $socketWritable,
    ];
}

/**
 * Run an arbitrary shell command and capture output. Used by docker helpers.
 */
function station_run_shell_cmd(array $command, ?string $cwd = null, int $timeout = 600): array
{
    $commandString = implode(' ', array_map('escapeshellarg', $command));

    $envSnapshot = getenv();
    $env = is_array($envSnapshot) ? $envSnapshot : [];
    $env['PATH'] = station_docker_runtime_path();

    // PHP-FPM typically runs as www-data with HOME=/var/www, which:
    //   - is not writable by www-data, breaking any tool that wants
    //     to drop config files in $HOME (docker CLI, snap, etc.), and
    //   - lives outside /home, which snap refuses outright
    //     ("home directories outside of /home needs configuration").
    // Always redirect to a station-managed dir that we *know* is
    // writable by the web user. Also pin XDG_* to the same root so
    // tools that honor it don't keep poking $HOME anyway.
    $stationHome = station_docker_runtime_home();
    $env['HOME'] = $stationHome;
    $env['XDG_DATA_HOME'] = $stationHome . '/.local/share';
    $env['XDG_CONFIG_HOME'] = $stationHome . '/.config';
    $env['XDG_CACHE_HOME'] = $stationHome . '/.cache';
    $env['XDG_STATE_HOME'] = $stationHome . '/.local/state';

    if (!isset($env['DOCKER_BUILDKIT'])) {
        $env['DOCKER_BUILDKIT'] = '1';
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open($commandString, $descriptors, $pipes, $cwd, $env);
    if (!is_resource($process)) {
        return ['ok' => false, 'code' => -1, 'output' => 'Could not start command: ' . $commandString];
    }

    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    foreach ([1, 2] as $fd) {
        if (isset($pipes[$fd]) && is_resource($pipes[$fd])) {
            stream_set_blocking($pipes[$fd], false);
        }
    }

    $output = '';
    $deadline = microtime(true) + max(5, $timeout);

    while (true) {
        $status = proc_get_status($process);
        $stdoutOpen = isset($pipes[1]) && is_resource($pipes[1]) && !feof($pipes[1]);
        $stderrOpen = isset($pipes[2]) && is_resource($pipes[2]) && !feof($pipes[2]);

        if ($stdoutOpen) {
            $chunk = (string) @fread($pipes[1], 4096);
            if ($chunk !== '') {
                $output .= $chunk;
            }
        }
        if ($stderrOpen) {
            $chunk = (string) @fread($pipes[2], 4096);
            if ($chunk !== '') {
                $output .= $chunk;
            }
        }

        if (!$status['running'] && !$stdoutOpen && !$stderrOpen) {
            break;
        }

        if (microtime(true) > $deadline) {
            @proc_terminate($process, 9);
            $output .= "\n[deployment-station] command timed out after {$timeout}s\n";
            break;
        }

        usleep(80000);
    }

    foreach ([1, 2] as $fd) {
        if (isset($pipes[$fd]) && is_resource($pipes[$fd])) {
            $remaining = (string) @stream_get_contents($pipes[$fd]);
            if ($remaining !== '') {
                $output .= $remaining;
            }
            fclose($pipes[$fd]);
        }
    }

    $code = proc_close($process);
    return [
        'ok' => $code === 0,
        'code' => $code,
        'output' => $output,
    ];
}

/**
 * Run a docker compose command for a project and persist the most recent log output.
 */
function station_run_project_docker_compose(string $projectSlug, array $args, int $timeout = 600): array
{
    $slug = station_safe_name($projectSlug);
    $projectPath = station_projects_dir() . '/' . $slug;
    if (!is_dir($projectPath)) {
        return ['ok' => false, 'code' => -1, 'output' => 'Project not found: ' . $slug];
    }

    $compose = station_docker_compose_cli();
    $command = array_merge($compose, ['-p', station_docker_project_name($slug)], $args);
    $result = station_run_shell_cmd($command, $projectPath, $timeout);
    station_save_project_docker_log($slug, $command, $result);
    return $result;
}

function station_docker_project_name(string $projectSlug): string
{
    $slug = strtolower(preg_replace('/[^a-z0-9_-]+/i', '-', station_safe_name($projectSlug)) ?? 'project');
    $slug = trim($slug, '-_');
    return $slug !== '' ? $slug : 'project';
}

function station_project_docker_log_path(string $projectSlug): string
{
    $dir = station_data_dir() . '/docker-logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir . '/' . station_safe_name($projectSlug) . '.log';
}

function station_save_project_docker_log(string $projectSlug, array $command, array $result): void
{
    $path = station_project_docker_log_path($projectSlug);
    $header = '──── ' . gmdate('c') . ' ────' . "\n";
    $header .= '$ ' . implode(' ', $command) . "\n";
    $body = (string) ($result['output'] ?? '');
    $footer = "\n(exit " . (string) ($result['code'] ?? '?') . ")\n\n";
    @file_put_contents($path, $header . $body . $footer, FILE_APPEND | LOCK_EX);
    // Cap the log file at ~256KB so it doesn't grow without bound.
    if (@filesize($path) > 262144) {
        $contents = (string) @file_get_contents($path);
        $contents = mb_substr($contents, -262144);
        @file_put_contents($path, $contents, LOCK_EX);
    }
}

function station_read_project_docker_log(string $projectSlug, int $maxBytes = 65536): string
{
    $path = station_project_docker_log_path($projectSlug);
    if (!is_file($path)) {
        return '';
    }
    $size = (int) @filesize($path);
    if ($size <= 0) {
        return '';
    }
    $fp = @fopen($path, 'rb');
    if (!is_resource($fp)) {
        return '';
    }
    if ($size > $maxBytes) {
        @fseek($fp, -$maxBytes, SEEK_END);
    }
    $data = (string) @stream_get_contents($fp);
    fclose($fp);
    return $data;
}

/**
 * @return list<string>
 */
function station_compose_filename_candidates(): array
{
    return ['docker-compose.yml', 'docker-compose.yaml', 'compose.yaml', 'compose.yml'];
}

/**
 * First compose file found in the project directory, or null.
 */
function station_find_project_compose_path(string $projectDir): ?string
{
    foreach (station_compose_filename_candidates() as $name) {
        $p = rtrim($projectDir, '/') . '/' . $name;
        if (is_file($p)) {
            return $p;
        }
    }

    return null;
}

/**
 * Absolute path to the compose file Station should use for this project slug.
 */
function station_project_compose_abs_path(string $projectSlug): string
{
    $slug = station_safe_name($projectSlug);
    $dir = station_projects_dir() . '/' . $slug;
    $settings = station_project_settings($slug);
    $docker = isset($settings['docker']) && is_array($settings['docker']) ? $settings['docker'] : [];
    $want = trim((string) ($docker['composeFile'] ?? ''));
    if ($want !== '' && is_file($dir . '/' . $want)) {
        return $dir . '/' . $want;
    }
    $found = station_find_project_compose_path($dir);

    return $found ?? ($dir . '/docker-compose.yml');
}

/**
 * Collect image: lines from a compose YAML (best-effort; does not parse full YAML).
 *
 * @return list<string>
 */
function station_compose_yaml_collect_images(string $yaml): array
{
    $images = [];
    foreach (preg_split('/\r\n|\r|\n/', $yaml) ?: [] as $line) {
        if (preg_match('/^\s*image:\s*(.+)$/i', (string) $line, $m) === 1) {
            $img = trim($m[1], " \t\"'");
            if ($img !== '' && !str_contains($img, '${')) {
                $images[] = $img;
            }
        }
    }

    return array_values(array_unique($images));
}

/**
 * Map a compose image reference to a Station docker service catalog key, if known.
 */
function station_compose_image_to_station_service_key(string $image): ?string
{
    $l = strtolower($image);
    $pairs = [
        'mysql' => 'mysql',
        'mariadb' => 'mariadb',
        'postgres' => 'postgres',
        'postgis' => 'postgres',
        'mongo' => 'mongodb',
        'redis' => 'redis',
        'elastic' => 'elasticsearch',
        'rabbitmq' => 'rabbitmq',
        'meilisearch' => 'meilisearch',
        'minio' => 'minio',
        'mailpit' => 'mailpit',
        'memcached' => 'memcached',
        'clickhouse' => 'clickhouse',
        'influxdb' => 'influxdb',
        'adminer' => 'adminer',
        'mongo-express' => 'mongo-express',
        'pgadmin' => 'pgadmin',
        'caddy' => 'caddy',
        'grafana' => 'grafana',
        'prometheus' => 'prometheus',
    ];
    foreach ($pairs as $needle => $key) {
        if (str_contains($l, $needle)) {
            return $key;
        }
    }

    return null;
}

/**
 * Append unique compose image strings to admin dockerSettings for operator visibility.
 *
 * @param list<string> $images
 */
function station_admin_merge_discovered_compose_images(array $images): void
{
    if ($images === []) {
        return;
    }
    $admin = station_admin_settings();
    $ds = isset($admin['dockerSettings']) && is_array($admin['dockerSettings']) ? $admin['dockerSettings'] : [];
    $known = isset($ds['discoveredComposeImages']) && is_array($ds['discoveredComposeImages']) ? $ds['discoveredComposeImages'] : [];
    foreach ($images as $img) {
        $img = trim((string) $img);
        if ($img === '' || str_contains($img, '${')) {
            continue;
        }
        if (!in_array($img, $known, true)) {
            $known[] = $img;
        }
    }
    if (count($known) > 300) {
        $known = array_slice($known, -300);
    }
    $ds['discoveredComposeImages'] = $known;
    $admin['dockerSettings'] = $ds;
    station_save_admin_settings($admin);
}

/**
 * If the workspace already contains a compose file, mark the project as using that stack
 * (Station will not overwrite compose on start) and register discovered images for the admin catalog.
 */
function station_try_bootstrap_native_docker_from_workspace(string $slug): void
{
    if (!station_docker_enabled()) {
        return;
    }
    $slug = station_safe_name($slug);
    if ($slug === '') {
        return;
    }
    $projectPath = station_projects_dir() . '/' . $slug;
    $composePath = station_find_project_compose_path($projectPath);
    if ($composePath === null) {
        return;
    }

    $settings = station_project_settings($slug);
    $docker = isset($settings['docker']) && is_array($settings['docker']) ? $settings['docker'] : [];
    if (!empty($docker['nativeCompose'])) {
        return;
    }
    if (!empty($docker['containerized']) && (int) ($docker['hostPort'] ?? 0) > 0) {
        return;
    }

    $yaml = (string) @file_get_contents($composePath);
    if (trim($yaml) === '') {
        return;
    }

    $images = station_compose_yaml_collect_images($yaml);
    station_admin_merge_discovered_compose_images($images);

    $recommended = [];
    foreach ($images as $img) {
        $key = station_compose_image_to_station_service_key($img);
        if ($key !== null) {
            $recommended[] = $key;
        }
    }
    $recommended = array_values(array_unique($recommended));

    $servicesMap = [];
    foreach ($recommended as $k) {
        $servicesMap[$k] = true;
    }

    $settings['docker'] = array_merge($docker, [
        'containerized' => true,
        'nativeCompose' => true,
        'composeFile' => basename($composePath),
        'hostPort' => (int) ($docker['hostPort'] ?? 0) > 0 ? (int) $docker['hostPort'] : station_allocate_project_host_port($slug),
        'appPort' => (int) ($docker['appPort'] ?? 0) > 0 ? (int) $docker['appPort'] : 80,
        'services' => $servicesMap !== [] ? $servicesMap : (isset($docker['services']) && is_array($docker['services']) ? $docker['services'] : []),
        'credentials' => isset($docker['credentials']) && is_array($docker['credentials']) ? $docker['credentials'] : [],
        'recommendedServices' => $recommended,
        'stack' => trim((string) ($docker['stack'] ?? '')) !== '' ? (string) $docker['stack'] : station_infer_docker_stack($slug),
    ]);
    station_save_project_settings($slug, $settings);
}

/**
 * Inspect the current docker-compose state for a project: returns one of
 * 'running', 'partial', 'stopped', 'unknown', 'unavailable', 'unconfigured'.
 */
function station_project_docker_status(string $projectSlug): array
{
    $slug = station_safe_name($projectSlug);
    $projectPath = station_projects_dir() . '/' . $slug;
    $composePath = station_project_compose_abs_path($slug);
    if (!is_file($composePath)) {
        return ['state' => 'unconfigured', 'services' => [], 'output' => ''];
    }

    if (!station_docker_enabled()) {
        return ['state' => 'unconfigured', 'services' => [], 'output' => ''];
    }

    $engine = station_docker_engine_available();
    if (empty($engine['ok'])) {
        return [
            'state' => 'unavailable',
            'services' => [],
            'output' => trim((string) ($engine['output'] ?? 'Docker daemon is not reachable.')),
        ];
    }

    $compose = station_docker_compose_cli();
    $command = array_merge($compose, ['-p', station_docker_project_name($slug), 'ps', '--format', 'json']);
    $result = station_run_shell_cmd($command, $projectPath, 15);
    if (($result['code'] ?? 1) !== 0) {
        return [
            'state' => 'unknown',
            'services' => [],
            'output' => trim((string) ($result['output'] ?? '')),
        ];
    }

    $rawOutput = trim((string) ($result['output'] ?? ''));
    $services = [];
    if ($rawOutput !== '') {
        $first = ltrim($rawOutput)[0] ?? '';
        if ($first === '[') {
            $decoded = json_decode($rawOutput, true);
            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    if (is_array($entry)) {
                        $services[] = $entry;
                    }
                }
            }
        } else {
            foreach (preg_split('/\r\n|\n/', $rawOutput) ?: [] as $line) {
                $line = trim((string) $line);
                if ($line === '' || $line[0] !== '{') {
                    continue;
                }
                $entry = json_decode($line, true);
                if (is_array($entry)) {
                    $services[] = $entry;
                }
            }
        }
    }

    if ($services === []) {
        return ['state' => 'stopped', 'services' => [], 'output' => $rawOutput];
    }

    $running = 0;
    $total = 0;
    foreach ($services as $service) {
        $total++;
        $state = strtolower((string) ($service['State'] ?? $service['state'] ?? ''));
        if ($state === 'running') {
            $running++;
        }
    }

    if ($total === 0) {
        $state = 'stopped';
    } elseif ($running === $total) {
        $state = 'running';
    } elseif ($running > 0) {
        $state = 'partial';
    } else {
        $state = 'stopped';
    }

    return ['state' => $state, 'services' => $services, 'output' => $rawOutput];
}

/**
 * Directory that holds the auto-generated nginx include file for dockerized
 * projects. Created with mode 0755 because nginx worker processes typically
 * run as a different system user (www-data / nginx) than PHP-FPM and still
 * need to read the include file.
 */
function station_nginx_include_dir(): string
{
    $dir = station_data_dir() . '/nginx';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    } elseif ((@fileperms($dir) & 0777) !== 0755) {
        @chmod($dir, 0755);
    }
    return $dir;
}

/**
 * Absolute path to the nginx include file. Operators add one
 * `include <this-path>;` directive inside their server { } block and the
 * station regenerates the file every time the docker fleet changes.
 */
function station_nginx_include_path(): string
{
    return station_nginx_include_dir() . '/projects.conf';
}

/**
 * True when the auto-generated nginx include contains a reverse-proxy route for
 * this project slug. Used by launch.php to avoid redirecting to /p/slug/
 * when nginx has not picked up the include yet (which would fall through to
 * the site root and show the wrong page).
 */
function station_nginx_proxy_route_present_for_slug(string $slug): bool
{
    $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', station_safe_name($slug));
    if ($slug === '') {
        return false;
    }
    $path = station_nginx_include_path();
    if (!is_readable($path)) {
        return false;
    }
    $body = (string) @file_get_contents($path);
    if ($body === '') {
        return false;
    }
    $needle = 'location ^~ /p/' . $slug . '/';
    return str_contains($body, $needle);
}

/**
 * Walk every project on disk and return a normalized struct for each one that
 * is set up as a containerized docker project with a real host port.
 *
 * @return array<int,array{slug:string,hostPort:int,appPort:int,containerized:bool}>
 */
function station_collect_dockerized_projects(): array
{
    if (!function_exists('station_list_projects')) {
        require_once __DIR__ . '/projects.php';
    }

    $out = [];
    foreach (station_list_projects() as $project) {
        $slug = station_safe_name((string) ($project['slug'] ?? ''));
        if ($slug === '') {
            continue;
        }
        $settings = station_project_settings($slug);
        $docker = isset($settings['docker']) && is_array($settings['docker']) ? $settings['docker'] : [];
        $containerized = !empty($docker['containerized']);
        $hostPort = (int) ($docker['hostPort'] ?? 0);
        $appPort = (int) ($docker['appPort'] ?? 0);
        if (!$containerized || $hostPort <= 0) {
            continue;
        }
        $out[] = [
            'slug' => $slug,
            'hostPort' => $hostPort,
            'appPort' => $appPort > 0 ? $appPort : 80,
            'containerized' => true,
        ];
    }
    return $out;
}

/**
 * Read-only snapshot of host paths and web-server settings for the admin UI.
 *
 * @return array<string, mixed>
 */
function station_admin_host_config_snapshot(): array
{
    $settings = station_admin_settings();
    $nginxCmd = trim((string) ($settings['nginxReloadCommand'] ?? ''));
    $envData = getenv('STATION_DATA_DIR');

    return [
        'stationDataDir' => station_data_dir(),
        'stationDataDirEnv' => is_string($envData) && trim($envData) !== '' ? trim($envData) : '',
        'projectsDir' => station_projects_dir(),
        'stationPhpDir' => station_base_dir(),
        'webBasePath' => station_web_base_path(),
        'serverInfrastructure' => station_normalize_server_infrastructure((string) ($settings['serverInfrastructure'] ?? 'apache')),
        'nginxIncludePath' => station_nginx_include_path(),
        'nginxReloadAfterRoutes' => station_normalize_server_infrastructure((string) ($settings['serverInfrastructure'] ?? 'apache')) === 'nginx',
        'nginxReloadCommandConfigured' => $nginxCmd !== '',
    ];
}

/**
 * Bounded `docker ps -a` table for admin overview.
 *
 * @return array{ok: bool, output: string, truncated: bool}
 */
function station_docker_global_ps(int $maxBytes = 100000): array
{
    $docker = station_docker_binary();
    $engine = station_docker_engine_available();
    if (empty($engine['ok'])) {
        return ['ok' => false, 'output' => 'Docker engine is not reachable from PHP.', 'truncated' => false];
    }
    $result = station_run_shell_cmd([
        $docker,
        'ps',
        '-a',
        '--format',
        'table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}',
    ], null, 25);
    $out = trim((string) ($result['output'] ?? ''));
    $truncated = mb_strlen($out) > $maxBytes;
    if ($truncated) {
        $out = mb_substr($out, 0, $maxBytes) . "\n... (truncated)\n";
    }

    return [
        'ok' => ($result['code'] ?? 1) === 0,
        'output' => $out !== '' ? $out : '(no output)',
        'truncated' => $truncated,
    ];
}

/**
 * Per-project docker status for each containerized project (admin fleet table).
 *
 * @return list<array{slug: string, hostPort: int, appPort: int, state: string, composePath: string}>
 */
function station_admin_containerized_project_rows(): array
{
    $rows = [];
    foreach (station_collect_dockerized_projects() as $p) {
        $slug = (string) ($p['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        $st = station_project_docker_status($slug);
        $rows[] = [
            'slug' => $slug,
            'hostPort' => (int) ($p['hostPort'] ?? 0),
            'appPort' => (int) ($p['appPort'] ?? 80),
            'state' => (string) ($st['state'] ?? 'unknown'),
            'composePath' => station_projects_dir() . '/' . $slug . '/docker-compose.yml',
        ];
    }

    return $rows;
}

/**
 * Owner-only: restart or force-recreate every containerized project stack, then
 * regenerate the nginx projects include.
 *
 * @param 'restart'|'recreate' $bulkAction
 * @return array{ok: bool, message: string, results: array<string, array{ok: bool, output: string, code: int}>, nginx_include: array<string, mixed>}
 */
function station_admin_bulk_docker_projects(string $bulkAction): array
{
    if ($bulkAction !== 'restart' && $bulkAction !== 'recreate') {
        return [
            'ok' => false,
            'message' => 'Invalid bulk action.',
            'results' => [],
            'nginx_include' => ['ok' => false, 'message' => 'skipped'],
        ];
    }
    if (!station_docker_enabled()) {
        return [
            'ok' => false,
            'message' => 'Docker deployment is disabled.',
            'results' => [],
            'nginx_include' => ['ok' => false, 'message' => 'skipped'],
        ];
    }
    $engine = station_docker_engine_available();
    if (empty($engine['ok'])) {
        return [
            'ok' => false,
            'message' => 'Docker engine is not reachable.',
            'results' => [],
            'nginx_include' => ['ok' => false, 'message' => 'skipped'],
        ];
    }

    $args = $bulkAction === 'restart' ? ['restart'] : ['up', '-d', '--force-recreate'];
    $timeout = $bulkAction === 'restart' ? 420 : 900;
    $results = [];
    $anyOk = false;
    $anyFail = false;

    foreach (station_collect_dockerized_projects() as $p) {
        $slug = (string) ($p['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        $projectPath = station_projects_dir() . '/' . $slug;
        $composePath = $projectPath . '/docker-compose.yml';
        $projectSettings = station_project_settings($slug);
        $dockerConfig = isset($projectSettings['docker']) && is_array($projectSettings['docker']) ? $projectSettings['docker'] : [];
        if ($dockerConfig === []) {
            $results[$slug] = ['ok' => false, 'output' => 'Missing docker settings.', 'code' => -1];
            $anyFail = true;
            continue;
        }
        if (!isset($dockerConfig['hostPort']) || (int) $dockerConfig['hostPort'] <= 0) {
            $dockerConfig['hostPort'] = station_allocate_project_host_port($slug);
            $projectSettings['docker'] = $dockerConfig;
            station_save_project_settings($slug, $projectSettings);
        }
        station_merge_station_entries_into_project_dockerignore($projectPath);
        $composeContent = station_generate_docker_compose($dockerConfig, $slug);
        if (@file_put_contents($composePath, $composeContent, LOCK_EX) === false) {
            $results[$slug] = ['ok' => false, 'output' => 'Could not write docker-compose.yml', 'code' => -1];
            $anyFail = true;
            continue;
        }

        $run = station_run_project_docker_compose($slug, $args, $timeout);
        $code = (int) ($run['code'] ?? -1);
        $ok = !empty($run['ok']);
        $results[$slug] = [
            'ok' => $ok,
            'output' => trim((string) ($run['output'] ?? '')),
            'code' => $code,
        ];
        if ($ok) {
            $anyOk = true;
            station_log_event('project.docker.' . $bulkAction . '.bulk', ['project' => $slug, 'exit' => $code]);
        } else {
            $anyFail = true;
        }
    }

    $nginxInclude = station_write_nginx_projects_conf();
    if (empty($nginxInclude['ok'])) {
        station_log_event('nginx.include.failed', [
            'phase' => 'admin-bulk-docker-' . $bulkAction,
            'message' => (string) ($nginxInclude['message'] ?? ''),
        ]);
    }

    $n = count($results);
    if ($n === 0) {
        return [
            'ok' => true,
            'message' => 'No containerized projects to operate on.',
            'results' => [],
            'nginx_include' => $nginxInclude,
        ];
    }

    $verb = $bulkAction === 'restart' ? 'Restarted' : 'Recreated';
    $summary = $verb . ' ' . $n . ' stack(s). ';
    if ($anyFail && $anyOk) {
        $summary .= 'Some commands failed — check per-project Docker logs.';
    } elseif ($anyFail) {
        $summary .= 'All compose commands failed.';
    } else {
        $summary .= 'All compose commands succeeded.';
    }
    $summary .= station_nginx_include_reload_hint_for_flash($nginxInclude);
    $reload = $nginxInclude['reload'] ?? null;
    if (!empty($nginxInclude['ok']) && is_array($reload) && empty($reload['skipped']) && empty($reload['ok'])) {
        $summary .= ' ' . station_nginx_include_reload_warning_detail($reload);
    }

    $failed = [];
    foreach ($results as $slug => $row) {
        if (empty($row['ok'])) {
            $failed[] = $slug;
        }
    }
    if ($failed !== []) {
        $summary .= ' Failed slugs: ' . implode(', ', $failed) . '.';
    }

    return [
        'ok' => !$anyFail,
        'message' => $summary,
        'results' => $results,
        'nginx_include' => $nginxInclude,
        'compose_all_failed' => $n > 0 && !$anyOk,
        'compose_partial_failed' => $anyFail && $anyOk,
    ];
}

/**
 * Nginx proxy lines for Host / X-Forwarded-Host toward dockerized apps.
 *
 * @return list<string>
 */
function station_nginx_docker_proxy_host_header_lines(int $hostPort): array
{
    $settings = station_admin_settings();
    $mode = strtolower(trim((string) ($settings['nginxDockerUpstreamHostMode'] ?? 'preserve')));
    if ($mode === 'loopback') {
        return [
            '    proxy_set_header Host 127.0.0.1:' . $hostPort . ';',
            '    proxy_set_header X-Forwarded-Host $http_host;',
        ];
    }

    return [
        '    proxy_set_header Host $host;',
        '    proxy_set_header X-Forwarded-Host $http_host;',
    ];
}

/**
 * Render the body of `projects.conf` (one nginx `location` block per
 * dockerized project) from the collected project list.
 */
function station_render_nginx_projects_conf(array $projects): string
{
    $generatedAt = gmdate('c');
    $lines = [
        '# Auto-generated by Deployment Station — managed file.',
        '# Regenerated whenever a docker project is configured, started,',
        '# stopped, rebuilt, or torn down. Manual edits will be lost.',
        '# Each /p/<slug>/ block reverse-proxies to the container (no Station auth_request).',
        '# Browser Cookie/Authorization are cleared before proxy_pass (avoids many app 500s when',
        '# the Station session is present). Optional ?dp_t= query is ignored here unless you add',
        '# your own validation — use lib/docker-proxy-token.php from custom tooling if needed.',
        '# Large header buffers: nginx only allows client_header_buffer_size /',
        '# large_client_header_buffers in http{} or server{}, not inside location{}. If you',
        '# still see 400/494 on /p/… with huge cookies, set those directives in this vhost\'s',
        '# server{} (see Admin → Projects → nginx snippet comments).',
        '# Generated at ' . $generatedAt,
        '',
    ];

    if ($projects === []) {
        $lines[] = '# No dockerized projects yet — this file is intentionally empty.';
        $lines[] = '# Once a project is configured for Docker the route blocks will appear below.';
        return implode("\n", $lines) . "\n";
    }

    // One location per slug: last duplicate wins (avoids nginx merging two ^~ blocks where
    // the second can override proxy settings).
    $bySlug = [];
    foreach ($projects as $project) {
        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($project['slug'] ?? ''));
        if ($slug === null || $slug === '') {
            continue;
        }
        $bySlug[$slug] = $project;
    }

    foreach ($bySlug as $slug => $project) {
        $hostPort = max(1, min(65535, (int) ($project['hostPort'] ?? 0)));
        $appPort = max(1, min(65535, (int) ($project['appPort'] ?? 80)));

        $lines[] = '# ' . $slug . ' -> host 127.0.0.1:' . $hostPort . ' (container :' . $appPort . ')';
        $lines[] = 'location ^~ /p/' . $slug . '/ {';
        $lines[] = '    proxy_http_version 1.1;';
        $lines[] = '    proxy_set_header Connection "";';
        foreach (station_nginx_docker_proxy_host_header_lines($hostPort) as $hostLine) {
            $lines[] = $hostLine;
        }
        // Always strip: forwarding the Station session cookie (and Authorization) to the
        // container often breaks the app or causes 500 for signed-in users while anonymous works.
        $lines[] = '    # Do not forward browser Cookie / Authorization to the container.';
        $lines[] = '    proxy_set_header Cookie "";';
        $lines[] = '    proxy_set_header Authorization "";';
        $lines[] = '    proxy_set_header X-Real-IP $remote_addr;';
        $lines[] = '    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;';
        $lines[] = '    proxy_set_header X-Forwarded-Proto $scheme;';
        $lines[] = '    proxy_set_header X-Forwarded-Prefix /p/' . $slug . ';';
        $lines[] = '    proxy_read_timeout 300s;';
        $lines[] = '    add_header Cache-Control "private, no-store, max-age=0" always;';
        $lines[] = '    add_header X-Station-Docker-Project "' . $slug . '" always;';
        $lines[] = '    proxy_pass http://127.0.0.1:' . $hostPort . '/;';
        $lines[] = '}';
        $lines[] = '';
    }

    return implode("\n", $lines);
}

/**
 * Strip directives that older Station builds incorrectly placed inside location{}
 * (nginx only allows these in http{} or server{}).
 */
function station_sanitize_legacy_projects_conf_directives(string $body): string
{
    $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $trim = ltrim($line);
        if (preg_match('/^(client_header_buffer_size|large_client_header_buffers)\s+/i', $trim)) {
            continue;
        }
        if (preg_match('/^#\s*Allow large browser Cookie headers/i', $trim)) {
            continue;
        }
        $out[] = $line;
    }

    return implode("\n", $out);
}

/**
 * Run a bounded shell command (used for optional `nginx -t` + reload).
 *
 * @return array{ok:bool,code:int,output:string,message:string}
 */
function station_run_shell_command(string $command, int $timeoutSeconds = 60): array
{
    $command = trim($command);
    if ($command === '') {
        return ['ok' => false, 'code' => -1, 'output' => '', 'message' => 'Empty command.'];
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $proc = @proc_open(['/usr/bin/env', 'bash', '-lc', $command], $descriptors, $pipes, null, null);
    if (!is_resource($proc)) {
        return ['ok' => false, 'code' => -1, 'output' => '', 'message' => 'Could not start shell subprocess.'];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start = microtime(true);

    while (true) {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);

        $status = proc_get_status($proc);
        if (!$status['running']) {
            break;
        }

        if (microtime(true) - $start > $timeoutSeconds) {
            proc_terminate($proc, 15);
            usleep(150000);
            if (proc_get_status($proc)['running']) {
                proc_terminate($proc, 9);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);

            return [
                'ok' => false,
                'code' => -2,
                'output' => trim($stdout . "\n--- stderr ---\n" . $stderr),
                'message' => 'Shell command timed out after ' . $timeoutSeconds . ' seconds.',
            ];
        }

        usleep(50000);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $combined = trim($stdout . ($stderr !== '' ? "\n--- stderr ---\n" . $stderr : ''));

    return [
        'ok' => $code === 0,
        'code' => $code,
        'output' => $combined,
        'message' => $code === 0 ? 'Exit 0.' : ('Exit code ' . $code),
    ];
}

/**
 * When Admin → Projects enables it, run `nginx -t` + reload after the
 * managed `projects.conf` file changes so the main nginx picks up new routes
 * without a manual `systemctl reload nginx`.
 *
 * @return array{ok:bool,skipped?:bool,code?:int,output?:string,message:string}
 */
function station_nginx_maybe_reload_main(): array
{
    $settings = station_admin_settings();
    if (station_normalize_server_infrastructure((string) ($settings['serverInfrastructure'] ?? 'apache')) !== 'nginx') {
        return ['ok' => true, 'skipped' => true, 'message' => 'Skipped: production web server is not Nginx.'];
    }

    $cmd = station_admin_resolved_shell_command($settings, 'nginxReloadCommand');
    $result = station_run_shell_command($cmd, 90);
    if (!empty($result['ok'])) {
        station_log_event('nginx.reload.ok', [
            'output' => mb_substr((string) ($result['output'] ?? ''), 0, 800),
        ]);
    } else {
        station_log_event('nginx.reload.failed', [
            'code' => (int) ($result['code'] ?? -1),
            'output' => mb_substr((string) ($result['output'] ?? ''), 0, 2000),
        ]);
    }

    return $result;
}

/**
 * Human-readable reload failure for warning-tier flash (routes file already written).
 *
 * @param array{ok?:bool,skipped?:bool,output?:string,message?:string} $reload
 */
function station_nginx_include_reload_warning_detail(array $reload): string
{
    $detail = trim(mb_substr((string) ($reload['output'] ?? ''), 0, 600));
    $tail = $detail !== '' ? (' Output: ' . $detail) : (' ' . trim((string) ($reload['message'] ?? '')));

    return 'Nginx configuration test or reload failed after updating routes. Fix the reload command under Admin → Projects, or run nginx -t and reload manually.' . $tail;
}

/**
 * Short success / skip suffix for flash messages (never includes reload failure text;
 * use station_nginx_include_reload_warning_detail + a warning-tier flash for that).
 */
function station_nginx_include_reload_hint_for_flash(array $includeResult): string
{
    if (empty($includeResult['ok'])) {
        return '';
    }

    if (array_key_exists('changed', $includeResult) && $includeResult['changed'] === false) {
        return ' projects.conf was already current; nginx reload skipped.';
    }

    $hint = ' Nginx routes file was updated.';
    $r = $includeResult['reload'] ?? null;
    if (!is_array($r)) {
        return $hint;
    }

    if (!empty($r['skipped'])) {
        return $hint . ' Nginx reload is skipped in Apache mode.';
    }

    if (!empty($r['ok'])) {
        return $hint . ' Nginx configuration test and reload succeeded.';
    }

    return $hint;
}

/**
 * Set ok / warning / error flashes after projects.conf write + optional nginx reload.
 *
 * @param bool $treatNginxWriteFailureAsError When true, a failed include write is an error flash. When false, keep $contextLine as ok and add a warning (e.g. deploy succeeded but routes were not written).
 */
function station_flash_nginx_include_outcome(string $contextLine, array $includeResult, bool $treatNginxWriteFailureAsError = true): void
{
    if (empty($includeResult['ok'])) {
        $detail = trim((string) ($includeResult['message'] ?? 'Unknown error'));
        if ($treatNginxWriteFailureAsError) {
            station_flash_set('error', $contextLine . ' ' . $detail);
        } else {
            station_flash_set('ok', $contextLine);
            station_flash_set('warning', 'Nginx routes file was not updated: ' . $detail);
        }

        return;
    }

    $reload = $includeResult['reload'] ?? null;
    $suffix = station_nginx_include_reload_hint_for_flash($includeResult);
    if (!is_array($reload) || !empty($reload['skipped']) || !empty($reload['ok'])
        || (array_key_exists('changed', $includeResult) && $includeResult['changed'] === false)) {
        station_flash_set('ok', $contextLine . $suffix);

        return;
    }

    station_flash_set('ok', $contextLine . $suffix);
    station_flash_set('warning', station_nginx_include_reload_warning_detail($reload));
}

/**
 * Regenerate `projects.conf` after a project is deleted, archived, or
 * restored so `/p/<slug>/` routes match disk + settings.
 */
function station_touch_nginx_routes_after_project_mutation(): void
{
    $r = station_write_nginx_projects_conf();
    if (empty($r['ok'])) {
        station_log_event('nginx.include.failed', [
            'phase' => 'project-mutation',
            'message' => (string) ($r['message'] ?? ''),
        ]);
    }
}

/**
 * Atomically (re)write the nginx include file at
 * station_nginx_include_path(). Failures are reported via the return array
 * but never thrown — callers must continue to function even if nginx isn't
 * present or the data directory isn't writable.
 */
function station_write_nginx_projects_conf(): array
{
    $path = station_nginx_include_path();
    $dir = dirname($path);

    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return [
            'ok' => false,
            'path' => $path,
            'count' => 0,
            'message' => 'Could not create nginx include directory at ' . $dir,
        ];
    }

    try {
        $projects = station_collect_dockerized_projects();
    } catch (\Throwable $e) {
        return [
            'ok' => false,
            'path' => $path,
            'count' => 0,
            'message' => 'Failed to collect dockerized projects: ' . $e->getMessage(),
        ];
    }

    $contents = station_sanitize_legacy_projects_conf_directives(station_render_nginx_projects_conf($projects));
    $previous = '';
    if (is_file($path) && is_readable($path)) {
        $previous = (string) (@file_get_contents($path) ?: '');
    }
    if ($previous === $contents) {
        return [
            'ok' => true,
            'path' => $path,
            'count' => count($projects),
            'changed' => false,
            'message' => 'projects.conf is already up to date.',
            'reload' => [
                'ok' => true,
                'skipped' => true,
                'message' => 'Unchanged; nginx reload skipped.',
            ],
        ];
    }

    $tmpPath = $path . '.tmp';
    $bytes = @file_put_contents($tmpPath, $contents, LOCK_EX);
    if ($bytes === false) {
        return [
            'ok' => false,
            'path' => $path,
            'count' => count($projects),
            'message' => 'Could not write temporary include file at ' . $tmpPath,
        ];
    }
    @chmod($tmpPath, 0644);

    if (!@rename($tmpPath, $path)) {
        @unlink($tmpPath);
        return [
            'ok' => false,
            'path' => $path,
            'count' => count($projects),
            'message' => 'Could not atomically rename temp file to ' . $path,
        ];
    }

    @chmod($path, 0644);

    $result = [
        'ok' => true,
        'path' => $path,
        'count' => count($projects),
        'message' => count($projects) === 0
            ? 'No dockerized projects yet — wrote empty include file.'
            : 'Wrote nginx include with ' . count($projects) . ' route block(s).',
        'changed' => true,
        'reload' => station_nginx_maybe_reload_main(),
    ];

    return $result;
}

/**
 * Encrypt and store credentials
 */
function station_encrypt_credentials(array $credentials, string $projectSlug): bool
{
    $credPath = station_data_dir() . '/project-credentials/' . station_safe_name($projectSlug) . '.json';
    @mkdir(dirname($credPath), 0700, true);

    $encrypted = station_encrypt_data($credentials);
    return (bool) @file_put_contents($credPath, json_encode($encrypted, JSON_PRETTY_PRINT) ?: '{}');
}

/**
 * Decrypt and retrieve credentials
 */
function station_decrypt_credentials(string $projectSlug): array
{
    $credPath = station_data_dir() . '/project-credentials/' . station_safe_name($projectSlug) . '.json';

    if (!file_exists($credPath)) {
        return [];
    }

    $content = file_get_contents($credPath);
    $encrypted = json_decode((string) $content, true) ?: [];

    return station_decrypt_data($encrypted) ?? [];
}

/**
 * Simple encryption (should use a proper crypto library in production)
 * For now, using base64 for obfuscation
 */
function station_encrypt_data(array $data): array
{
    return [
        'data' => base64_encode(json_encode($data) ?: ''),
        'algorithm' => 'base64',
    ];
}

/**
 * Simple decryption
 */
function station_decrypt_data(array $encrypted): ?array
{
    if (($encrypted['algorithm'] ?? '') !== 'base64') {
        return null;
    }

    $json = base64_decode($encrypted['data'] ?? '', true);
    return json_decode((string) $json, true);
}
