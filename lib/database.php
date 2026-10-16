<?php
declare(strict_types=1);

function inyouEnv(string $key): ?string
{
    $value = getenv($key);
    if ($value === false) {
        return null;
    }
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function inyouRuntime(): string
{
    if (inyouEnv('VERCEL') === '1' || inyouEnv('VERCEL_ENV') !== null) {
        return 'vercel';
    }
    return inyouEnv('INY_APP_ENV') ?? 'hostinger';
}

function inyouDatabaseConfig(): array
{
    $envConfig = [
        'host' => inyouEnv('INY_DB_HOST'),
        'database' => inyouEnv('INY_DB_NAME'),
        'username' => inyouEnv('INY_DB_USER'),
        'password' => inyouEnv('INY_DB_PASSWORD'),
        'port' => inyouEnv('INY_DB_PORT') ?? '3306',
    ];

    $required = ['host', 'database', 'username', 'password'];
    $hasAnyEnv = false;
    $hasAllEnv = true;
    foreach ($required as $key) {
        if ($envConfig[$key] !== null) {
            $hasAnyEnv = true;
        } else {
            $hasAllEnv = false;
        }
    }

    if ($hasAnyEnv && !$hasAllEnv) {
        throw new RuntimeException('La configuración MySQL por variables de entorno está incompleta.');
    }

    if ($hasAllEnv) {
        return $envConfig;
    }

    $privatePath = dirname(__DIR__, 2) . '/inyou-private/db.php';
    if (!is_file($privatePath)) {
        throw new RuntimeException('Configuración de base de datos no disponible.');
    }

    $config = require $privatePath;
    if (!is_array($config)) {
        throw new RuntimeException('Configuración privada de base de datos inválida.');
    }

    foreach ($required as $key) {
        if (!isset($config[$key]) || !is_string($config[$key]) || trim($config[$key]) === '') {
            throw new RuntimeException('Configuración privada de base de datos incompleta.');
        }
    }

    return [
        'host' => trim($config['host']),
        'database' => trim($config['database']),
        'username' => trim($config['username']),
        'password' => $config['password'],
        'port' => isset($config['port']) && (string)$config['port'] !== '' ? (string)$config['port'] : '3306',
    ];
}

function inyouDb(): PDO
{
    $config = inyouDatabaseConfig();
    $port = preg_match('/^\d{1,5}$/', (string)$config['port']) ? (string)$config['port'] : '3306';

    return new PDO(
        'mysql:host=' . $config['host'] . ';port=' . $port . ';dbname=' . $config['database'] . ';charset=utf8mb4',
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]
    );
}
