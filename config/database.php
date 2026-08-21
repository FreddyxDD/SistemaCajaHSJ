<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT') ?: null,
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME') ?: null,
            'password' => env('DB_PASSWORD') ?: null,
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', true),
        ],

        // Identidad, personal, aplicaciones, roles y permisos centrales del ecosistema HSJ.
        // Compartida con citashsj e intranet_hsj; esta app se registra como 'gestioncajahsj'
        // en access_applications, no crea su propia copia de estas tablas.
        'identity' => [
            'driver' => 'sqlsrv',
            'host' => env('IDENTITY_DB_HOST', env('DB_HOST', 'localhost')),
            'port' => env('IDENTITY_DB_PORT') ?: null,
            'database' => env('IDENTITY_DB_DATABASE', 'HSJ_Identity'),
            'username' => env('IDENTITY_DB_USERNAME', env('DB_USERNAME')) ?: null,
            'password' => env('IDENTITY_DB_PASSWORD', env('DB_PASSWORD')) ?: null,
            'charset' => env('IDENTITY_DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'trust_server_certificate' => env('IDENTITY_DB_TRUST_SERVER_CERTIFICATE', true),
        ],

        // Base operativa real del modulo de Caja (esquema legado SISGESH_BD, lectura y
        // escritura). Local hoy porque el equipo no esta en la red institucional; en
        // produccion apunta al servidor real cambiando solo estas variables de entorno.
        'caja' => [
            'driver' => 'sqlsrv',
            'host' => env('CAJA_DB_HOST', env('DB_HOST', 'localhost')),
            'port' => env('CAJA_DB_PORT') ?: null,
            'database' => env('CAJA_DB_DATABASE', 'SISGESH_BD'),
            'username' => env('CAJA_DB_USERNAME') ?: null,
            'password' => env('CAJA_DB_PASSWORD') ?: null,
            'charset' => env('CAJA_DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'trust_server_certificate' => env('CAJA_DB_TRUST_SERVER_CERTIFICATE', true),
        ],

        // Copia de desarrollo del esquema legado. El middleware coloca esta
        // configuracion en el alias `caja` cuando la sesion usa DEV.
        'caja_development' => [
            'driver' => 'sqlsrv',
            'host' => env('CAJA_DEV_DB_HOST', env('CAJA_DB_HOST', env('DB_HOST', 'localhost'))),
            'port' => env('CAJA_DEV_DB_PORT', env('CAJA_DB_PORT')) ?: null,
            'database' => env('CAJA_DEV_DB_DATABASE', env('CAJA_DB_DATABASE', 'SISGESH_BD')),
            'username' => env('CAJA_DEV_DB_USERNAME', env('CAJA_DB_USERNAME')) ?: null,
            'password' => env('CAJA_DEV_DB_PASSWORD', env('CAJA_DB_PASSWORD')) ?: null,
            'charset' => env('CAJA_DEV_DB_CHARSET', env('CAJA_DB_CHARSET', 'utf8')),
            'prefix' => '',
            'prefix_indexes' => true,
            'trust_server_certificate' => env('CAJA_DEV_DB_TRUST_SERVER_CERTIFICATE', env('CAJA_DB_TRUST_SERVER_CERTIFICATE', true)),
        ],

        // Base real en la red institucional. No hereda host ni credenciales de
        // desarrollo: el cambio se rechaza si alguna variable obligatoria falta.
        'caja_institutional' => [
            'driver' => 'sqlsrv',
            'host' => env('CAJA_INSTITUTIONAL_DB_HOST'),
            'port' => env('CAJA_INSTITUTIONAL_DB_PORT') ?: null,
            'database' => env('CAJA_INSTITUTIONAL_DB_DATABASE', 'SISGESH_BD'),
            'username' => env('CAJA_INSTITUTIONAL_DB_USERNAME') ?: null,
            'password' => env('CAJA_INSTITUTIONAL_DB_PASSWORD') ?: null,
            'charset' => env('CAJA_INSTITUTIONAL_DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'trust_server_certificate' => env('CAJA_INSTITUTIONAL_DB_TRUST_SERVER_CERTIFICATE', true),
        ],

        // Base clinica SIGH, solo lectura, usada para buscar pacientes por historia clinica.
        'sigh' => [
            'driver' => 'sqlsrv',
            'host' => env('SIGH_DB_HOST', '127.0.0.1'),
            'port' => env('SIGH_DB_PORT') ?: null,
            'database' => env('SIGH_DB_DATABASE', 'SIGH'),
            'username' => env('SIGH_DB_USERNAME') ?: null,
            'password' => env('SIGH_DB_PASSWORD') ?: null,
            'charset' => env('SIGH_DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'trust_server_certificate' => env('SIGH_DB_TRUST_SERVER_CERTIFICATE', true),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
