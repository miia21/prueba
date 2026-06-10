<?php
require_once __DIR__ . '/config.php';

const SESSION_NAME = 'expedientes_session';

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

function auth_users_file(): string {
    return expediente_users_file_path();
}

function auth_load_users(): array {
    $path = auth_users_file();
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function auth_save_users(array $users): void {
    $path = auth_users_file();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    file_put_contents($path, json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function auth_public_user(array $user): array {
    return [
        'id' => $user['id'] ?? '',
        'username' => $user['username'] ?? '',
        'name' => $user['name'] ?? '',
        'role' => $user['role'] ?? 'empleado',
        'active' => (bool)($user['active'] ?? true),
        'created_at' => $user['created_at'] ?? null,
        'last_login_at' => $user['last_login_at'] ?? null,
    ];
}

function auth_current_user(): ?array {
    auth_start_session();
    $id = $_SESSION['user_id'] ?? '';
    if ($id === '') {
        return null;
    }
    foreach (auth_load_users() as $user) {
        if (($user['id'] ?? '') === $id && ($user['active'] ?? true)) {
            return $user;
        }
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

function auth_validate_username(string $username): bool {
    return (bool)preg_match('/^[a-zA-Z0-9._-]{3,40}$/', $username);
}

function auth_validate_password(string $password): bool {
    return strlen($password) >= 8;
}

function auth_find_user_by_username(array $users, string $username): ?array {
    foreach ($users as $user) {
        if (strtolower((string)($user['username'] ?? '')) === strtolower($username)) {
            return $user;
        }
    }
    return null;
}

function auth_replace_user(array $users, array $updated): array {
    foreach ($users as $idx => $user) {
        if (($user['id'] ?? '') === ($updated['id'] ?? null)) {
            $users[$idx] = $updated;
            return $users;
        }
    }
    $users[] = $updated;
    return $users;
}
