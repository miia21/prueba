<?php
require_once __DIR__ . '/operaciones.php';

$current = auth_require_admin();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    auth_json(['ok' => true, 'users' => array_map('auth_public_user', auth_load_users())]);
}

$data = auth_read_json();
$validRoles = ['admin', 'supervisor', 'empleado'];

function users_normalize_sector(PDO $pdo, string $role, $sectorValue): ?string {
    $sector = strtoupper(op_clean_string($sectorValue ?? '', 2));
    if ($role === 'empleado' && $sector === '') {
        auth_json(['error' => 'Los empleados deben tener un sector asignado.'], 400);
    }
    if ($sector === '') {
        return null;
    }
    return op_require_sector($pdo, $sector)['CODIGO'];
}

if ($method === 'POST') {
    $username = trim((string)($data['username'] ?? ''));
    $name = trim((string)($data['name'] ?? $username));
    $role = (string)($data['role'] ?? 'empleado');
    $password = (string)($data['password'] ?? '');
    $pdo = op_db();

    if (!auth_validate_username($username)) {
        auth_json(['error' => 'Usuario inválido.'], 400);
    }
    if (auth_find_user_by_username([], $username)) {
        auth_json(['error' => 'El usuario ya existe.'], 409);
    }
    if (!in_array($role, $validRoles, true)) {
        auth_json(['error' => 'Rol inválido.'], 400);
    }
    if (!auth_validate_password($password)) {
        auth_json(['error' => 'La contraseña debe tener al menos 8 caracteres.'], 400);
    }
    $sectorCodigo = users_normalize_sector($pdo, $role, $data['sector_codigo'] ?? '');

    $user = auth_insert_user([
        'id' => bin2hex(random_bytes(16)),
        'username' => $username,
        'name' => $name !== '' ? $name : $username,
        'role' => $role,
        'sector_codigo' => $sectorCodigo,
        'active' => true,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'created_at' => auth_now(),
        'last_login_at' => null,
    ]);
    auth_json(['ok' => true, 'user' => auth_public_user($user)], 201);
}

if ($method === 'PATCH') {
    $id = (string)($data['id'] ?? '');
    $user = auth_find_user_by_id($id);
    if (!$user) {
        auth_json(['error' => 'Usuario no encontrado.'], 404);
    }
    if (isset($data['name'])) {
        $user['name'] = trim((string)$data['name']);
    }
    if (isset($data['role']) && in_array($data['role'], $validRoles, true)) {
        $user['role'] = $data['role'];
    }
    if (array_key_exists('sector_codigo', $data)) {
        $user['sector_codigo'] = users_normalize_sector(op_db(), $user['role'], $data['sector_codigo']);
    } elseif (($user['role'] ?? '') === 'empleado' && empty($user['sector_codigo'])) {
        auth_json(['error' => 'Los empleados deben tener un sector asignado.'], 400);
    }
    if (isset($data['active'])) {
        if ($id === ($current['id'] ?? '') && !$data['active']) {
            auth_json(['error' => 'No podés desactivar tu propio usuario.'], 400);
        }
        $user['active'] = (bool)$data['active'];
    }
    if (!empty($data['password'])) {
        if (!auth_validate_password((string)$data['password'])) {
            auth_json(['error' => 'La contraseña debe tener al menos 8 caracteres.'], 400);
        }
        $user['password_hash'] = password_hash((string)$data['password'], PASSWORD_DEFAULT);
    }
    $user = auth_update_user($user);
    auth_json(['ok' => true, 'user' => auth_public_user($user)]);
}

auth_json(['error' => 'Método no permitido.'], 405);
