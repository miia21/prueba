<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');

try {
    $pdo = expediente_pdo();
    $row = $pdo->query('SELECT MAX(updated_at) AS ultima FROM expediente')->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'ultima_sync' => $row['ultima'] ?? null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('status.php error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'ultima_sync' => null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
