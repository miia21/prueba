<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

define('DB_HOST',    'localhost');
define('DB_NAME',    'sigap_expedientes');
define('DB_USER',    'admin_sql');
define('DB_PASS',    'MpSj*30673');
define('DB_CHARSET', 'utf8mb4');

// Rate limit simple
$ip  = $_SERVER['REMOTE_ADDR'] ?? 'x';
$key = sys_get_temp_dir() . '/rl_sigap_' . md5($ip);
$now = time();
$rl  = @file_get_contents($key);
$rl  = $rl ? json_decode($rl, true) : ['c'=>0,'t'=>$now];
if ($now - $rl['t'] > 60) $rl = ['c'=>0,'t'=>$now];
$rl['c']++;
@file_put_contents($key, json_encode($rl));
if ($rl['c'] > 30) {
    http_response_code(429);
    die(json_encode(['error'=>'Demasiadas solicitudes. Esperá un momento.']));
}

// Parámetros
$numero = trim($_GET['numero'] ?? '');
$letra  = strtoupper(trim($_GET['letra'] ?? ''));
$ano    = trim($_GET['ano'] ?? '');

if (!$numero || !$ano) {
    http_response_code(400);
    die(json_encode(['error'=>'Número y año son requeridos.']));
}
if (!is_numeric($numero) || !is_numeric($ano)) {
    http_response_code(400);
    die(json_encode(['error'=>'Número y año deben ser numéricos.']));
}
$ano_int    = (int)$ano;
$numero_int = (int)$numero;
if ($ano_int < 1990 || $ano_int > 2100) {
    http_response_code(400);
    die(json_encode(['error'=>'Año fuera de rango.']));
}

// Conexión
try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['error'=>'Error de base de datos.']));
}

// Consulta expediente
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

$params = [':numero'=>$numero_int, ':ano'=>$ano_int];
if ($letra !== '') {
    $sql .= " AND LETRA = :letra";
    $params[':letra'] = $letra;
}
$sql .= " LIMIT 1";

try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $expediente = $st->fetch();
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['error'=>'Error al consultar.']));
}

if (!$expediente) {
    echo json_encode(['expediente'=>null, 'movimientos'=>[]]);
    exit;
}

// Consulta movimientos
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
    LIMIT 50");
    $st2->execute([':numero'=>$numero_int, ':ano'=>$ano_int]);
    $movimientos = $st2->fetchAll();
} catch (PDOException $e) {
    $movimientos = [];
}

// Ocultar datos sensibles
unset($expediente['EMAIL'], $expediente['TELEFONO'],
      $expediente['CELULAR'], $expediente['DOMICILIO']);

echo json_encode(
    ['expediente'=>$expediente, 'movimientos'=>$movimientos],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
