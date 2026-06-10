<?php
require_once __DIR__ . '/auth.php';

$current = auth_require_admin();
$method = $_SERVER['REQUEST_METHOD'];
$users = auth_load_users();

if ($method === 'GET') {
    auth_json(['ok' => true, 'users' => array_map('auth_public_user', $users)]);
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
    if (auth_find_user_by_username($users, $username)) {
        auth_json(['error' => 'El usuario ya existe.'], 409);
    }
    if (!in_array($role, ['admin', 'empleado'], true)) {
        auth_json(['error' => 'Rol inválido.'], 400);
    }
    if (!auth_validate_password($password)) {
        auth_json(['error' => 'La contraseña debe tener al menos 8 caracteres.'], 400);
    }

    $user = [
        'id' => bin2hex(random_bytes(16)),
        'username' => $username,
        'name' => $name !== '' ? $name : $username,
        'role' => $role,
        'active' => true,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'created_at' => date('c'),
        'last_login_at' => null,
    ];
    $users[] = $user;
    auth_save_users($users);
    auth_json(['ok' => true, 'user' => auth_public_user($user)], 201);
}

if ($method === 'PATCH') {
    $id = (string)($data['id'] ?? '');
    foreach ($users as $idx => $user) {
        if (($user['id'] ?? '') !== $id) {
            continue;
        }
        if (isset($data['name'])) {
            $users[$idx]['name'] = trim((string)$data['name']);
        }
        if (isset($data['role']) && in_array($data['role'], ['admin', 'empleado'], true)) {
            $users[$idx]['role'] = $data['role'];
        }
        if (isset($data['active'])) {
            if ($id === ($current['id'] ?? '') && !$data['active']) {
                auth_json(['error' => 'No podés desactivar tu propio usuario.'], 400);
            }
            $users[$idx]['active'] = (bool)$data['active'];
        }
        if (!empty($data['password'])) {
            if (!auth_validate_password((string)$data['password'])) {
                auth_json(['error' => 'La contraseña debe tener al menos 8 caracteres.'], 400);
            }
            $users[$idx]['password_hash'] = password_hash((string)$data['password'], PASSWORD_DEFAULT);
        }
        auth_save_users($users);
        auth_json(['ok' => true, 'user' => auth_public_user($users[$idx])]);
    }
    auth_json(['error' => 'Usuario no encontrado.'], 404);
}

auth_json(['error' => 'Método no permitido.'], 405);
