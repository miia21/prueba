<?php
require_once __DIR__ . '/operaciones.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    auth_json(['error' => 'Método no permitido.'], 405);
}

auth_require_user();
$sector = strtoupper(op_clean_string($_GET['sector'] ?? '', 2));

try {
    $pdo = op_db();
    $params = [];
    $where = '';
    if ($sector !== '') {
        if (!op_validate_sector_code($sector)) {
            auth_json(['error' => 'Sector inválido.'], 400);
        }
        $where = 'WHERE el.sector_actual = :sector';
        $params[':sector'] = $sector;
    }

    $st = $pdo->prepare("SELECT el.numero, el.ano, el.letra, el.sector_actual, COALESCE(NULLIF(s.DESCRIPCION,''), el.sector_actual) AS sector_actual_nombre,
        el.estado_local, el.actualizado_en, e.MOTIVO, e.ESTADO AS estado_oficial, e.SECTACTUAL AS sector_oficial
        FROM `" . OP_TABLE_ESTADO . "` el
        LEFT JOIN expediente e ON e.NUMERO = el.numero AND e.ANO = el.ano
        LEFT JOIN sectmuni s ON s.CODIGO = el.sector_actual
        $where
        ORDER BY el.actualizado_en DESC
        LIMIT 80");
    $st->execute($params);
    $estados = $st->fetchAll();

    $movimientos = $pdo->query("SELECT m.id, m.numero, m.ano, m.letra, m.sector_origen, COALESCE(NULLIF(so.DESCRIPCION,''), m.sector_origen) AS sector_origen_nombre,
        m.sector_destino, COALESCE(NULLIF(sd.DESCRIPCION,''), m.sector_destino) AS sector_destino_nombre, m.estado, m.observaciones,
        m.enviado_en, m.recibido, m.recibido_en
        FROM `" . OP_TABLE_MOVIMIENTOS . "` m
        LEFT JOIN sectmuni so ON so.CODIGO = m.sector_origen
        LEFT JOIN sectmuni sd ON sd.CODIGO = m.sector_destino
        ORDER BY m.enviado_en DESC
        LIMIT 30")->fetchAll();

    auth_json(['ok' => true, 'estados' => $estados, 'movimientos' => $movimientos]);
} catch (Throwable $e) {
    error_log('bandeja.php error: ' . $e->getMessage());
    auth_json(['error' => 'No se pudo cargar la bandeja interna.'], 500);
}
