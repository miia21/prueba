<?php
require_once __DIR__ . '/auth.php';

auth_start_session();
$user = auth_current_user();
auth_json([
    'ok' => true,
    'authenticated' => (bool)$user,
    'user' => $user ? auth_public_user($user) : null,
    'setup_required' => count(auth_load_users()) === 0,
]);
