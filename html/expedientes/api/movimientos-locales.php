<?php
require_once __DIR__ . '/operaciones.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    auth_json(['error' => 'Método no permitido.'], 405);
}

$user = auth_require_user();
$data = auth_read_json();
$exp = op_normalized_expediente_input($data);
$destinoCodigo = strtoupper(op_clean_string($data['sector_destino'] ?? '', 2));
$observaciones = op_clean_string($data['observaciones'] ?? '', 2000);
$estado = op_clean_string($data['estado'] ?? 'enviado', 30);
if (!preg_match('/^[a-zA-Z0-9_-]{3,30}$/', $estado)) {
    auth_json(['error' => 'Estado interno inválido.'], 400);
}

try {
    $pdo = op_db();
    $oficial = op_require_official_expediente($pdo, $exp['numero'], $exp['ano'], $exp['letra']);
    $destino = op_require_sector($pdo, $destinoCodigo);
    $local = op_get_local_state($pdo, $exp['numero'], $exp['ano']);
    $origenCodigo = $local['sector_actual'] ?? ($oficial['SECTACTUAL'] ?? '');
    if ($origenCodigo === '') {
        $origenCodigo = $destino['CODIGO'];
    }
    if ($origenCodigo === $destino['CODIGO']) {
        auth_json(['error' => 'El sector destino debe ser diferente al sector interno actual.'], 400);
    }
    op_require_user_can_manage_sector($user, $origenCodigo);

    $pdo->beginTransaction();
    $now = auth_now();
    $st = $pdo->prepare("INSERT INTO `" . OP_TABLE_MOVIMIENTOS . "`
        (numero, ano, letra, sector_origen, sector_destino, estado, observaciones, enviado_por, enviado_en, recibido, created_at)
        VALUES (:numero, :ano, :letra, :sector_origen, :sector_destino, :estado, :observaciones, :enviado_por, :enviado_en, 0, :created_at)");
    $st->execute([
        ':numero' => $exp['numero'],
        ':ano' => $exp['ano'],
        ':letra' => $exp['letra'] !== '' ? $exp['letra'] : ($oficial['LETRA'] ?? ''),
        ':sector_origen' => $origenCodigo,
        ':sector_destino' => $destino['CODIGO'],
        ':estado' => strtolower($estado),
        ':observaciones' => $observaciones,
        ':enviado_por' => $user['id'],
        ':enviado_en' => $now,
        ':created_at' => $now,
    ]);
    $movimientoId = (int)$pdo->lastInsertId();

    op_upsert_local_state($pdo, $exp['numero'], $exp['ano'], $exp['letra'] !== '' ? $exp['letra'] : ($oficial['LETRA'] ?? ''), $destino['CODIGO'], strtolower($estado), $movimientoId, $user['id']);
    op_audit($pdo, $user, $exp['numero'], $exp['ano'], 'movimiento_local', $origenCodigo, $destino['CODIGO'], ['observaciones' => $observaciones, 'estado' => strtolower($estado)]);
    $pdo->commit();

    auth_json(['ok' => true, 'message' => 'Movimiento interno registrado.', 'estado_local' => op_get_local_state($pdo, $exp['numero'], $exp['ano'])], 201);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('movimientos-locales.php error: ' . $e->getMessage());
    auth_json(['error' => 'No se pudo registrar el movimiento interno.'], 500);
}
