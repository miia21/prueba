<?php
require_once __DIR__ . '/operaciones.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    auth_json(['error' => 'Método no permitido.'], 405);
}

auth_require_user();

try {
    $pdo = op_db();
    $rows = $pdo->query("SELECT CODIGO AS codigo, DESCRIPCION AS descripcion, RESPONSABLE AS responsable, NOMBRECORTO AS nombre_corto
        FROM sectmuni
        WHERE VIGENTE = 1
        ORDER BY DESCRIPCION ASC, CODIGO ASC")->fetchAll();
    auth_json(['ok' => true, 'sectores' => $rows]);
} catch (Throwable $e) {
    error_log('sectores.php error: ' . $e->getMessage());
    auth_json(['error' => 'No se pudieron cargar los sectores.'], 500);
}
