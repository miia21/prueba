"""
========================================================================
sigap_sync_incremental.py
========================================================================
Sincronización INCREMENTAL: solo sube lo que cambió desde la última sync.

Estrategia:
  - sectmuni   : siempre completa (tabla chica, cambia poco)
  - expediente : solo los afectados por movimientos nuevos + FECHACARGA reciente
  - expemovi   : solo movimientos con FECHAHORA > ultima_sync

USO:
  py sigap_sync_incremental.py               # incremental normal
  py sigap_sync_incremental.py --full        # fuerza sync completa
  py sigap_sync_incremental.py --test        # solo verifica conexiones
  py sigap_sync_incremental.py --config x.ini

REQUISITOS:
  py -m pip install pyodbc paramiko
========================================================================
"""

import pyodbc
import paramiko
import os
import sys
import socket
import argparse
import configparser
import zipfile
from datetime import datetime, timedelta, date

# ── Rutas base ────────────────────────────────────────────────
BASE_DIR      = os.path.dirname(os.path.abspath(__file__))
LAST_SYNC_FILE = os.path.join(BASE_DIR, "last_sync.txt")
TMP_DIR       = os.path.join(BASE_DIR, "tmp")
LOG_DIR       = os.path.join(BASE_DIR, "logs")

# ── Config por defecto ────────────────────────────────────────
CONFIG_DEFAULT = {
    "server":            "SERVIDOR-PPAL,1433",
    "database":          "SIGAP",
    "driver":            "ODBC Driver 17 for SQL Server",
    "user":              "MP",
    "password":          "Munipa*Pocito",
    "ssh_host":          "137.131.197.13",
    "ssh_port":          22,
    "ssh_user":          "ubuntu",
    "llave":             "D:/subida/pocito-server-openssh.key",
    "db_user":           "admin_sql",
    "db_pass":           "MpSj*30673",
    "db_name":           "sigap_expedientes",
    "dir_remoto":        "/tmp/",
    "dias_movimientos":  90,
}

# ── Columnas LOAD DATA ────────────────────────────────────────
COLS_SECTMUNI = (
    "(CODIGO,DESCRIPCION,OBSERVACIONES,SECRETARIA,CARGOMAX,"
    "RESPONSABLE,NOMBRECORTO,CODIGOINVEN,CODIGOANTERIOR,VIGENTE)"
)
COLS_EXPEDIENTE = (
    "(NUMERO,LETRA,ANO,FECHAINICIO,SECTORINICIA,EXTERNOINICIA,"
    "TIPODOCUM,NRODOCUM,SECTORDESTINO,EXTERNODESTINO,FECHACARGA,"
    "TIPOEXPEDIENTE,ESTADO,USUARIO,TEMA,MOTIVO,INICIADOR,DESTINO,"
    "IMPRESO,ANULADO,DOMICILIO,DEPTO,CODPOSTAL,TELEFONO,PROVINCIA,"
    "FOLIO,CONTABILIZADO,SECTACTUAL,CELULAR,EMAIL,PAGADO,EMPRESA,"
    "PERMITIDO,INCOMPLETO,CUERPOEXPE,PREFIJOEXP,EXPEORIGINAL,SECTACTUAL_NOMBRE)"
)
COLS_EXPEMOVI = (
    "(NUMERO,FECHAHORA,SECTORACTUAL,EXTERNOACTUAL,LUGAR,ESTADOACTUAL,"
    "ANO,FOJAS,PERMANECIO,OBSERVACIONES,RECIBIDO,FECHARECEPCION,"
    "USUARIO,SECTORPROVENIENTE,CUERPOEXPEMOVI,PREFIJOEXPMOVI,SECTORACTUAL_NOMBRE)"
)

# ── Queries incrementales ─────────────────────────────────────
SQL_SECTMUNI = """
SELECT
    CODIGO, ISNULL(DESCRIPCION,'') AS DESCRIPCION,
    ISNULL(OBSERVACIONES,'') AS OBSERVACIONES,
    CAST(SECRETARIA AS INT) AS SECRETARIA,
    ISNULL(CARGOMAX,'') AS CARGOMAX, ISNULL(RESPONSABLE,'') AS RESPONSABLE,
    ISNULL(NOMBRECORTO,'') AS NOMBRECORTO, ISNULL(CODIGOINVEN,'') AS CODIGOINVEN,
    ISNULL(CODIGOANTERIOR,'') AS CODIGOANTERIOR, CAST(VIGENTE AS INT) AS VIGENTE
FROM dbo.sectmuni ORDER BY CODIGO
"""

SQL_EXPEDIENTE_INC = """
SELECT
    e.NUMERO, ISNULL(e.LETRA,'') AS LETRA, e.ANO,
    CONVERT(varchar(19),e.FECHAINICIO,120) AS FECHAINICIO,
    ISNULL(e.SECTORINICIA,'') AS SECTORINICIA,
    ISNULL(e.EXTERNOINICIA,'') AS EXTERNOINICIA,
    ISNULL(e.TIPODOCUM,'') AS TIPODOCUM,
    ISNULL(e.NRODOCUM,0) AS NRODOCUM,
    ISNULL(e.SECTORDESTINO,'') AS SECTORDESTINO,
    ISNULL(e.EXTERNODESTINO,'') AS EXTERNODESTINO,
    CONVERT(varchar(19),e.FECHACARGA,120) AS FECHACARGA,
    ISNULL(e.TIPOEXPEDIENTE,'') AS TIPOEXPEDIENTE,
    ISNULL(e.ESTADO,'') AS ESTADO,
    ISNULL(e.USUARIO,'') AS USUARIO,
    ISNULL(e.TEMA,'') AS TEMA,
    REPLACE(ISNULL(e.MOTIVO,''),';',',') AS MOTIVO,
    ISNULL(e.INICIADOR,'') AS INICIADOR, ISNULL(e.DESTINO,'') AS DESTINO,
    CAST(e.IMPRESO AS INT) AS IMPRESO, CAST(e.ANULADO AS INT) AS ANULADO,
    REPLACE(ISNULL(e.DOMICILIO,''),';',',') AS DOMICILIO,
    ISNULL(e.DEPTO,'') AS DEPTO, ISNULL(e.CODPOSTAL,'') AS CODPOSTAL,
    ISNULL(e.TELEFONO,0) AS TELEFONO, ISNULL(e.PROVINCIA,'') AS PROVINCIA,
    ISNULL(e.FOLIO,0) AS FOLIO, CAST(e.CONTABILIZADO AS INT) AS CONTABILIZADO,
    ISNULL(e.SECTACTUAL,'') AS SECTACTUAL,
    ISNULL(e.CELULAR,'') AS CELULAR, ISNULL(e.EMAIL,'') AS EMAIL,
    CAST(e.PAGADO AS INT) AS PAGADO, ISNULL(e.EMPRESA,'') AS EMPRESA,
    CAST(e.PERMITIDO AS INT) AS PERMITIDO, CAST(e.INCOMPLETO AS INT) AS INCOMPLETO,
    ISNULL(e.CUERPOEXPE,1) AS CUERPOEXPE, ISNULL(e.PREFIJOEXP,'') AS PREFIJOEXP,
    ISNULL(e.EXPEORIGINAL,0) AS EXPEORIGINAL,
    ISNULL(s.NOMBRECORTO,'') AS SECTACTUAL_NOMBRE
FROM dbo.expediente e
LEFT JOIN dbo.sectmuni s ON s.CODIGO = e.SECTACTUAL
WHERE
    -- Expedientes con movimientos nuevos
    e.NUMERO IN (
        SELECT DISTINCT NUMERO FROM dbo.expemovi WHERE FECHAHORA > ?
    )
    -- Expedientes recibidos (RECIBIDO cambia 0→1, SECTACTUAL cambia)
    OR e.NUMERO IN (
        SELECT DISTINCT NUMERO FROM dbo.expemovi
        WHERE RECIBIDO = 1 AND FECHARECEPCION > ?
    )
    -- Expedientes recién cargados
    OR e.FECHACARGA > ?
ORDER BY e.ANO DESC, e.NUMERO DESC
"""

SQL_EXPEMOVI_INC = """
SELECT
    m.NUMERO,
    CONVERT(varchar(19),m.FECHAHORA,120) AS FECHAHORA,
    ISNULL(m.SECTORACTUAL,'') AS SECTORACTUAL,
    ISNULL(m.EXTERNOACTUAL,'') AS EXTERNOACTUAL,
    ISNULL(m.LUGAR,'') AS LUGAR,
    ISNULL(m.ESTADOACTUAL,'') AS ESTADOACTUAL,
    m.ANO,
    ISNULL(m.FOJAS,0) AS FOJAS,
    ISNULL(m.PERMANECIO,0) AS PERMANECIO,
    REPLACE(ISNULL(m.OBSERVACIONES,''),';',',') AS OBSERVACIONES,
    CAST(m.RECIBIDO AS INT) AS RECIBIDO,
    CONVERT(varchar(19),m.FECHARECEPCION,120) AS FECHARECEPCION,
    ISNULL(m.USUARIO,'') AS USUARIO,
    REPLACE(ISNULL(m.SECTORPROVENIENTE,''),';',',') AS SECTORPROVENIENTE,
    ISNULL(m.CUERPOEXPEMOVI,1) AS CUERPOEXPEMOVI,
    ISNULL(m.PREFIJOEXPMOVI,'') AS PREFIJOEXPMOVI,
    ISNULL(s.NOMBRECORTO,'') AS SECTORACTUAL_NOMBRE
FROM dbo.expemovi m
LEFT JOIN dbo.sectmuni s ON s.CODIGO = m.SECTORACTUAL
WHERE
    -- Movimientos nuevos
    m.FECHAHORA > ?
    -- O movimientos que fueron recibidos (RECIBIDO 0→1) desde ultima sync
    OR (m.RECIBIDO = 1 AND m.FECHARECEPCION > ?)
ORDER BY m.ANO DESC, m.NUMERO ASC, m.FECHAHORA ASC
"""

# Chequeo rápido: ¿hay algo nuevo desde ultima_sync?
# Detecta: movimientos nuevos O recepciones nuevas (RECIBIDO cambia de 0 a 1)
SQL_HAY_CAMBIOS = """
SELECT COUNT(*) FROM dbo.expemovi
WHERE FECHAHORA > ?
   OR (RECIBIDO = 1 AND FECHARECEPCION > ?)
"""


# ============================================================
# LOGGER
# ============================================================
class Logger:
    def __init__(self):
        self._terminal = sys.__stdout__
        os.makedirs(LOG_DIR, exist_ok=True)
        ts       = datetime.now().strftime("%Y%m%d_%H%M%S")
        ruta_log = os.path.join(LOG_DIR, f"sync_incremental_{ts}.log")
        self._log = open(ruta_log, "a", encoding="utf-8")
        print(f"  [LOG] {ruta_log}", file=self._terminal)

    def write(self, msg):
        try:
            self._terminal.write(msg)
        except UnicodeEncodeError:
            self._terminal.write(msg.encode("ascii", errors="replace").decode("ascii"))
        self._log.write(msg)

    def flush(self):
        self._terminal.flush()
        self._log.flush()


# ============================================================
# HELPERS
# ============================================================
def sep(c="=", n=70): print(c * n)

def cargar_config(config_file: str) -> dict:
    cfg = CONFIG_DEFAULT.copy()
    if not os.path.exists(config_file):
        print(f"[!] {config_file} no encontrado, usando valores por defecto.")
        return cfg
    parser = configparser.ConfigParser()
    parser.read(config_file, encoding="utf-8")
    if "sqlserver" in parser:
        s = parser["sqlserver"]
        for k in ("server","database","driver","user","password"):
            cfg[k] = s.get(k, cfg[k])
    if "nube" in parser:
        n = parser["nube"]
        for k in ("ssh_host","ssh_user","llave","db_user","db_pass","db_name","dir_remoto"):
            cfg[k] = n.get(k, cfg[k])
        cfg["ssh_port"]         = int(n.get("ssh_port", str(cfg["ssh_port"])))
        cfg["dias_movimientos"] = int(n.get("dias_movimientos", str(cfg["dias_movimientos"])))
    print(f"[OK] Config: {config_file}")
    return cfg


def leer_ultima_sync() -> datetime:
    """Lee el timestamp de la última sync exitosa. Si no existe, usa hace 24hs."""
    if os.path.exists(LAST_SYNC_FILE):
        try:
            txt = open(LAST_SYNC_FILE, "r").read().strip()
            ts  = datetime.fromisoformat(txt)
            print(f"[OK] Última sync: {ts.strftime('%d/%m/%Y %H:%M:%S')}")
            return ts
        except Exception:
            pass
    fallback = datetime.now() - timedelta(hours=24)
    print(f"[!] Sin registro previo. Usando últimas 24hs: {fallback.strftime('%d/%m/%Y %H:%M:%S')}")
    return fallback


def guardar_ultima_sync(ts: datetime):
    """Guarda el timestamp de sync exitosa."""
    with open(LAST_SYNC_FILE, "w") as f:
        f.write(ts.isoformat())
    print(f"[OK] Timestamp guardado: {ts.strftime('%d/%m/%Y %H:%M:%S')}")


def conectar_sql(cfg: dict):
    conn_str = (
        f"DRIVER={{{cfg['driver']}}};"
        f"SERVER={cfg['server']};"
        f"DATABASE={cfg['database']};"
        f"UID={cfg['user']};"
        f"PWD={cfg['password']};"
    )
    try:
        conn = pyodbc.connect(conn_str, timeout=60)
        print(f"[OK] SQL Server: {cfg['server']} / {cfg['database']}")
        return conn
    except pyodbc.Error as e:
        print(f"[ERR] SQL Server: {e}")
        raise


def hay_cambios(cfg: dict, desde: datetime) -> int:
    """Consulta rápida: devuelve cantidad de movimientos nuevos."""
    conn   = conectar_sql(cfg)
    cursor = conn.cursor()
    cursor.execute(SQL_HAY_CAMBIOS, desde, desde)   # param x2: FECHAHORA y FECHARECEPCION
    n = cursor.fetchone()[0]
    conn.close()
    return n


def limpiar(v) -> str:
    return "" if v is None else str(v).strip()


def escribir_txt(ruta: str, cursor, titulo: str) -> int:
    lineas = []
    for row in cursor.fetchall():
        campos = []
        for v in row:
            if v is None:
                campos.append("")
            else:
                campos.append(
                    str(v).replace("\r\n", " ").replace("\n", " ").replace("\r", " ")
                )
        lineas.append(";".join(campos) + "\r\n")
    with open(ruta, "w", encoding="utf-8") as f:
        f.writelines(lineas)
    print(f"  {titulo}: {len(lineas):,} registros")
    return len(lineas)


# ============================================================
# GENERAR ZIP INCREMENTAL
# ============================================================
def generar_zip_incremental(cfg: dict, desde: datetime, forzar_completa: bool) -> tuple:
    """
    Genera ZIP con solo los cambios desde `desde`.
    Devuelve (ruta_zip, stats_dict).
    """
    sep()
    modo = "COMPLETA (forzada)" if forzar_completa else f"INCREMENTAL desde {desde.strftime('%d/%m/%Y %H:%M')}"
    print(f"[PASO 1/2] GENERANDO archivos — {modo}")
    sep()

    os.makedirs(TMP_DIR, exist_ok=True)
    fecha_str  = datetime.now().strftime("%Y%m%d_%H%M%S")
    nombre_zip = f"sigap_inc_{fecha_str}.zip"
    ruta_zip   = os.path.join(TMP_DIR, nombre_zip)

    conn   = conectar_sql(cfg)
    cursor = conn.cursor()

    archivos = []
    stats    = {}

    # --- sectmuni: siempre completa ---
    print("  Consultando sectmuni (completa)...", end=" ", flush=True)
    cursor.execute(SQL_SECTMUNI)
    ruta = os.path.join(TMP_DIR, f"sectmuni_{fecha_str}.txt")
    stats["sectmuni"] = escribir_txt(ruta, cursor, "sectmuni")
    archivos.append(("sectmuni", ruta))

    # --- expediente: incremental o completa ---
    if forzar_completa:
        from sigap_sync import SQL_EXPEDIENTE
        print("  Consultando expediente (completa)...", end=" ", flush=True)
        cursor.execute(SQL_EXPEDIENTE)
    else:
        print("  Consultando expediente (incremental)...", end=" ", flush=True)
        cursor.execute(SQL_EXPEDIENTE_INC, desde, desde, desde)  # FECHAHORA, FECHARECEPCION, FECHACARGA
    ruta = os.path.join(TMP_DIR, f"expediente_{fecha_str}.txt")
    stats["expediente"] = escribir_txt(ruta, cursor, "expediente")
    archivos.append(("expediente", ruta))

    # --- expemovi: incremental o completa ---
    if forzar_completa:
        desde_movi = datetime.now() - timedelta(days=int(cfg.get("dias_movimientos", 90)))
        print(f"  Consultando expemovi (últimos {cfg['dias_movimientos']} días)...", end=" ", flush=True)
        cursor.execute(
            "SELECT m.NUMERO, CONVERT(varchar(19),m.FECHAHORA,120), "
            "ISNULL(m.SECTORACTUAL,''), ISNULL(m.EXTERNOACTUAL,''), "
            "ISNULL(m.LUGAR,''), ISNULL(m.ESTADOACTUAL,''), m.ANO, "
            "ISNULL(m.FOJAS,0), ISNULL(m.PERMANECIO,0), "
            "REPLACE(ISNULL(m.OBSERVACIONES,''),';',','), "
            "CAST(m.RECIBIDO AS INT), "
            "CONVERT(varchar(19),m.FECHARECEPCION,120), "
            "ISNULL(m.USUARIO,''), "
            "REPLACE(ISNULL(m.SECTORPROVENIENTE,''),';',','), "
            "ISNULL(m.CUERPOEXPEMOVI,1), ISNULL(m.PREFIJOEXPMOVI,''), "
            "ISNULL(s.NOMBRECORTO,'') "
            "FROM dbo.expemovi m "
            "LEFT JOIN dbo.sectmuni s ON s.CODIGO = m.SECTORACTUAL "
            "WHERE m.FECHAHORA >= ? "
            "ORDER BY m.ANO DESC, m.NUMERO ASC, m.FECHAHORA ASC",
            desde_movi
        )
    else:
        print("  Consultando expemovi (incremental)...", end=" ", flush=True)
        cursor.execute(SQL_EXPEMOVI_INC, desde, desde)   # FECHAHORA y FECHARECEPCION
    ruta = os.path.join(TMP_DIR, f"expemovi_{fecha_str}.txt")
    stats["expemovi"] = escribir_txt(ruta, cursor, "expemovi")
    archivos.append(("expemovi", ruta))

    conn.close()

    # Comprimir
    with zipfile.ZipFile(ruta_zip, "w", zipfile.ZIP_DEFLATED) as zf:
        for _, ruta_txt in archivos:
            zf.write(ruta_txt, os.path.basename(ruta_txt))

    tam_kb = os.path.getsize(ruta_zip) / 1024
    sep("-")
    print(f"  ZIP: {ruta_zip}  ({tam_kb:.1f} KB)")
    sep("-")

    # Limpiar TXT locales
    for _, ruta_txt in archivos:
        try: os.remove(ruta_txt)
        except: pass

    return ruta_zip, stats, [os.path.basename(r) for _, r in archivos]


# ============================================================
# SUBIR Y APLICAR EN MYSQL
# ============================================================
def subir_incremental(cfg: dict, ruta_zip: str, nombres_txt: list, stats: dict) -> bool:
    sep()
    print("[PASO 2/2] SUBIENDO cambios a la nube")
    sep()

    dir_remoto = cfg["dir_remoto"]
    db_user    = cfg["db_user"]
    db_pass    = cfg["db_pass"]
    db_name    = cfg["db_name"]
    nombre_zip = os.path.basename(ruta_zip)

    # Mapear nombre de archivo → tabla
    tabla_map = {}
    for nombre in nombres_txt:
        for tabla in ("sectmuni", "expediente", "expemovi"):
            if nombre.startswith(tabla):
                tabla_map[nombre] = tabla

    cols_map = {
        "sectmuni":   COLS_SECTMUNI,
        "expediente": COLS_EXPEDIENTE,
        "expemovi":   COLS_EXPEMOVI,
    }

    client = None
    sftp   = None

    try:
        # SSH
        print(f"  Conectando SSH {cfg['ssh_host']}:{cfg['ssh_port']}...", end=" ", flush=True)
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        llave  = paramiko.RSAKey.from_private_key_file(cfg["llave"])
        client.connect(
            cfg["ssh_host"], port=cfg["ssh_port"],
            username=cfg["ssh_user"], pkey=llave,
            timeout=30, banner_timeout=30
        )
        print("OK")

        # SFTP
        sftp = client.open_sftp()
        print(f"  Subiendo {nombre_zip}...", end=" ", flush=True)
        sftp.put(ruta_zip, dir_remoto + nombre_zip)
        sftp.close(); sftp = None
        print("OK")

        # Descomprimir
        print("  Descomprimiendo...", end=" ", flush=True)
        _, stdout, stderr = client.exec_command(
            f"cd {dir_remoto} && unzip -o {nombre_zip} && rm -f {nombre_zip}"
        )
        stdout.channel.recv_exit_status()
        err = stderr.read().decode()
        if err and "error" in err.lower():
            raise RuntimeError(f"Error unzip: {err}")
        print("OK")

        # Cargar cada tabla
        for nombre_txt, tabla in tabla_map.items():
            cols        = cols_map[tabla]
            ruta_remota = dir_remoto + nombre_txt
            n_registros = stats.get(tabla, 0)

            if n_registros == 0:
                print(f"  {tabla}: sin cambios, omitido.")
                client.exec_command(f"rm -f {ruta_remota}")
                continue

            print(f"  Cargando {tabla} ({n_registros:,} registros)...", end=" ", flush=True)

            # sectmuni siempre TRUNCATE; expediente y expemovi usan INSERT ... ON DUPLICATE KEY
            if tabla == "sectmuni":
                sql_load = (
                    f"TRUNCATE TABLE {tabla}; "
                    f"LOAD DATA LOCAL INFILE '{ruta_remota}' "
                    f"INTO TABLE {tabla} CHARACTER SET utf8mb4 "
                    f"FIELDS TERMINATED BY ';' LINES TERMINATED BY '\\r\\n' "
                    f"{cols};"
                )
            else:
                # Carga en tabla temporal y luego UPSERT
                tmp = f"tmp_{tabla}"
                sql_load = (
                    f"CREATE TEMPORARY TABLE {tmp} LIKE {tabla}; "
                    f"LOAD DATA LOCAL INFILE '{ruta_remota}' "
                    f"INTO TABLE {tmp} CHARACTER SET utf8mb4 "
                    f"FIELDS TERMINATED BY ';' LINES TERMINATED BY '\\r\\n' "
                    f"{cols}; "
                    f"REPLACE INTO {tabla} SELECT * FROM {tmp}; "
                    f"DROP TEMPORARY TABLE {tmp};"
                )

            cmd = (
                f"mysql --local-infile=1 -u {db_user} -p'{db_pass}' {db_name} "
                f"-e \"{sql_load}\" 2>&1"
            )
            _, stdout, _ = client.exec_command(cmd)
            resultado = stdout.read().decode()
            if resultado and "ERROR" in resultado.upper():
                raise RuntimeError(f"MySQL error en {tabla}: {resultado}")
            print("OK")

            # Limpiar remoto
            client.exec_command(f"rm -f {ruta_remota}")

        sep()
        print("[OK] SYNC INCREMENTAL COMPLETADA")
        sep()
        return True

    except paramiko.AuthenticationException:
        print(f"\n[ERR] Auth SSH fallida. Verificar llave: {cfg['llave']}")
        return False
    except Exception as e:
        print(f"\n[ERR] {e}")
        import traceback; traceback.print_exc()
        if client:
            try:
                for n in nombres_txt:
                    client.exec_command(f"rm -f {dir_remoto}{n}")
                client.exec_command(f"rm -f {dir_remoto}{nombre_zip}")
            except: pass
        return False
    finally:
        if sftp:
            try: sftp.close()
            except: pass
        if client:
            client.close()
            print("[SSH] Conexión cerrada.")


# ============================================================
# VERIFICAR PREREQUISITOS
# ============================================================
def verificar(cfg: dict) -> bool:
    sep()
    print("[?] VERIFICACIÓN DE PREREQUISITOS")
    sep()
    ok = True

    print(f"  Llave SSH : {cfg['llave']}")
    if not os.path.exists(cfg["llave"]):
        print("    [ERR] No encontrada"); ok = False
    else:
        try:
            paramiko.RSAKey.from_private_key_file(cfg["llave"])
            print("    [OK]  Válida")
        except Exception as e:
            print(f"    [ERR] {e}"); ok = False

    print(f"  SSH       : {cfg['ssh_host']}:{cfg['ssh_port']}")
    try:
        s = socket.socket(); s.settimeout(5)
        r = s.connect_ex((cfg["ssh_host"], cfg["ssh_port"])); s.close()
        print("    [OK]  Puerto accesible" if r==0 else "    [ERR] Puerto no accesible")
        if r != 0: ok = False
    except Exception as e:
        print(f"    [ERR] {e}"); ok = False

    print(f"  SQL Server: {cfg['server']} / {cfg['database']}")
    try:
        conn = pyodbc.connect(
            f"DRIVER={{{cfg['driver']}}};SERVER={cfg['server']};"
            f"DATABASE={cfg['database']};UID={cfg['user']};PWD={cfg['password']};",
            timeout=10
        )
        conn.close()
        print("    [OK]  Conexión exitosa")
    except Exception as e:
        print(f"    [ERR] {e}"); ok = False

    print(f"  last_sync : {LAST_SYNC_FILE}")
    if os.path.exists(LAST_SYNC_FILE):
        print(f"    [OK]  {open(LAST_SYNC_FILE).read().strip()}")
    else:
        print("    [!]   No existe aún (se creará en primera sync)")

    sep()
    print("[OK] Todo listo." if ok else "[ERR] Corregir errores antes de continuar.")
    sep()
    return ok


# ============================================================
# MAIN
# ============================================================
def main():
    parser = argparse.ArgumentParser(description="Sync incremental SIGAP → MySQL nube")
    parser.add_argument("--config",  default=os.path.join(BASE_DIR, "config.ini"))
    parser.add_argument("--test",    action="store_true", help="Solo verifica conexiones")
    parser.add_argument("--full",    action="store_true", help="Fuerza sync completa")
    args = parser.parse_args()

    sep()
    print("  SIGAP SYNC INCREMENTAL - Municipalidad de Pocito")
    print(f"  {datetime.now().strftime('%d/%m/%Y  %H:%M:%S')}")
    sep()

    cfg = cargar_config(args.config)

    # Logger a archivo
    sys.stdout = Logger()
    sys.stderr = sys.stdout

    if args.test:
        verificar(cfg)
        sys.exit(0)

    if not verificar(cfg):
        sys.exit(1)

    desde = leer_ultima_sync()
    ts_inicio = datetime.now()

    # Verificar si hay cambios (salvo --full)
    if not args.full:
        print(f"  Verificando cambios desde {desde.strftime('%d/%m/%Y %H:%M')}...", end=" ", flush=True)
        try:
            n = hay_cambios(cfg, desde)
            print(f"{n} movimientos nuevos")
            if n == 0:
                print("[OK] Sin cambios. No es necesario sincronizar.")
                sys.exit(0)
        except Exception as e:
            print(f"\n[ERR] No se pudo verificar cambios: {e}")
            sys.exit(1)

    ruta_zip, stats, nombres_txt = generar_zip_incremental(cfg, desde, args.full)
    exito = subir_incremental(cfg, ruta_zip, nombres_txt, stats)

    # Limpiar ZIP local
    try: os.remove(ruta_zip)
    except: pass

    if exito:
        guardar_ultima_sync(ts_inicio)
        sys.exit(0)
    else:
        print("[ERR] Sync falló. No se actualiza last_sync.txt para reintentar.")
        sys.exit(1)


if __name__ == "__main__":
    main()
