<?php
/**
 * api/sync.php — Receptor de sincronización SIGAP
 * Recibe POST del script Python local, valida firma HMAC y hace UPSERT en MySQL.
 */

define('SECRET_KEY', 'CAMBIA_ESTA_CLAVE_SECRETA_MUY_LARGA_2024');
define('DB_HOST',    'localhost');
define('DB_NAME',    'sigap_expedientes');
define('DB_USER',    'admin_sql');
define('DB_PASS',    'MpSj*30673');
define('DB_CHARSET', 'utf8mb4');
define('LOG_FILE',   __DIR__ . '/sync_api.log');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error'=>'Method Not Allowed']));
}

$raw = file_get_contents('php://input');
if (empty($raw)) {
    http_response_code(400);
    die(json_encode(['error'=>'Empty body']));
}

// Validar firma HMAC
$recv_sig = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$exp_sig  = hash_hmac('sha256', $raw, SECRET_KEY);
if (!hash_equals($exp_sig, $recv_sig)) {
    http_response_code(401);
    log_msg("Firma inválida");
    die(json_encode(['error'=>'Unauthorized']));
}

$payload = json_decode($raw, true);
if (!$payload || !isset($payload['table'], $payload['data'])) {
    http_response_code(400);
    die(json_encode(['error'=>'Invalid payload']));
}

$table   = $payload['table'];
$records = $payload['data'];
$allowed = ['sectmuni','expediente','expemovi'];
if (!in_array($table, $allowed, true)) {
    http_response_code(400);
    die(json_encode(['error'=>"Tabla no permitida: $table"]));
}

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    log_msg("DB error: ".$e->getMessage());
    die(json_encode(['error'=>'DB connection failed']));
}

try {
    $pdo->beginTransaction();
    match($table) {
        'sectmuni'   => upsert_sectmuni($pdo, $records),
        'expediente' => upsert_expediente($pdo, $records),
        'expemovi'   => upsert_expemovi($pdo, $records),
    };
    $pdo->commit();
    log_msg("OK | $table | ".count($records)." registros");
    echo json_encode(['ok'=>true, 'table'=>$table, 'count'=>count($records)]);
} catch (Exception $e) {
    $pdo->rollBack();
    log_msg("ERROR | $table | ".$e->getMessage());
    http_response_code(500);
    echo json_encode(['error'=>$e->getMessage()]);
}

function upsert_sectmuni(PDO $pdo, array $records): void {
    $sql = "INSERT INTO sectmuni
        (CODIGO,DESCRIPCION,OBSERVACIONES,SECRETARIA,CARGOMAX,
         RESPONSABLE,NOMBRECORTO,CODIGOINVEN,CODIGOANTERIOR,VIGENTE)
        VALUES
        (:CODIGO,:DESCRIPCION,:OBSERVACIONES,:SECRETARIA,:CARGOMAX,
         :RESPONSABLE,:NOMBRECORTO,:CODIGOINVEN,:CODIGOANTERIOR,:VIGENTE)
        ON DUPLICATE KEY UPDATE
        DESCRIPCION=VALUES(DESCRIPCION),OBSERVACIONES=VALUES(OBSERVACIONES),
        SECRETARIA=VALUES(SECRETARIA),CARGOMAX=VALUES(CARGOMAX),
        RESPONSABLE=VALUES(RESPONSABLE),NOMBRECORTO=VALUES(NOMBRECORTO),
        CODIGOINVEN=VALUES(CODIGOINVEN),CODIGOANTERIOR=VALUES(CODIGOANTERIOR),
        VIGENTE=VALUES(VIGENTE)";
    $st = $pdo->prepare($sql);
    foreach ($records as $r) {
        $st->execute([
            ':CODIGO'=>$r['CODIGO'],':DESCRIPCION'=>$r['DESCRIPCION'],
            ':OBSERVACIONES'=>$r['OBSERVACIONES'],':SECRETARIA'=>$r['SECRETARIA'],
            ':CARGOMAX'=>$r['CARGOMAX'],':RESPONSABLE'=>$r['RESPONSABLE'],
            ':NOMBRECORTO'=>$r['NOMBRECORTO'],':CODIGOINVEN'=>$r['CODIGOINVEN'],
            ':CODIGOANTERIOR'=>$r['CODIGOANTERIOR'],':VIGENTE'=>$r['VIGENTE'],
        ]);
    }
}

function upsert_expediente(PDO $pdo, array $records): void {
    $sql = "INSERT INTO expediente
        (NUMERO,LETRA,ANO,FECHAINICIO,SECTORINICIA,EXTERNOINICIA,
         TIPODOCUM,NRODOCUM,SECTORDESTINO,EXTERNODESTINO,FECHACARGA,
         TIPOEXPEDIENTE,ESTADO,USUARIO,TEMA,MOTIVO,INICIADOR,DESTINO,
         IMPRESO,ANULADO,DOMICILIO,DEPTO,CODPOSTAL,TELEFONO,PROVINCIA,
         FOLIO,CONTABILIZADO,SECTACTUAL,CELULAR,EMAIL,PAGADO,EMPRESA,
         PERMITIDO,INCOMPLETO,CUERPOEXPE,PREFIJOEXP,EXPEORIGINAL,SECTACTUAL_NOMBRE)
        VALUES
        (:NUMERO,:LETRA,:ANO,:FECHAINICIO,:SECTORINICIA,:EXTERNOINICIA,
         :TIPODOCUM,:NRODOCUM,:SECTORDESTINO,:EXTERNODESTINO,:FECHACARGA,
         :TIPOEXPEDIENTE,:ESTADO,:USUARIO,:TEMA,:MOTIVO,:INICIADOR,:DESTINO,
         :IMPRESO,:ANULADO,:DOMICILIO,:DEPTO,:CODPOSTAL,:TELEFONO,:PROVINCIA,
         :FOLIO,:CONTABILIZADO,:SECTACTUAL,:CELULAR,:EMAIL,:PAGADO,:EMPRESA,
         :PERMITIDO,:INCOMPLETO,:CUERPOEXPE,:PREFIJOEXP,:EXPEORIGINAL,:SECTACTUAL_NOMBRE)
        ON DUPLICATE KEY UPDATE
        LETRA=VALUES(LETRA),FECHAINICIO=VALUES(FECHAINICIO),
        SECTORINICIA=VALUES(SECTORINICIA),EXTERNOINICIA=VALUES(EXTERNOINICIA),
        TIPODOCUM=VALUES(TIPODOCUM),NRODOCUM=VALUES(NRODOCUM),
        SECTORDESTINO=VALUES(SECTORDESTINO),EXTERNODESTINO=VALUES(EXTERNODESTINO),
        FECHACARGA=VALUES(FECHACARGA),TIPOEXPEDIENTE=VALUES(TIPOEXPEDIENTE),
        ESTADO=VALUES(ESTADO),USUARIO=VALUES(USUARIO),TEMA=VALUES(TEMA),
        MOTIVO=VALUES(MOTIVO),INICIADOR=VALUES(INICIADOR),DESTINO=VALUES(DESTINO),
        IMPRESO=VALUES(IMPRESO),ANULADO=VALUES(ANULADO),DOMICILIO=VALUES(DOMICILIO),
        DEPTO=VALUES(DEPTO),CODPOSTAL=VALUES(CODPOSTAL),TELEFONO=VALUES(TELEFONO),
        PROVINCIA=VALUES(PROVINCIA),FOLIO=VALUES(FOLIO),
        CONTABILIZADO=VALUES(CONTABILIZADO),SECTACTUAL=VALUES(SECTACTUAL),
        CELULAR=VALUES(CELULAR),EMAIL=VALUES(EMAIL),PAGADO=VALUES(PAGADO),
        EMPRESA=VALUES(EMPRESA),PERMITIDO=VALUES(PERMITIDO),
        INCOMPLETO=VALUES(INCOMPLETO),CUERPOEXPE=VALUES(CUERPOEXPE),
        PREFIJOEXP=VALUES(PREFIJOEXP),EXPEORIGINAL=VALUES(EXPEORIGINAL),
        SECTACTUAL_NOMBRE=VALUES(SECTACTUAL_NOMBRE)";
    $st = $pdo->prepare($sql);
    foreach ($records as $r) {
        $st->execute([
            ':NUMERO'=>$r['NUMERO'],':LETRA'=>$r['LETRA'],':ANO'=>$r['ANO'],
            ':FECHAINICIO'=>$r['FECHAINICIO'],':SECTORINICIA'=>$r['SECTORINICIA'],
            ':EXTERNOINICIA'=>$r['EXTERNOINICIA'],':TIPODOCUM'=>$r['TIPODOCUM'],
            ':NRODOCUM'=>$r['NRODOCUM'],':SECTORDESTINO'=>$r['SECTORDESTINO'],
            ':EXTERNODESTINO'=>$r['EXTERNODESTINO'],':FECHACARGA'=>$r['FECHACARGA'],
            ':TIPOEXPEDIENTE'=>$r['TIPOEXPEDIENTE'],':ESTADO'=>$r['ESTADO'],
            ':USUARIO'=>$r['USUARIO'],':TEMA'=>$r['TEMA'],':MOTIVO'=>$r['MOTIVO'],
            ':INICIADOR'=>$r['INICIADOR'],':DESTINO'=>$r['DESTINO'],
            ':IMPRESO'=>$r['IMPRESO'],':ANULADO'=>$r['ANULADO'],
            ':DOMICILIO'=>$r['DOMICILIO'],':DEPTO'=>$r['DEPTO'],
            ':CODPOSTAL'=>$r['CODPOSTAL'],':TELEFONO'=>$r['TELEFONO'],
            ':PROVINCIA'=>$r['PROVINCIA'],':FOLIO'=>$r['FOLIO'],
            ':CONTABILIZADO'=>$r['CONTABILIZADO'],':SECTACTUAL'=>$r['SECTACTUAL'],
            ':CELULAR'=>$r['CELULAR'],':EMAIL'=>$r['EMAIL'],':PAGADO'=>$r['PAGADO'],
            ':EMPRESA'=>$r['EMPRESA'],':PERMITIDO'=>$r['PERMITIDO'],
            ':INCOMPLETO'=>$r['INCOMPLETO'],':CUERPOEXPE'=>$r['CUERPOEXPE'],
            ':PREFIJOEXP'=>$r['PREFIJOEXP'],':EXPEORIGINAL'=>$r['EXPEORIGINAL'],
            ':SECTACTUAL_NOMBRE'=>$r['SECTACTUAL_NOMBRE']??'',
        ]);
    }
}

function upsert_expemovi(PDO $pdo, array $records): void {
    $sql = "INSERT INTO expemovi
        (NUMERO,FECHAHORA,SECTORACTUAL,EXTERNOACTUAL,LUGAR,ESTADOACTUAL,
         ANO,FOJAS,PERMANECIO,OBSERVACIONES,RECIBIDO,FECHARECEPCION,
         USUARIO,SECTORPROVENIENTE,CUERPOEXPEMOVI,PREFIJOEXPMOVI,SECTORACTUAL_NOMBRE)
        VALUES
        (:NUMERO,:FECHAHORA,:SECTORACTUAL,:EXTERNOACTUAL,:LUGAR,:ESTADOACTUAL,
         :ANO,:FOJAS,:PERMANECIO,:OBSERVACIONES,:RECIBIDO,:FECHARECEPCION,
         :USUARIO,:SECTORPROVENIENTE,:CUERPOEXPEMOVI,:PREFIJOEXPMOVI,:SECTORACTUAL_NOMBRE)
        ON DUPLICATE KEY UPDATE
        SECTORACTUAL=VALUES(SECTORACTUAL),EXTERNOACTUAL=VALUES(EXTERNOACTUAL),
        LUGAR=VALUES(LUGAR),ESTADOACTUAL=VALUES(ESTADOACTUAL),FOJAS=VALUES(FOJAS),
        PERMANECIO=VALUES(PERMANECIO),OBSERVACIONES=VALUES(OBSERVACIONES),
        RECIBIDO=VALUES(RECIBIDO),FECHARECEPCION=VALUES(FECHARECEPCION),
        USUARIO=VALUES(USUARIO),SECTORPROVENIENTE=VALUES(SECTORPROVENIENTE),
        CUERPOEXPEMOVI=VALUES(CUERPOEXPEMOVI),PREFIJOEXPMOVI=VALUES(PREFIJOEXPMOVI),
        SECTORACTUAL_NOMBRE=VALUES(SECTORACTUAL_NOMBRE)";
    $st = $pdo->prepare($sql);
    foreach ($records as $r) {
        $st->execute([
            ':NUMERO'=>$r['NUMERO'],':FECHAHORA'=>$r['FECHAHORA'],
            ':SECTORACTUAL'=>$r['SECTORACTUAL'],':EXTERNOACTUAL'=>$r['EXTERNOACTUAL'],
            ':LUGAR'=>$r['LUGAR'],':ESTADOACTUAL'=>$r['ESTADOACTUAL'],
            ':ANO'=>$r['ANO'],':FOJAS'=>$r['FOJAS'],':PERMANECIO'=>$r['PERMANECIO'],
            ':OBSERVACIONES'=>$r['OBSERVACIONES'],':RECIBIDO'=>$r['RECIBIDO'],
            ':FECHARECEPCION'=>$r['FECHARECEPCION'],':USUARIO'=>$r['USUARIO'],
            ':SECTORPROVENIENTE'=>$r['SECTORPROVENIENTE'],
            ':CUERPOEXPEMOVI'=>$r['CUERPOEXPEMOVI'],':PREFIJOEXPMOVI'=>$r['PREFIJOEXPMOVI'],
            ':SECTORACTUAL_NOMBRE'=>$r['SECTORACTUAL_NOMBRE']??'',
        ]);
    }
}

function log_msg(string $msg): void {
    file_put_contents(LOG_FILE, date('[Y-m-d H:i:s] ').$msg.PHP_EOL, FILE_APPEND);
}
