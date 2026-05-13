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
            'name' => 'MySQL / MariaDB',
            'icon' => '🐬',
            'description' => 'Relational database with MariaDB compatibility',
            'versions' => ['8.0', '5.7', 'mariadb-11', 'mariadb-10.6'],
            'defaultVersion' => '8.0',
            'requiredEnvVars' => [
                'MYSQL_ROOT_PASSWORD' => 'Root password for database',
                'MYSQL_DATABASE' => 'Default database name (optional)',
                'MYSQL_USER' => 'Database user (optional)',
                'MYSQL_PASSWORD' => 'Database user password (optional)',
            ],
        ],
        'postgres' => [
            'name' => 'PostgreSQL',
            'icon' => '🐘',
            'description' => 'Advanced open-source relational database',
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
            'description' => 'In-memory data store for caching and sessions',
            'versions' => ['7', '6'],
            'defaultVersion' => '7',
            'requiredEnvVars' => [
                'REDIS_PASSWORD' => 'Optional password',
            ],
        ],
        'mongodb' => [
            'name' => 'MongoDB',
            'icon' => '🍃',
            'description' => 'NoSQL document database',
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
            'description' => 'Search and analytics engine',
            'versions' => ['8.13.0', '8.0.0', '7.17.0'],
            'defaultVersion' => '8.13.0',
            'requiredEnvVars' => [
                'ELASTIC_USERNAME' => 'Username (default: elastic)',
                'ELASTIC_PASSWORD' => 'Password for elastic user',
            ],
        ],
        'rabbitmq' => [
            'name' => 'RabbitMQ',
            'icon' => '🐢',
            'description' => 'Message broker for asynchronous communication',
            'versions' => ['3.13-management', '3.12-management', '3.11-management'],
            'defaultVersion' => '3.13-management',
            'requiredEnvVars' => [
                'RABBITMQ_DEFAULT_USER' => 'Default RabbitMQ username',
                'RABBITMQ_DEFAULT_PASS' => 'Default RabbitMQ password',
                'RABBITMQ_DEFAULT_VHOST' => 'Default virtual host (optional)',
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
    if (is_file($dockerfilePath) && !$force) {
        return;
    }

    $dockerfile = station_build_project_dockerfile($projectPath);
    @file_put_contents($dockerfilePath, $dockerfile, LOCK_EX);

    $ignorePath = $projectPath . '/.dockerignore';
    if (!is_file($ignorePath)) {
        @file_put_contents($ignorePath, station_default_dockerignore(), LOCK_EX);
    }
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
IGNORE;
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
FROM node:20-alpine
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
FROM python:3.12-slim
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
        return <<<'DOCKER'
FROM php:8.3-apache
RUN a2enmod rewrite
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html
EXPOSE 80
DOCKER;
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
function station_generate_docker_compose(array $projectConfig): string
{
    $services = [];
    $appPort = station_normalize_docker_port($projectConfig['appPort'] ?? 80);
    $hostPort = station_normalize_docker_port($projectConfig['hostPort'] ?? $appPort, $appPort);
    $appVolumes = [];
    if (!empty($projectConfig['devMount'])) {
        $appVolumes[] = '.:/app';
        if ($appPort === 3000) {
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
                $projectConfig['credentials'][$serviceKey] ?? []
            );
            $enabledServiceKeys[] = $serviceKey;
        }
    }

    $appService = [
        'build' => [
            'context' => '.',
            'dockerfile' => 'Dockerfile',
        ],
        'restart' => 'unless-stopped',
        'ports' => [$hostPort . ':' . $appPort],
        'environment' => array_merge(['PORT' => (string) $appPort], station_build_app_env_vars($projectConfig)),
        'networks' => ['app-network'],
    ];
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
function station_build_service_compose_block(string $serviceKey, array $serviceConfig, array $credentials = []): array
{
    $block = [
        'image' => station_docker_service_image($serviceKey, (string) ($credentials['version'] ?? $serviceConfig['defaultVersion'])),
        'restart' => 'unless-stopped',
        'networks' => ['app-network'],
    ];

    $env = station_docker_service_env_vars($serviceKey, $credentials);
    if ($env) {
        $block['environment'] = $env;
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
 * Get Docker image string for a service
 */
function station_docker_service_image(string $serviceKey, string $version): string
{
    $images = [
        'mysql' => 'mysql',
        'postgres' => 'postgres',
        'redis' => 'redis',
        'mongodb' => 'mongo',
        'elasticsearch' => 'docker.elastic.co/elasticsearch/elasticsearch',
        'rabbitmq' => 'rabbitmq',
    ];

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
        'postgres' => ['postgres-data:/var/lib/postgresql/data'],
        'mongodb' => ['mongodb-data:/data/db'],
        'redis' => ['redis-data:/data'],
        'elasticsearch' => ['elasticsearch-data:/usr/share/elasticsearch/data'],
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
    }

    return $env;
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
        return (string) $value;
    }
    $value = (string) $value;

    if ($value === '' ||
        in_array(strtolower($value), ['true', 'false', 'null', 'yes', 'no', 'on', 'off'], true) ||
        preg_match('/^\d+$/', $value) ||
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
 * Resolve the standalone docker-compose binary path (only used as a fallback
 * when the v2 compose plugin is not installed).
 */
function station_docker_compose_binary(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $runtimePath = station_docker_runtime_path();
    $shellCheck = @shell_exec('PATH=' . escapeshellarg($runtimePath) . ' command -v docker-compose 2>/dev/null');
    if (is_string($shellCheck)) {
        $candidate = trim($shellCheck);
        if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
            $cached = $candidate;
            return $cached;
        }
    }
    foreach (['/usr/local/bin/docker-compose', '/opt/homebrew/bin/docker-compose', '/usr/bin/docker-compose'] as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            $cached = $candidate;
            return $cached;
        }
    }
    $cached = 'docker-compose';
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
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $docker = station_docker_binary();

    $result = station_run_shell_cmd([$docker, 'compose', 'version'], null, 5);
    if (($result['code'] ?? 1) === 0) {
        $cached = [$docker, 'compose'];
        return $cached;
    }

    $standalone = station_docker_compose_binary();
    $result = station_run_shell_cmd([$standalone, 'version'], null, 5);
    if (($result['code'] ?? 1) === 0) {
        $cached = [$standalone];
        return $cached;
    }

    // Default to the v2 invocation even if the probe failed, so errors are clearer.
    $cached = [$docker, 'compose'];
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

    return [
        'binary' => $docker,
        'binaryExists' => is_file($docker) || $docker !== 'docker',
        'composeCommand' => implode(' ', $compose),
        'runtimePath' => station_docker_runtime_path(),
        'inheritedPath' => (string) (getenv('PATH') ?: ''),
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
    $env['HOME'] = (string) ($env['HOME'] ?? sys_get_temp_dir());
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
 * Run a docker compose command for a project (always rebuilds the compose file
 * unless told otherwise) and persist the most recent log output.
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
 * Inspect the current docker-compose state for a project: returns one of
 * 'running', 'partial', 'stopped', 'unknown', 'unavailable', 'unconfigured'.
 */
function station_project_docker_status(string $projectSlug): array
{
    $slug = station_safe_name($projectSlug);
    $projectPath = station_projects_dir() . '/' . $slug;
    $composePath = $projectPath . '/docker-compose.yml';
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
