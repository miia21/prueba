<?php
require_once __DIR__ . '/operaciones.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');

const DEFAULT_LIMIT = 12;
const MAX_LIMIT = 80;

function json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function clean_string(string $value): string {
    return trim(str_replace(["\0", "\r"], '', $value));
}

function bounded_int(string $value, int $default, int $min, int $max): int {
    if ($value === '' || !preg_match('/^\d+$/', $value)) {
        return $default;
    }
    return max($min, min($max, (int)$value));
}

function audit_event(string $event, array $context = []): void {
    $entry = [
        'ts' => date('c'),
        'event' => $event,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 180),
        'context' => $context,
    ];
    @file_put_contents(
        expediente_audit_log_path(),
        json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function is_internal_request(): bool {
    $token = expediente_internal_token();
    if ($token === '') {
        return false;
    }
    $provided = $_SERVER['HTTP_X_INTERNAL_TOKEN'] ?? '';
    return is_string($provided) && hash_equals($token, $provided);
}

function public_expediente(array $expediente): array {
    $allowed = [
        'NUMERO', 'LETRA', 'ANO', 'FECHAINICIO', 'FECHACARGA',
        'SECTORINICIA', 'SECTORINICIA_NOMBRE', 'EXTERNOINICIA',
        'SECTORDESTINO', 'EXTERNODESTINO', 'TIPOEXPEDIENTE', 'ESTADO',
        'TEMA', 'MOTIVO', 'INICIADOR', 'DESTINO', 'IMPRESO', 'ANULADO',
        'PAGADO', 'INCOMPLETO', 'SECTACTUAL', 'SECTACTUAL_NOMBRE', 'updated_at',
    ];
    return array_intersect_key($expediente, array_flip($allowed));
}

function public_movimiento(array $movimiento): array {
    $allowed = [
        'NUMERO', 'ANO', 'FECHAHORA', 'SECTORACTUAL', 'SECTORACTUAL_NOMBRE',
        'EXTERNOACTUAL', 'LUGAR', 'ESTADOACTUAL', 'FOJAS', 'PERMANECIO',
        'OBSERVACIONES', 'RECIBIDO', 'FECHARECEPCION', 'SECTORPROVENIENTE',
    ];
    return array_intersect_key($movimiento, array_flip($allowed));
}


function is_public_expediente_visible(array $expediente): bool {
    $iniciador = strtoupper(trim((string)($expediente['INICIADOR'] ?? '')));
    $destino = strtoupper(trim((string)($expediente['DESTINO'] ?? '')));
    $externoInicia = trim((string)($expediente['EXTERNOINICIA'] ?? ''));
    return $iniciador === 'EXTERNO' || $destino === 'EXTERNO' || $externoInicia !== '';
}

// Rate limit simple por IP. Mantiene compatibilidad con hosting básico sin servicios extra.
$ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
$key = sys_get_temp_dir() . '/rl_sigap_' . md5($ip);
$now = time();
$rl = @file_get_contents($key);
$rl = $rl ? json_decode($rl, true) : ['c' => 0, 't' => $now];
if (!is_array($rl) || !isset($rl['c'], $rl['t']) || $now - (int)$rl['t'] > 60) {
    $rl = ['c' => 0, 't' => $now];
}
$rl['c']++;
@file_put_contents($key, json_encode($rl));
if ($rl['c'] > 40) {
    audit_event('rate_limited', ['ip_hash' => hash('sha256', $ip)]);
    json_response(['error' => 'Demasiadas solicitudes. Esperá un momento y volvé a intentar.'], 429);
}

$numero = clean_string((string)($_GET['numero'] ?? ''));
$letra = strtoupper(clean_string((string)($_GET['letra'] ?? '')));
$ano = clean_string((string)($_GET['ano'] ?? ''));
$vista = strtolower(clean_string((string)($_GET['vista'] ?? 'publica')));
$limit = bounded_int(clean_string((string)($_GET['limit'] ?? '')), DEFAULT_LIMIT, 1, MAX_LIMIT);
$offset = bounded_int(clean_string((string)($_GET['offset'] ?? '')), 0, 0, 10000);

if ($numero === '' || $ano === '') {
    audit_event('validation_error', ['reason' => 'missing_required']);
    json_response(['error' => 'Número y año son requeridos.'], 400);
}
if (!preg_match('/^\d{1,10}$/', $numero)) {
    audit_event('validation_error', ['reason' => 'invalid_numero']);
    json_response(['error' => 'El número de expediente debe contener solo dígitos.'], 400);
}
if ($letra !== '' && !preg_match('/^[A-Z]$/', $letra)) {
    audit_event('validation_error', ['reason' => 'invalid_letra']);
    json_response(['error' => 'La letra debe ser una única letra.'], 400);
}
if (!preg_match('/^\d{4}$/', $ano)) {
    audit_event('validation_error', ['reason' => 'invalid_ano']);
    json_response(['error' => 'El año debe tener 4 dígitos.'], 400);
}

$ano_int = (int)$ano;
$numero_int = (int)$numero;
$max_year = (int)date('Y') + 1;
if ($ano_int < 1990 || $ano_int > $max_year) {
    audit_event('validation_error', ['reason' => 'ano_out_of_range', 'ano' => $ano_int]);
    json_response(['error' => "Año fuera de rango. Debe estar entre 1990 y $max_year."], 400);
}

$internalRequested = $vista === 'interna';
$internal = $internalRequested && is_internal_request();
if ($internalRequested && !$internal) {
    audit_event('internal_denied', ['numero' => $numero_int, 'ano' => $ano_int]);
    json_response(['error' => 'No autorizado para vista interna.'], 401);
}

try {
    $pdo = expediente_pdo();
} catch (Throwable $e) {
    error_log('consulta.php DB connection error: ' . $e->getMessage());
    audit_event('db_connection_error');
    json_response(['error' => 'Error de base de datos. Intentá más tarde.'], 500);
}

$currentUser = auth_current_user();

$sql = "SELECT
    e.NUMERO, e.LETRA, e.ANO,
    DATE_FORMAT(e.FECHAINICIO,'%Y-%m-%d %H:%i:%s') AS FECHAINICIO,
    DATE_FORMAT(e.FECHACARGA, '%Y-%m-%d %H:%i:%s') AS FECHACARGA,
    e.SECTORINICIA,
    COALESCE(NULLIF(si.DESCRIPCION,''), e.SECTORINICIA) AS SECTORINICIA_NOMBRE,
    e.EXTERNOINICIA,
    e.TIPODOCUM, e.NRODOCUM,
    e.SECTORDESTINO, e.EXTERNODESTINO,
    e.TIPOEXPEDIENTE, e.ESTADO, e.USUARIO,
    e.TEMA, e.MOTIVO, e.INICIADOR, e.DESTINO,
    e.IMPRESO, e.ANULADO, e.PAGADO, e.EMPRESA, e.INCOMPLETO,
    e.SECTACTUAL,
    COALESCE(NULLIF(sa.DESCRIPCION,''), NULLIF(e.SECTACTUAL_NOMBRE,''), e.SECTACTUAL) AS SECTACTUAL_NOMBRE,
    e.updated_at
FROM expediente e
LEFT JOIN sectmuni sa ON sa.CODIGO = e.SECTACTUAL
LEFT JOIN sectmuni si ON si.CODIGO = e.SECTORINICIA
WHERE e.NUMERO = :numero AND e.ANO = :ano";

$params = [':numero' => $numero_int, ':ano' => $ano_int];
if ($letra !== '') {
    $sql .= ' AND e.LETRA = :letra';
    $params[':letra'] = $letra;
}
$sql .= ' LIMIT 1';

try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $expediente = $st->fetch();
} catch (PDOException $e) {
    error_log('consulta.php expediente query error: ' . $e->getMessage());
    audit_event('query_error', ['scope' => 'expediente']);
    json_response(['error' => 'Error al consultar el expediente.'], 500);
}

if ($expediente && !$currentUser && !$internal && !is_public_expediente_visible($expediente)) {
    audit_event('consulta_restringida', ['numero' => $numero_int, 'ano' => $ano_int, 'reason' => 'expediente_interno']);
    $expediente = false;
}

if (!$expediente) {
    audit_event('consulta', ['numero' => $numero_int, 'ano' => $ano_int, 'found' => false]);
    json_response([
        'expediente' => null,
        'movimientos' => [],
        'meta' => [
            'vista' => $internal ? 'interna' : 'publica',
            'limit' => $limit,
            'offset' => $offset,
            'movimientos_total' => 0,
            'has_more' => false,
        ],
    ]);
}

try {
    $countSt = $pdo->prepare('SELECT COUNT(*) FROM expemovi WHERE NUMERO = :numero AND ANO = :ano');
    $countSt->execute([':numero' => $numero_int, ':ano' => $ano_int]);
    $movimientosTotal = (int)$countSt->fetchColumn();

    $st2 = $pdo->prepare("SELECT
        m.NUMERO, m.ANO,
        DATE_FORMAT(m.FECHAHORA,'%Y-%m-%d %H:%i:%s') AS FECHAHORA,
        m.SECTORACTUAL,
        COALESCE(NULLIF(s.DESCRIPCION,''), NULLIF(m.SECTORACTUAL_NOMBRE,''), m.SECTORACTUAL) AS SECTORACTUAL_NOMBRE,
        m.EXTERNOACTUAL, m.LUGAR, m.ESTADOACTUAL,
        m.FOJAS, m.PERMANECIO, m.OBSERVACIONES, m.RECIBIDO,
        DATE_FORMAT(m.FECHARECEPCION,'%Y-%m-%d %H:%i:%s') AS FECHARECEPCION,
        m.USUARIO, m.SECTORPROVENIENTE
    FROM expemovi m
    LEFT JOIN sectmuni s ON s.CODIGO = m.SECTORACTUAL
    WHERE m.NUMERO = :numero AND m.ANO = :ano
    ORDER BY m.FECHAHORA DESC
    LIMIT :limit OFFSET :offset");
    $st2->bindValue(':numero', $numero_int, PDO::PARAM_INT);
    $st2->bindValue(':ano', $ano_int, PDO::PARAM_INT);
    $st2->bindValue(':limit', $limit, PDO::PARAM_INT);
    $st2->bindValue(':offset', $offset, PDO::PARAM_INT);
    $st2->execute();
    $movimientos = $st2->fetchAll();
} catch (PDOException $e) {
    error_log('consulta.php movimientos query error: ' . $e->getMessage());
    audit_event('query_error', ['scope' => 'movimientos']);
    $movimientos = [];
    $movimientosTotal = 0;
}

$seguimientoLocal = null;
if ($currentUser) {
    try {
        op_ensure_tables($pdo);
        $estadoLocal = op_get_local_state($pdo, $numero_int, $ano_int);
        $localCountSt = $pdo->prepare('SELECT COUNT(*) FROM `' . OP_TABLE_MOVIMIENTOS . '` WHERE numero = :numero AND ano = :ano');
        $localCountSt->execute([':numero' => $numero_int, ':ano' => $ano_int]);
        $localTotal = (int)$localCountSt->fetchColumn();
        $localMovSt = $pdo->prepare("SELECT m.id, m.numero AS NUMERO, m.ano AS ANO, m.letra AS LETRA,
            DATE_FORMAT(m.enviado_en, '%Y-%m-%d %H:%i:%s') AS FECHAHORA,
            m.sector_destino AS SECTORACTUAL,
            COALESCE(NULLIF(sd.DESCRIPCION,''), m.sector_destino) AS SECTORACTUAL_NOMBRE,
            m.sector_origen AS SECTORPROVENIENTE,
            COALESCE(NULLIF(so.DESCRIPCION,''), m.sector_origen) AS SECTORPROVENIENTE_NOMBRE,
            m.estado AS ESTADOACTUAL,
            m.observaciones AS OBSERVACIONES,
            m.recibido AS RECIBIDO,
            DATE_FORMAT(m.recibido_en, '%Y-%m-%d %H:%i:%s') AS FECHARECEPCION,
            'web' AS ORIGEN
            FROM `" . OP_TABLE_MOVIMIENTOS . "` m
            LEFT JOIN sectmuni sd ON sd.CODIGO = m.sector_destino
            LEFT JOIN sectmuni so ON so.CODIGO = m.sector_origen
            WHERE m.numero = :numero AND m.ano = :ano
            ORDER BY m.enviado_en DESC
            LIMIT 50");
        $localMovSt->execute([':numero' => $numero_int, ':ano' => $ano_int]);
        $seguimientoLocal = [
            'estado' => $estadoLocal,
            'movimientos' => $localMovSt->fetchAll(),
            'movimientos_total' => $localTotal,
        ];
    } catch (Throwable $e) {
        error_log('consulta.php seguimiento local error: ' . $e->getMessage());
        $seguimientoLocal = ['estado' => null, 'movimientos' => [], 'movimientos_total' => 0, 'error' => 'No se pudo cargar el seguimiento interno.'];
    }
}

if (!$internal && !$currentUser) {
    $expediente = public_expediente($expediente);
    $movimientos = array_map('public_movimiento', $movimientos);
}

$auditContext = [
    'numero' => $numero_int,
    'ano' => $ano_int,
    'letra' => $letra,
    'found' => true,
    'vista' => $internal ? 'interna' : 'publica',
    'limit' => $limit,
    'offset' => $offset,
    'movimientos_returned' => count($movimientos),
    'movimientos_total' => $movimientosTotal,
];
audit_event('consulta', $auditContext);

$response = [
    'expediente' => $expediente,
    'movimientos' => $movimientos,
    'meta' => [
        'vista' => $internal ? 'interna' : 'publica',
        'limit' => $limit,
        'offset' => $offset,
        'movimientos_total' => $movimientosTotal,
        'returned' => count($movimientos),
        'has_more' => ($offset + count($movimientos)) < $movimientosTotal,
    ],
];
if ($seguimientoLocal !== null) {
    $response['seguimiento_local'] = $seguimientoLocal;
}
json_response($response);
