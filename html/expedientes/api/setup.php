<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    auth_json(['error' => 'Método no permitido.'], 405);
}

if (auth_count_users() > 0) {
    auth_json(['error' => 'La configuración inicial ya fue realizada.'], 409);
}

$data = auth_read_json();
$username = trim((string)($data['username'] ?? ''));
$name = trim((string)($data['name'] ?? $username));
$password = (string)($data['password'] ?? '');

if (!auth_validate_username($username)) {
    auth_json(['error' => 'El usuario debe tener 3 a 40 caracteres y puede incluir letras, números, punto, guion o guion bajo.'], 400);
}
if (!auth_validate_password($password)) {
    auth_json(['error' => 'La contraseña debe tener al menos 8 caracteres.'], 400);
}

$user = auth_insert_user([
    'id' => bin2hex(random_bytes(16)),
    'username' => $username,
    'name' => $name !== '' ? $name : $username,
    'role' => 'admin',
    'active' => true,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'created_at' => auth_now(),
    'last_login_at' => auth_now(),
]);

auth_start_session();
session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];

auth_json(['ok' => true, 'user' => auth_public_user($user)]);
