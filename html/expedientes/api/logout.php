<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    auth_json(['error' => 'Método no permitido.'], 405);
}

auth_start_session();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
}
session_destroy();

auth_json(['ok' => true]);
