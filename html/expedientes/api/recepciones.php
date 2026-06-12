<?php
require_once __DIR__ . '/operaciones.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    auth_json(['error' => 'Método no permitido.'], 405);
}

$user = auth_require_user();
$data = auth_read_json();
$exp = op_normalized_expediente_input($data);
$sectorCodigo = strtoupper(op_clean_string($data['sector_codigo'] ?? '', 2));
$observaciones = op_clean_string($data['observaciones'] ?? '', 2000);

try {
    $pdo = op_db();
    $oficial = op_require_official_expediente($pdo, $exp['numero'], $exp['ano'], $exp['letra']);
    $sector = op_require_sector($pdo, $sectorCodigo);
    $local = op_get_local_state($pdo, $exp['numero'], $exp['ano']);
    $currentSector = strtoupper((string)(($local['sector_actual'] ?? '') ?: ($oficial['SECTACTUAL'] ?? '')));
    op_require_user_can_manage_sector($user, $sector['CODIGO']);
    if (!auth_is_manager($user) && $currentSector !== '' && $currentSector !== $sector['CODIGO']) {
        auth_json(['error' => 'Solo podés recibir expedientes que estén asignados a tu sector.'], 403);
    }
    $pdo->beginTransaction();
    $now = auth_now();

    $st = $pdo->prepare("INSERT INTO `" . OP_TABLE_RECEPCIONES . "`
        (numero, ano, letra, sector_codigo, recibido_por, recibido_en, observaciones, origen, created_at)
        VALUES (:numero, :ano, :letra, :sector_codigo, :recibido_por, :recibido_en, :observaciones, 'web', :created_at)");
    $st->execute([
        ':numero' => $exp['numero'],
        ':ano' => $exp['ano'],
        ':letra' => $exp['letra'] !== '' ? $exp['letra'] : ($oficial['LETRA'] ?? ''),
        ':sector_codigo' => $sector['CODIGO'],
        ':recibido_por' => $user['id'],
        ':recibido_en' => $now,
        ':observaciones' => $observaciones,
        ':created_at' => $now,
    ]);

    op_upsert_local_state($pdo, $exp['numero'], $exp['ano'], $exp['letra'] !== '' ? $exp['letra'] : ($oficial['LETRA'] ?? ''), $sector['CODIGO'], 'recibido', null, $user['id']);
    op_audit($pdo, $user, $exp['numero'], $exp['ano'], 'recepcion_local', null, $sector['CODIGO'], ['observaciones' => $observaciones]);
    $pdo->commit();

    auth_json(['ok' => true, 'message' => 'Recepción interna registrada.', 'estado_local' => op_get_local_state($pdo, $exp['numero'], $exp['ano'])], 201);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('recepciones.php error: ' . $e->getMessage());
    auth_json(['error' => 'No se pudo registrar la recepción interna.'], 500);
}
