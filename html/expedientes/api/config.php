<?php
/**
 * Configuración compartida de la app de expedientes.
 *
 * Prioridad de carga:
 * 1. Variables de entorno EXPEDIENTES_*.
 * 2. Archivo local no versionado config.local.php, si existe.
 * 3. Valores seguros por defecto para desarrollo local.
 */

$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require_once $localConfig;
}

function expediente_config_value(string $envName, string $constantName, string $default = ''): string {
    $value = getenv($envName);
    if (is_string($value) && $value !== '') {
        return $value;
    }
    if (defined($constantName)) {
        return (string)constant($constantName);
    }
    return $default;
}

function expediente_db_config(): array {
    return [
        'host' => expediente_config_value('EXPEDIENTES_DB_HOST', 'EXPEDIENTES_DB_HOST', 'localhost'),
        'name' => expediente_config_value('EXPEDIENTES_DB_NAME', 'EXPEDIENTES_DB_NAME', 'sigap_expedientes'),
        'user' => expediente_config_value('EXPEDIENTES_DB_USER', 'EXPEDIENTES_DB_USER'),
        'pass' => expediente_config_value('EXPEDIENTES_DB_PASS', 'EXPEDIENTES_DB_PASS'),
        'charset' => expediente_config_value('EXPEDIENTES_DB_CHARSET', 'EXPEDIENTES_DB_CHARSET', 'utf8mb4'),
    ];
}

function expediente_internal_token(): string {
    return expediente_config_value('EXPEDIENTES_INTERNAL_TOKEN', 'EXPEDIENTES_INTERNAL_TOKEN');
}

function expediente_audit_log_path(): string {
    return expediente_config_value(
        'EXPEDIENTES_AUDIT_LOG',
        'EXPEDIENTES_AUDIT_LOG',
        sys_get_temp_dir() . '/expedientes_audit.log'
    );
}


function expediente_users_file_path(): string {
    return expediente_config_value(
        'EXPEDIENTES_USERS_FILE',
        'EXPEDIENTES_USERS_FILE',
        __DIR__ . '/data/users.json'
    );
}

function expediente_pdo(): PDO {
    $cfg = expediente_db_config();
    if ($cfg['user'] === '') {
        throw new RuntimeException('Falta configurar EXPEDIENTES_DB_USER.');
    }

    return new PDO(
        'mysql:host=' . $cfg['host'] . ';dbname=' . $cfg['name'] . ';charset=' . $cfg['charset'],
        $cfg['user'],
        $cfg['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}
