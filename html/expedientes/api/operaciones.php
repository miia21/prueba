<?php
require_once __DIR__ . '/auth.php';

const OP_TABLE_RECEPCIONES = 'expe2_recepciones';
const OP_TABLE_MOVIMIENTOS = 'expe2_movimientos';
const OP_TABLE_ESTADO = 'expe2_estado_local';
const OP_TABLE_AUDITORIA = 'expe2_auditoria';

function op_db(): PDO {
    $pdo = expediente_pdo();
    op_ensure_tables($pdo);
    return $pdo;
}

function op_ensure_tables(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `" . OP_TABLE_RECEPCIONES . "` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `numero` bigint NOT NULL,
        `ano` smallint NOT NULL,
        `letra` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
        `sector_codigo` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL,
        `recibido_por` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
        `recibido_en` datetime NOT NULL,
        `observaciones` text COLLATE utf8mb4_unicode_ci,
        `origen` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'web',
        `created_at` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_expe2_recepciones_exp` (`numero`,`ano`),
        KEY `idx_expe2_recepciones_sector` (`sector_codigo`),
        KEY `idx_expe2_recepciones_fecha` (`recibido_en`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `" . OP_TABLE_MOVIMIENTOS . "` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `numero` bigint NOT NULL,
        `ano` smallint NOT NULL,
        `letra` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
        `sector_origen` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL,
        `sector_destino` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL,
        `estado` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'enviado',
        `observaciones` text COLLATE utf8mb4_unicode_ci,
        `enviado_por` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
        `enviado_en` datetime NOT NULL,
        `recibido` tinyint(1) NOT NULL DEFAULT 0,
        `recibido_por` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `recibido_en` datetime DEFAULT NULL,
        `created_at` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_expe2_movimientos_exp` (`numero`,`ano`),
        KEY `idx_expe2_movimientos_destino` (`sector_destino`,`recibido`),
        KEY `idx_expe2_movimientos_fecha` (`enviado_en`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `" . OP_TABLE_ESTADO . "` (
        `numero` bigint NOT NULL,
        `ano` smallint NOT NULL,
        `letra` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
        `sector_actual` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL,
        `estado_local` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
        `ultimo_movimiento_id` bigint unsigned DEFAULT NULL,
        `actualizado_por` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
        `actualizado_en` datetime NOT NULL,
        PRIMARY KEY (`numero`,`ano`),
        KEY `idx_expe2_estado_sector` (`sector_actual`),
        KEY `idx_expe2_estado_estado` (`estado_local`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `" . OP_TABLE_AUDITORIA . "` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `numero` bigint NOT NULL,
        `ano` smallint NOT NULL,
        `accion` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
        `usuario_id` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
        `username` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
        `sector_origen` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `sector_destino` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `detalle_json` longtext COLLATE utf8mb4_unicode_ci,
        `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `created_at` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_expe2_auditoria_exp` (`numero`,`ano`),
        KEY `idx_expe2_auditoria_usuario` (`usuario_id`),
        KEY `idx_expe2_auditoria_accion` (`accion`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $ensured = true;
}

function op_clean_string($value, int $max = 255): string {
    $clean = trim(str_replace(["\0", "\r"], '', (string)$value));
    if (function_exists('mb_substr')) {
        return mb_substr($clean, 0, $max, 'UTF-8');
    }
    return substr($clean, 0, $max);
}

function op_validate_numero(string $numero): bool {
    return (bool)preg_match('/^\d{1,10}$/', $numero);
}

function op_validate_ano(string $ano): bool {
    if (!preg_match('/^\d{4}$/', $ano)) {
        return false;
    }
    $year = (int)$ano;
    return $year >= 1990 && $year <= ((int)date('Y') + 1);
}

function op_validate_letra(string $letra): bool {
    return $letra === '' || (bool)preg_match('/^[A-Z]$/', $letra);
}

function op_validate_sector_code(string $sector): bool {
    return (bool)preg_match('/^[A-Z0-9]{1,2}$/', $sector);
}

function op_normalized_expediente_input(array $data): array {
    $numero = op_clean_string($data['numero'] ?? '', 10);
    $ano = op_clean_string($data['ano'] ?? '', 4);
    $letra = strtoupper(op_clean_string($data['letra'] ?? '', 1));

    if (!op_validate_numero($numero)) {
        auth_json(['error' => 'El número de expediente debe contener solo dígitos.'], 400);
    }
    if (!op_validate_ano($ano)) {
        auth_json(['error' => 'El año debe tener 4 dígitos y estar dentro del rango permitido.'], 400);
    }
    if (!op_validate_letra($letra)) {
        auth_json(['error' => 'La letra debe ser una única letra.'], 400);
    }

    return ['numero' => (int)$numero, 'ano' => (int)$ano, 'letra' => $letra];
}

function op_require_sector(PDO $pdo, string $sector): array {
    $sector = strtoupper(op_clean_string($sector, 2));
    if (!op_validate_sector_code($sector)) {
        auth_json(['error' => 'Sector inválido.'], 400);
    }

    $st = $pdo->prepare('SELECT CODIGO, DESCRIPCION, RESPONSABLE, NOMBRECORTO FROM sectmuni WHERE CODIGO = :codigo AND VIGENTE = 1 LIMIT 1');
    $st->execute([':codigo' => $sector]);
    $row = $st->fetch();
    if (!$row) {
        auth_json(['error' => 'El sector indicado no existe o no está vigente.'], 400);
    }
    return $row;
}

function op_find_official_expediente(PDO $pdo, int $numero, int $ano, string $letra = ''): ?array {
    $sql = 'SELECT NUMERO, LETRA, ANO, ESTADO, SECTACTUAL, SECTACTUAL_NOMBRE, MOTIVO, INICIADOR, EXTERNOINICIA, DESTINO, ANULADO FROM expediente WHERE NUMERO = :numero AND ANO = :ano';
    $params = [':numero' => $numero, ':ano' => $ano];
    if ($letra !== '') {
        $sql .= ' AND LETRA = :letra';
        $params[':letra'] = $letra;
    }
    $sql .= ' LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row ?: null;
}

function op_require_official_expediente(PDO $pdo, int $numero, int $ano, string $letra = ''): array {
    $expediente = op_find_official_expediente($pdo, $numero, $ano, $letra);
    if (!$expediente) {
        auth_json(['error' => 'El expediente no existe en la base oficial sincronizada.'], 404);
    }
    if ((int)($expediente['ANULADO'] ?? 0) === 1) {
        auth_json(['error' => 'No se puede operar un expediente anulado.'], 409);
    }
    return $expediente;
}

function op_user_sector(array $user): string {
    return strtoupper(trim((string)($user['sector_codigo'] ?? '')));
}

function op_user_can_manage_sector(array $user, string $sector): bool {
    if (auth_is_manager($user)) {
        return true;
    }
    $userSector = op_user_sector($user);
    return $userSector !== '' && $userSector === strtoupper($sector);
}

function op_require_user_can_manage_sector(array $user, string $sector): void {
    if (!op_user_can_manage_sector($user, $sector)) {
        auth_json(['error' => 'Tu usuario solo puede operar expedientes correspondientes a su sector.'], 403);
    }
}

function op_get_local_state(PDO $pdo, int $numero, int $ano): ?array {
    $st = $pdo->prepare("SELECT e.numero, e.ano, e.letra, e.sector_actual, COALESCE(NULLIF(s.DESCRIPCION,''), e.sector_actual) AS sector_actual_nombre,
        e.estado_local, e.ultimo_movimiento_id, e.actualizado_por, e.actualizado_en
        FROM `" . OP_TABLE_ESTADO . "` e
        LEFT JOIN sectmuni s ON s.CODIGO = e.sector_actual
        WHERE e.numero = :numero AND e.ano = :ano LIMIT 1");
    $st->execute([':numero' => $numero, ':ano' => $ano]);
    $row = $st->fetch();
    return $row ?: null;
}

function op_upsert_local_state(PDO $pdo, int $numero, int $ano, string $letra, string $sector, string $estado, ?int $movimientoId, string $userId): void {
    $st = $pdo->prepare("INSERT INTO `" . OP_TABLE_ESTADO . "`
        (numero, ano, letra, sector_actual, estado_local, ultimo_movimiento_id, actualizado_por, actualizado_en)
        VALUES (:numero, :ano, :letra, :sector_actual, :estado_local, :ultimo_movimiento_id, :actualizado_por, :actualizado_en)
        ON DUPLICATE KEY UPDATE letra = VALUES(letra), sector_actual = VALUES(sector_actual), estado_local = VALUES(estado_local),
        ultimo_movimiento_id = VALUES(ultimo_movimiento_id), actualizado_por = VALUES(actualizado_por), actualizado_en = VALUES(actualizado_en)");
    $st->execute([
        ':numero' => $numero,
        ':ano' => $ano,
        ':letra' => $letra,
        ':sector_actual' => $sector,
        ':estado_local' => $estado,
        ':ultimo_movimiento_id' => $movimientoId,
        ':actualizado_por' => $userId,
        ':actualizado_en' => auth_now(),
    ]);
}

function op_audit(PDO $pdo, array $user, int $numero, int $ano, string $accion, ?string $origen, ?string $destino, array $detalle = []): void {
    $st = $pdo->prepare("INSERT INTO `" . OP_TABLE_AUDITORIA . "`
        (numero, ano, accion, usuario_id, username, sector_origen, sector_destino, detalle_json, ip, user_agent, created_at)
        VALUES (:numero, :ano, :accion, :usuario_id, :username, :sector_origen, :sector_destino, :detalle_json, :ip, :user_agent, :created_at)");
    $st->execute([
        ':numero' => $numero,
        ':ano' => $ano,
        ':accion' => $accion,
        ':usuario_id' => $user['id'] ?? '',
        ':username' => $user['username'] ?? '',
        ':sector_origen' => $origen,
        ':sector_destino' => $destino,
        ':detalle_json' => json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ':user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ':created_at' => auth_now(),
    ]);
}
