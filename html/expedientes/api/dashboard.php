<?php
require_once __DIR__ . '/auth.php';

$user = auth_require_user();

try {
    $pdo = expediente_pdo();
    $totals = [
        'expedientes' => (int)$pdo->query('SELECT COUNT(*) FROM expediente')->fetchColumn(),
        'movimientos' => (int)$pdo->query('SELECT COUNT(*) FROM expemovi')->fetchColumn(),
        'sectores' => (int)$pdo->query('SELECT COUNT(*) FROM sectmuni WHERE VIGENTE = 1')->fetchColumn(),
    ];
    $ultima = $pdo->query('SELECT MAX(updated_at) FROM expediente')->fetchColumn();

    $estadoRows = $pdo->query("SELECT ESTADO, COUNT(*) AS total FROM expediente GROUP BY ESTADO ORDER BY total DESC LIMIT 8")->fetchAll();
    $sectorRows = $pdo->query("SELECT COALESCE(NULLIF(s.DESCRIPCION,''), e.SECTACTUAL, 'Sin sector') AS sector, COUNT(*) AS total
        FROM expediente e
        LEFT JOIN sectmuni s ON s.CODIGO = e.SECTACTUAL
        GROUP BY sector
        ORDER BY total DESC
        LIMIT 8")->fetchAll();

    auth_json([
        'ok' => true,
        'user' => auth_public_user($user),
        'totals' => $totals,
        'ultima_sync' => $ultima ?: null,
        'by_estado' => $estadoRows,
        'by_sector' => $sectorRows,
    ]);
} catch (Throwable $e) {
    error_log('dashboard.php error: ' . $e->getMessage());
    auth_json(['error' => 'No se pudo cargar el dashboard.'], 500);
}
