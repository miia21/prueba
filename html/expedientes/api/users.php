<?php
require_once __DIR__ . '/auth.php';

$current = auth_require_admin();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    auth_json(['ok' => true, 'users' => array_map('auth_public_user', auth_load_users())]);
}

$data = auth_read_json();

if ($method === 'POST') {
    $username = trim((string)($data['username'] ?? ''));
    $name = trim((string)($data['name'] ?? $username));
    $role = (string)($data['role'] ?? 'empleado');
    $password = (string)($data['password'] ?? '');

    if (!auth_validate_username($username)) {
        auth_json(['error' => 'Usuario inválido.'], 400);
    }
    if (auth_find_user_by_username([], $username)) {
        auth_json(['error' => 'El usuario ya existe.'], 409);
    }
    if (!in_array($role, ['admin', 'empleado'], true)) {
        auth_json(['error' => 'Rol inválido.'], 400);
    }
    if (!auth_validate_password($password)) {
        auth_json(['error' => 'La contraseña debe tener al menos 8 caracteres.'], 400);
    }

    $user = auth_insert_user([
        'id' => bin2hex(random_bytes(16)),
        'username' => $username,
        'name' => $name !== '' ? $name : $username,
        'role' => $role,
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
    if (isset($data['role']) && in_array($data['role'], ['admin', 'empleado'], true)) {
        $user['role'] = $data['role'];
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
