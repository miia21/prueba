<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');

const DB_HOST = 'localhost';
const DB_NAME = 'sigap_expedientes';
const DB_USER = 'admin_sql';
const DB_PASS = 'MpSj*30673';
const DB_CHARSET = 'utf8mb4';
const DEFAULT_LIMIT = 80;

function json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function clean_string(string $value): string {
    return trim(str_replace(["\0", "\r"], '', $value));
}

function is_internal_request(): bool {
    $token = getenv('EXPEDIENTES_INTERNAL_TOKEN') ?: '';
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
    json_response(['error' => 'Demasiadas solicitudes. Esperá un momento y volvé a intentar.'], 429);
}

$numero = clean_string((string)($_GET['numero'] ?? ''));
$letra = strtoupper(clean_string((string)($_GET['letra'] ?? '')));
$ano = clean_string((string)($_GET['ano'] ?? ''));
$vista = strtolower(clean_string((string)($_GET['vista'] ?? 'publica')));

if ($numero === '' || $ano === '') {
    json_response(['error' => 'Número y año son requeridos.'], 400);
}
if (!preg_match('/^\d{1,10}$/', $numero)) {
    json_response(['error' => 'El número de expediente debe contener solo dígitos.'], 400);
}
if ($letra !== '' && !preg_match('/^[A-Z]$/', $letra)) {
    json_response(['error' => 'La letra debe ser una única letra.'], 400);
}
if (!preg_match('/^\d{4}$/', $ano)) {
    json_response(['error' => 'El año debe tener 4 dígitos.'], 400);
}

$ano_int = (int)$ano;
$numero_int = (int)$numero;
$max_year = (int)date('Y') + 1;
if ($ano_int < 1990 || $ano_int > $max_year) {
    json_response(['error' => "Año fuera de rango. Debe estar entre 1990 y $max_year."], 400);
}

$internal = $vista === 'interna' && is_internal_request();

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    error_log('consulta.php DB connection error: ' . $e->getMessage());
    json_response(['error' => 'Error de base de datos. Intentá más tarde.'], 500);
}

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
    json_response(['error' => 'Error al consultar el expediente.'], 500);
}

if (!$expediente) {
    json_response(['expediente' => null, 'movimientos' => []]);
}

try {
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
    LIMIT " . DEFAULT_LIMIT);
    $st2->execute([':numero' => $numero_int, ':ano' => $ano_int]);
    $movimientos = $st2->fetchAll();
} catch (PDOException $e) {
    error_log('consulta.php movimientos query error: ' . $e->getMessage());
    $movimientos = [];
}

if (!$internal) {
    $expediente = public_expediente($expediente);
    $movimientos = array_map('public_movimiento', $movimientos);
}

json_response([
    'expediente' => $expediente,
    'movimientos' => $movimientos,
    'meta' => [
        'vista' => $internal ? 'interna' : 'publica',
        'movimientos_limit' => DEFAULT_LIMIT,
    ],
]);
