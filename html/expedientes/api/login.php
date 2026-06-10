<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    auth_json(['error' => 'Método no permitido.'], 405);
}

$data = auth_read_json();
$username = trim((string)($data['username'] ?? ''));
$password = (string)($data['password'] ?? '');
$users = auth_load_users();
$user = auth_find_user_by_username($users, $username);

if (!$user || !($user['active'] ?? true) || !password_verify($password, (string)($user['password_hash'] ?? ''))) {
    auth_json(['error' => 'Usuario o contraseña inválidos.'], 401);
}

$user['last_login_at'] = date('c');
$users = auth_replace_user($users, $user);
auth_save_users($users);

auth_start_session();
session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];

auth_json(['ok' => true, 'user' => auth_public_user($user)]);
