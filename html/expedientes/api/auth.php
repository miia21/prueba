<?php
require_once __DIR__ . '/config.php';

const SESSION_NAME = 'expedientes_session';
const USERS_TABLE = 'usuarios_expe2';

function auth_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'path' => '/',
    ]);
    session_start();
}

function auth_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function auth_read_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function auth_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = expediente_pdo();
    auth_ensure_users_table($pdo);
    return $pdo;
}

function auth_ensure_users_table(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    try {
        $pdo->query("SELECT 1 FROM `" . USERS_TABLE . "` LIMIT 1");
        auth_ensure_users_columns($pdo);
        $ensured = true;
        return;
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') {
            throw $e;
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `" . USERS_TABLE . "` (
        `id` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
        `username` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
        `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
        `role` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'empleado',
        `sector_codigo` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `active` tinyint(1) NOT NULL DEFAULT 1,
        `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
        `created_at` datetime NOT NULL,
        `last_login_at` datetime DEFAULT NULL,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_usuarios_expe2_username` (`username`),
        KEY `idx_usuarios_expe2_role` (`role`),
        KEY `idx_usuarios_expe2_sector` (`sector_codigo`),
        KEY `idx_usuarios_expe2_active` (`active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    auth_ensure_users_columns($pdo);
    $ensured = true;
}

function auth_ensure_users_columns(PDO $pdo): void {
    $columns = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `" . USERS_TABLE . "`")->fetchAll() as $column) {
        $columns[$column['Field']] = true;
    }
    if (!isset($columns['sector_codigo'])) {
        $pdo->exec("ALTER TABLE `" . USERS_TABLE . "` ADD COLUMN `sector_codigo` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `role`");
        try {
            $pdo->exec("ALTER TABLE `" . USERS_TABLE . "` ADD KEY `idx_usuarios_expe2_sector` (`sector_codigo`)");
        } catch (PDOException $e) {
            // El índice puede existir si la tabla fue creada manualmente con una variante similar.
        }
    }
}

function auth_now(): string {
    return date('Y-m-d H:i:s');
}

function auth_load_users(): array {
    $st = auth_db()->query("SELECT id, username, name, role, sector_codigo, active, password_hash, created_at, last_login_at FROM `" . USERS_TABLE . "` ORDER BY created_at ASC, username ASC");
    return $st->fetchAll();
}

function auth_count_users(): int {
    return (int)auth_db()->query("SELECT COUNT(*) FROM `" . USERS_TABLE . "`")->fetchColumn();
}

function auth_public_user(array $user): array {
    return [
        'id' => $user['id'] ?? '',
        'username' => $user['username'] ?? '',
        'name' => $user['name'] ?? '',
        'role' => $user['role'] ?? 'empleado',
        'sector_codigo' => $user['sector_codigo'] ?? null,
        'active' => (bool)($user['active'] ?? true),
        'created_at' => $user['created_at'] ?? null,
        'last_login_at' => $user['last_login_at'] ?? null,
    ];
}

function auth_find_user_by_id(string $id): ?array {
    $st = auth_db()->prepare("SELECT id, username, name, role, sector_codigo, active, password_hash, created_at, last_login_at FROM `" . USERS_TABLE . "` WHERE id = :id LIMIT 1");
    $st->execute([':id' => $id]);
    $user = $st->fetch();
    return $user ?: null;
}

function auth_find_user_by_username(array $unusedUsers, string $username): ?array {
    $st = auth_db()->prepare("SELECT id, username, name, role, sector_codigo, active, password_hash, created_at, last_login_at FROM `" . USERS_TABLE . "` WHERE LOWER(username) = LOWER(:username) LIMIT 1");
    $st->execute([':username' => $username]);
    $user = $st->fetch();
    return $user ?: null;
}

function auth_insert_user(array $user): array {
    $st = auth_db()->prepare("INSERT INTO `" . USERS_TABLE . "`
        (id, username, name, role, sector_codigo, active, password_hash, created_at, last_login_at)
        VALUES (:id, :username, :name, :role, :sector_codigo, :active, :password_hash, :created_at, :last_login_at)");
    $st->execute([
        ':id' => $user['id'],
        ':username' => $user['username'],
        ':name' => $user['name'],
        ':role' => $user['role'],
        ':sector_codigo' => $user['sector_codigo'] ?? null,
        ':active' => !empty($user['active']) ? 1 : 0,
        ':password_hash' => $user['password_hash'],
        ':created_at' => $user['created_at'],
        ':last_login_at' => $user['last_login_at'] ?? null,
    ]);
    return auth_find_user_by_id($user['id']) ?: $user;
}

function auth_update_user(array $user): array {
    $st = auth_db()->prepare("UPDATE `" . USERS_TABLE . "`
        SET name = :name, role = :role, sector_codigo = :sector_codigo, active = :active, password_hash = :password_hash, last_login_at = :last_login_at
        WHERE id = :id");
    $st->execute([
        ':id' => $user['id'],
        ':name' => $user['name'],
        ':role' => $user['role'],
        ':sector_codigo' => $user['sector_codigo'] ?? null,
        ':active' => !empty($user['active']) ? 1 : 0,
        ':password_hash' => $user['password_hash'],
        ':last_login_at' => $user['last_login_at'] ?? null,
    ]);
    return auth_find_user_by_id($user['id']) ?: $user;
}

function auth_current_user(): ?array {
    auth_start_session();
    $id = $_SESSION['user_id'] ?? '';
    if ($id === '') {
        return null;
    }
    $user = auth_find_user_by_id((string)$id);
    if ($user && (bool)($user['active'] ?? true)) {
        return $user;
    }
    return null;
}

function auth_require_user(): array {
    $user = auth_current_user();
    if (!$user) {
        auth_json(['error' => 'No autenticado.'], 401);
    }
    return $user;
}

function auth_require_admin(): array {
    $user = auth_require_user();
    if (($user['role'] ?? '') !== 'admin') {
        auth_json(['error' => 'Permisos insuficientes.'], 403);
    }
    return $user;
}

function auth_require_manager(): array {
    $user = auth_require_user();
    if (!in_array(($user['role'] ?? ''), ['admin', 'supervisor'], true)) {
        auth_json(['error' => 'Permisos insuficientes.'], 403);
    }
    return $user;
}

function auth_is_manager(array $user): bool {
    return in_array(($user['role'] ?? ''), ['admin', 'supervisor'], true);
}

function auth_validate_username(string $username): bool {
    return (bool)preg_match('/^[\p{L}\p{N}._-]{3,40}$/u', $username);
}

function auth_validate_password(string $password): bool {
    return strlen($password) >= 8;
}
