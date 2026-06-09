"""
========================================================================
sigap_sync.py
========================================================================
Proceso UNIFICADO: exporta expedientes, movimientos y sectores desde
SQL Server SIGAP (produccion) y los sube directamente a MySQL en la nube.

PASOS INTERNOS:
  1. Conecta a SQL Server SIGAP (SERVIDOR-PPAL,1433)
  2. Consulta tablas: sectmuni, expediente, expemovi
  3. Escribe 3 archivos TXT separados por ; en D:/Arc_Diarios
  4. Comprime en sigap_expedientes_YYYYMMDD.zip
  5. Conecta al servidor en la nube via SSH/SFTP
  6. Sube el ZIP, lo descomprime y carga en MySQL (TRUNCATE + LOAD DATA)
  7. Verifica recuento y muestra muestra de registros
  8. Limpia archivos temporales remotos

USO:
  py sigap_sync.py                  # proceso completo
  py sigap_sync.py --test           # solo verifica conexiones
  py sigap_sync.py --solo-generar   # genera ZIP sin subir
  py sigap_sync.py --solo-subir     # sube ZIP ya existente
  py sigap_sync.py --config mi.ini  # usa config alternativo

REQUISITOS:
  py -m pip install pyodbc paramiko

CONFIG (config.ini):
  [sqlserver]
  server   = SERVIDOR-PPAL,1433
  database = SIGAP
  driver   = ODBC Driver 17 for SQL Server
  user     = MP
  password = Munipa*Pocito

  [output]
  dir = D:/Arc_Diarios

  [nube]
  ssh_host   = 137.131.197.13
  ssh_port   = 22
  ssh_user   = ubuntu
  llave      = D:/subida/pocito-server-openssh.key
  db_user    = admin_sql
  db_pass    = MpSj*30673
  db_name    = sigap_expedientes
  dir_remoto = /tmp/
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
from datetime import date, datetime, timedelta


# ============================================================
# CONFIGURACION POR DEFECTO (se sobreescribe con config.ini)
# ============================================================
CONFIG_DEFAULT = {
    "server":     "SERVIDOR-PPAL,1433",
    "database":   "SIGAP",
    "driver":     "ODBC Driver 17 for SQL Server",
    "user":       "MP",
    "password":   "Munipa*Pocito",
    "output_dir": "D:/Arc_Diarios",
    "ssh_host":   "137.131.197.13",
    "ssh_port":   22,
    "ssh_user":   "ubuntu",
    "llave":      "D:/subida/pocito-server-openssh.key",
    "db_user":    "admin_sql",
    "db_pass":    "MpSj*30673",
    "db_name":    "sigap_expedientes",
    "dir_remoto": "/tmp/",
    "dias_movimientos": 90,
}

# Columnas para LOAD DATA — deben coincidir exactamente con las del TXT
COLS_SECTMUNI = (
    "(CODIGO, DESCRIPCION, OBSERVACIONES, SECRETARIA, CARGOMAX, "
    "RESPONSABLE, NOMBRECORTO, CODIGOINVEN, CODIGOANTERIOR, VIGENTE)"
)

COLS_EXPEDIENTE = (
    "(NUMERO, LETRA, ANO, FECHAINICIO, SECTORINICIA, EXTERNOINICIA, "
    "TIPODOCUM, NRODOCUM, SECTORDESTINO, EXTERNODESTINO, FECHACARGA, "
    "TIPOEXPEDIENTE, ESTADO, USUARIO, TEMA, MOTIVO, INICIADOR, DESTINO, "
    "IMPRESO, ANULADO, DOMICILIO, DEPTO, CODPOSTAL, TELEFONO, PROVINCIA, "
    "FOLIO, CONTABILIZADO, SECTACTUAL, CELULAR, EMAIL, PAGADO, EMPRESA, "
    "PERMITIDO, INCOMPLETO, CUERPOEXPE, PREFIJOEXP, EXPEORIGINAL, SECTACTUAL_NOMBRE)"
)

COLS_EXPEMOVI = (
    "(NUMERO, FECHAHORA, SECTORACTUAL, EXTERNOACTUAL, LUGAR, ESTADOACTUAL, "
    "ANO, FOJAS, PERMANECIO, OBSERVACIONES, RECIBIDO, FECHARECEPCION, "
    "USUARIO, SECTORPROVENIENTE, CUERPOEXPEMOVI, PREFIJOEXPMOVI, SECTORACTUAL_NOMBRE)"
)


# ============================================================
# QUERIES SQL SERVER
# ============================================================

SQL_SECTMUNI = """
SELECT
    CODIGO,
    ISNULL(DESCRIPCION, '')                     AS DESCRIPCION,
    ISNULL(OBSERVACIONES, '')                   AS OBSERVACIONES,
    CAST(SECRETARIA AS INT)                     AS SECRETARIA,
    ISNULL(CARGOMAX, '')                        AS CARGOMAX,
    ISNULL(RESPONSABLE, '')                     AS RESPONSABLE,
    ISNULL(NOMBRECORTO, '')                     AS NOMBRECORTO,
    ISNULL(CODIGOINVEN, '')                     AS CODIGOINVEN,
    ISNULL(CODIGOANTERIOR, '')                  AS CODIGOANTERIOR,
    CAST(VIGENTE AS INT)                        AS VIGENTE
FROM dbo.sectmuni
ORDER BY CODIGO
"""

SQL_EXPEDIENTE = """
SELECT
    e.NUMERO,
    ISNULL(e.LETRA, '')                                     AS LETRA,
    e.ANO,
    CONVERT(varchar(19), e.FECHAINICIO, 120)                AS FECHAINICIO,
    ISNULL(e.SECTORINICIA, '')                              AS SECTORINICIA,
    ISNULL(e.EXTERNOINICIA, '')                             AS EXTERNOINICIA,
    ISNULL(e.TIPODOCUM, '')                                 AS TIPODOCUM,
    ISNULL(e.NRODOCUM, 0)                                   AS NRODOCUM,
    ISNULL(e.SECTORDESTINO, '')                             AS SECTORDESTINO,
    ISNULL(e.EXTERNODESTINO, '')                            AS EXTERNODESTINO,
    CONVERT(varchar(19), e.FECHACARGA, 120)                 AS FECHACARGA,
    ISNULL(e.TIPOEXPEDIENTE, '')                            AS TIPOEXPEDIENTE,
    ISNULL(e.ESTADO, '')                                    AS ESTADO,
    ISNULL(e.USUARIO, '')                                   AS USUARIO,
    ISNULL(e.TEMA, '')                                      AS TEMA,
    REPLACE(ISNULL(e.MOTIVO, ''), ';', ',')                 AS MOTIVO,
    ISNULL(e.INICIADOR, '')                                 AS INICIADOR,
    ISNULL(e.DESTINO, '')                                   AS DESTINO,
    CAST(e.IMPRESO AS INT)                                  AS IMPRESO,
    CAST(e.ANULADO AS INT)                                  AS ANULADO,
    REPLACE(ISNULL(e.DOMICILIO, ''), ';', ',')              AS DOMICILIO,
    ISNULL(e.DEPTO, '')                                     AS DEPTO,
    ISNULL(e.CODPOSTAL, '')                                 AS CODPOSTAL,
    ISNULL(e.TELEFONO, 0)                                   AS TELEFONO,
    ISNULL(e.PROVINCIA, '')                                 AS PROVINCIA,
    ISNULL(e.FOLIO, 0)                                      AS FOLIO,
    CAST(e.CONTABILIZADO AS INT)                            AS CONTABILIZADO,
    ISNULL(e.SECTACTUAL, '')                                AS SECTACTUAL,
    ISNULL(e.CELULAR, '')                                   AS CELULAR,
    ISNULL(e.EMAIL, '')                                     AS EMAIL,
    CAST(e.PAGADO AS INT)                                   AS PAGADO,
    ISNULL(e.EMPRESA, '')                                   AS EMPRESA,
    CAST(e.PERMITIDO AS INT)                                AS PERMITIDO,
    CAST(e.INCOMPLETO AS INT)                               AS INCOMPLETO,
    ISNULL(e.CUERPOEXPE, 1)                                 AS CUERPOEXPE,
    ISNULL(e.PREFIJOEXP, '')                                AS PREFIJOEXP,
    ISNULL(e.EXPEORIGINAL, 0)                               AS EXPEORIGINAL,
    ISNULL(s.NOMBRECORTO, '')                               AS SECTACTUAL_NOMBRE
FROM dbo.expediente e
LEFT JOIN dbo.sectmuni s ON s.CODIGO = e.SECTACTUAL
ORDER BY e.ANO DESC, e.NUMERO DESC
"""

SQL_EXPEMOVI = """
SELECT
    m.NUMERO,
    CONVERT(varchar(19), m.FECHAHORA, 120)                  AS FECHAHORA,
    ISNULL(m.SECTORACTUAL, '')                              AS SECTORACTUAL,
    ISNULL(m.EXTERNOACTUAL, '')                             AS EXTERNOACTUAL,
    ISNULL(m.LUGAR, '')                                     AS LUGAR,
    ISNULL(m.ESTADOACTUAL, '')                              AS ESTADOACTUAL,
    m.ANO,
    ISNULL(m.FOJAS, 0)                                      AS FOJAS,
    ISNULL(m.PERMANECIO, 0)                                 AS PERMANECIO,
    REPLACE(ISNULL(m.OBSERVACIONES, ''), ';', ',')          AS OBSERVACIONES,
    CAST(m.RECIBIDO AS INT)                                 AS RECIBIDO,
    CONVERT(varchar(19), m.FECHARECEPCION, 120)             AS FECHARECEPCION,
    ISNULL(m.USUARIO, '')                                   AS USUARIO,
    REPLACE(ISNULL(m.SECTORPROVENIENTE, ''), ';', ',')      AS SECTORPROVENIENTE,
    ISNULL(m.CUERPOEXPEMOVI, 1)                             AS CUERPOEXPEMOVI,
    ISNULL(m.PREFIJOEXPMOVI, '')                            AS PREFIJOEXPMOVI,
    ISNULL(s.NOMBRECORTO, '')                               AS SECTORACTUAL_NOMBRE
FROM dbo.expemovi m
LEFT JOIN dbo.sectmuni s ON s.CODIGO = m.SECTORACTUAL
WHERE m.FECHAHORA >= ?
ORDER BY m.ANO DESC, m.NUMERO ASC, m.FECHAHORA ASC
"""


# ============================================================
# LOGGER
# ============================================================
class Logger:
    def __init__(self, dir_logs: str):
        self._terminal = sys.__stdout__
        os.makedirs(dir_logs, exist_ok=True)
        ts       = datetime.now().strftime("%Y%m%d_%H%M%S")
        ruta_log = os.path.join(dir_logs, f"log_sigap_sync_{ts}.txt")
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

def separador(char="=", ancho=70):
    print(char * ancho)


def cargar_config(config_file: str) -> dict:
    cfg = CONFIG_DEFAULT.copy()
    if not os.path.exists(config_file):
        print(f"[!] config.ini no encontrado en '{config_file}', usando valores por defecto.")
        return cfg

    parser = configparser.ConfigParser()
    parser.read(config_file, encoding="utf-8")

    if "sqlserver" in parser:
        s = parser["sqlserver"]
        cfg["server"]   = s.get("server",   cfg["server"])
        cfg["database"] = s.get("database", cfg["database"])
        cfg["driver"]   = s.get("driver",   cfg["driver"])
        cfg["user"]     = s.get("user",     cfg["user"])
        cfg["password"] = s.get("password", cfg["password"])

    if "output" in parser:
        cfg["output_dir"] = parser["output"].get("dir", cfg["output_dir"])

    if "nube" in parser:
        n = parser["nube"]
        cfg["ssh_host"]          = n.get("ssh_host",          cfg["ssh_host"])
        cfg["ssh_port"]          = int(n.get("ssh_port",      str(cfg["ssh_port"])))
        cfg["ssh_user"]          = n.get("ssh_user",          cfg["ssh_user"])
        cfg["llave"]             = n.get("llave",             cfg["llave"])
        cfg["db_user"]           = n.get("db_user",           cfg["db_user"])
        cfg["db_pass"]           = n.get("db_pass",           cfg["db_pass"])
        cfg["db_name"]           = n.get("db_name",           cfg["db_name"])
        cfg["dir_remoto"]        = n.get("dir_remoto",        cfg["dir_remoto"])
        cfg["dias_movimientos"]  = int(n.get("dias_movimientos", str(cfg["dias_movimientos"])))

    print(f"[OK] Config cargada desde: {config_file}")
    return cfg


def conectar_sqlserver(cfg: dict):
    conn_str = (
        f"DRIVER={{{cfg['driver']}}};"
        f"SERVER={cfg['server']};"
        f"DATABASE={cfg['database']};"
        f"UID={cfg['user']};"
        f"PWD={cfg['password']};"
    )
    try:
        conn = pyodbc.connect(conn_str, timeout=60)
        print(f"[OK] SQL Server conectado: {cfg['server']} / {cfg['database']}")
        return conn
    except pyodbc.Error as e:
        print(f"[ERR] No se pudo conectar a SQL Server: {e}")
        sys.exit(1)


def limpiar(v) -> str:
    return "" if v is None else str(v).strip()


def escribir_txt(ruta: str, cursor, titulo: str) -> int:
    """Escribe los resultados del cursor en un TXT separado por ; y devuelve cantidad de filas."""
    cols  = [col[0] for col in cursor.description]
    lineas = []
    for row in cursor.fetchall():
        campos = []
        for v in row:
            if v is None:
                campos.append("")
            else:
                campos.append(str(v).replace("\r\n", " ").replace("\n", " ").replace("\r", " "))
        lineas.append(";".join(campos) + "\r\n")

    with open(ruta, "w", encoding="utf-8") as f:
        f.writelines(lineas)

    print(f"  {titulo}: {len(lineas):,} registros → {os.path.basename(ruta)}")
    return len(lineas)


# ============================================================
# PASO 1 - GENERACION DEL ZIP
# ============================================================

def generar_zip(cfg: dict, output_dir: str) -> str:
    separador()
    print("[PASO 1/2] GENERANDO archivos desde SIGAP produccion")
    separador()

    conn   = conectar_sqlserver(cfg)
    cursor = conn.cursor()

    os.makedirs(output_dir, exist_ok=True)
    fecha_str  = date.today().strftime("%Y%m%d")
    nombre_zip = f"sigap_expedientes_{fecha_str}.zip"
    ruta_zip   = os.path.join(output_dir, nombre_zip)

    archivos_txt = []

    # --- sectmuni ---
    print("  Consultando sectores...", end=" ", flush=True)
    cursor.execute(SQL_SECTMUNI)
    ruta_sect = os.path.join(output_dir, f"sectmuni_{fecha_str}.txt")
    n_sect = escribir_txt(ruta_sect, cursor, "sectmuni")
    archivos_txt.append(ruta_sect)

    # --- expediente ---
    print("  Consultando expedientes...", end=" ", flush=True)
    cursor.execute(SQL_EXPEDIENTE)
    ruta_expe = os.path.join(output_dir, f"expediente_{fecha_str}.txt")
    n_expe = escribir_txt(ruta_expe, cursor, "expediente")
    archivos_txt.append(ruta_expe)

    # --- expemovi (ultimos N dias) ---
    dias = cfg.get("dias_movimientos", 90)
    fecha_desde = datetime.now() - timedelta(days=int(dias))
    print(f"  Consultando movimientos (ultimos {dias} dias)...", end=" ", flush=True)
    cursor.execute(SQL_EXPEMOVI, fecha_desde)
    ruta_movi = os.path.join(output_dir, f"expemovi_{fecha_str}.txt")
    n_movi = escribir_txt(ruta_movi, cursor, "expemovi")
    archivos_txt.append(ruta_movi)

    conn.close()

    # --- Comprimir los 3 TXT en un ZIP ---
    with zipfile.ZipFile(ruta_zip, "w", zipfile.ZIP_DEFLATED) as zf:
        for ruta_txt in archivos_txt:
            zf.write(ruta_txt, os.path.basename(ruta_txt))

    tam_kb = os.path.getsize(ruta_zip) / 1024
    separador("-")
    print(f"  ZIP      : {ruta_zip}")
    print(f"  Sectores : {n_sect:,}")
    print(f"  Expedient: {n_expe:,}")
    print(f"  Movimient: {n_movi:,}")
    print(f"  Tamanio  : {tam_kb:.1f} KB")
    separador("-")

    # Limpiar TXT locales
    for ruta_txt in archivos_txt:
        try:
            os.remove(ruta_txt)
        except Exception:
            pass

    return ruta_zip


# ============================================================
# PASO 2 - SUBIDA A LA NUBE
# ============================================================

def subir_zip(cfg: dict, ruta_zip: str) -> bool:
    separador()
    print("[PASO 2/2] SUBIENDO archivos a la nube")
    separador()

    fecha_str  = date.today().strftime("%Y%m%d")
    nombre_zip = os.path.basename(ruta_zip)
    dir_remoto = cfg["dir_remoto"]
    db_user    = cfg["db_user"]
    db_pass    = cfg["db_pass"]
    db_name    = cfg["db_name"]

    nombres_txt = {
        "sectmuni":   f"sectmuni_{fecha_str}.txt",
        "expediente": f"expediente_{fecha_str}.txt",
        "expemovi":   f"expemovi_{fecha_str}.txt",
    }

    client = None
    sftp   = None

    try:
        # --- Conectar SSH ---
        print(f"  Conectando SSH a {cfg['ssh_host']}:{cfg['ssh_port']}...", end=" ", flush=True)
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        llave  = paramiko.RSAKey.from_private_key_file(cfg["llave"])
        client.connect(
            cfg["ssh_host"], port=cfg["ssh_port"],
            username=cfg["ssh_user"], pkey=llave,
            timeout=30, banner_timeout=30
        )
        print("OK")

        # --- Subir ZIP via SFTP ---
        sftp = client.open_sftp()
        ruta_remota_zip = dir_remoto + nombre_zip
        print(f"  Subiendo {nombre_zip}...", end=" ", flush=True)
        sftp.put(ruta_zip, ruta_remota_zip)
        sftp.close()
        sftp = None
        print("OK")

        # --- Descomprimir ---
        print(f"  Descomprimiendo en servidor...", end=" ", flush=True)
        cmd = f"cd {dir_remoto} && unzip -o {nombre_zip} && rm -f {nombre_zip}"
        _, stdout, stderr = client.exec_command(cmd)
        stdout.channel.recv_exit_status()
        err = stderr.read().decode()
        if err and "error" in err.lower():
            raise RuntimeError(f"Error al descomprimir: {err}")
        print("OK")

        # --- Cargar cada tabla ---
        tablas = [
            ("sectmuni",   nombres_txt["sectmuni"],   COLS_SECTMUNI),
            ("expediente", nombres_txt["expediente"], COLS_EXPEDIENTE),
            ("expemovi",   nombres_txt["expemovi"],   COLS_EXPEMOVI),
        ]

        for tabla, nombre_txt, cols in tablas:
            ruta_txt_remota = dir_remoto + nombre_txt

            # Recuento antes
            _, stdout, _ = client.exec_command(
                f"mysql -u {db_user} -p'{db_pass}' {db_name} "
                f"-e 'SELECT COUNT(*) FROM {tabla};' 2>/dev/null"
            )
            count_antes = stdout.read().decode().strip().split("\n")[-1]

            # TRUNCATE + LOAD DATA
            print(f"  Cargando {tabla}...", end=" ", flush=True)
            sql_load = (
                f"TRUNCATE TABLE {tabla}; "
                f"LOAD DATA LOCAL INFILE '{ruta_txt_remota}' "
                f"INTO TABLE {tabla} CHARACTER SET utf8mb4 "
                f"FIELDS TERMINATED BY ';' "
                f"LINES TERMINATED BY '\\r\\n' "
                f"{cols};"
            )
            cmd_mysql = (
                f"mysql --local-infile=1 -u {db_user} -p'{db_pass}' {db_name} "
                f"-e \"{sql_load}\" 2>&1"
            )
            _, stdout, _ = client.exec_command(cmd_mysql)
            resultado = stdout.read().decode()
            if resultado and "ERROR" in resultado.upper():
                raise RuntimeError(f"Error MySQL en {tabla}: {resultado}")
            print("OK")

            # Recuento despues
            _, stdout, _ = client.exec_command(
                f"mysql -u {db_user} -p'{db_pass}' {db_name} "
                f"-e 'SELECT COUNT(*) FROM {tabla};' 2>/dev/null"
            )
            count_despues = stdout.read().decode().strip().split("\n")[-1]
            print(f"    Antes: {count_antes}  →  Despues: {count_despues}")

            # Limpiar TXT remoto
            client.exec_command(f"rm -f {ruta_txt_remota}")

        separador()
        print("[OK] PROCESO COMPLETADO EXITOSAMENTE")
        separador()
        return True

    except paramiko.AuthenticationException:
        print(f"\n[ERR] Error de autenticacion SSH. Verificar llave: {cfg['llave']}")
        return False
    except Exception as e:
        print(f"\n[ERR] {e}")
        import traceback
        traceback.print_exc()
        if client:
            try:
                for nombre_txt in nombres_txt.values():
                    client.exec_command(f"rm -f {dir_remoto}{nombre_txt}")
                client.exec_command(f"rm -f {dir_remoto}{nombre_zip}")
            except Exception:
                pass
        return False
    finally:
        if sftp:
            try: sftp.close()
            except Exception: pass
        if client:
            client.close()
            print("[SSH] Conexion cerrada.")


# ============================================================
# VERIFICACION DE PREREQUISITOS
# ============================================================

def verificar_prereqs(cfg: dict, ruta_zip: str = None) -> bool:
    separador()
    print("[?] VERIFICACION DE PREREQUISITOS")
    separador()
    ok = True

    # Llave SSH
    print(f"  Llave SSH : {cfg['llave']}")
    if not os.path.exists(cfg["llave"]):
        print("    [ERR] No encontrada")
        ok = False
    else:
        try:
            paramiko.RSAKey.from_private_key_file(cfg["llave"])
            print("    [OK]  Valida")
        except Exception as e:
            print(f"    [ERR] Invalida: {e}")
            ok = False

    # Conectividad SSH
    print(f"  SSH       : {cfg['ssh_host']}:{cfg['ssh_port']}")
    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        sock.settimeout(5)
        res = sock.connect_ex((cfg["ssh_host"], int(cfg["ssh_port"])))
        sock.close()
        print("    [OK]  Puerto accesible" if res == 0 else "    [ERR] Puerto no accesible")
        if res != 0: ok = False
    except Exception as e:
        print(f"    [ERR] {e}")
        ok = False

    # SQL Server
    print(f"  SQL Server: {cfg['server']} / {cfg['database']}")
    try:
        conn_str = (
            f"DRIVER={{{cfg['driver']}}};"
            f"SERVER={cfg['server']};"
            f"DATABASE={cfg['database']};"
            f"UID={cfg['user']};"
            f"PWD={cfg['password']};"
        )
        conn = pyodbc.connect(conn_str, timeout=10)
        conn.close()
        print("    [OK]  Conexion exitosa")
    except Exception as e:
        print(f"    [ERR] {e}")
        ok = False

    if ruta_zip:
        print(f"  ZIP local : {ruta_zip}")
        if os.path.exists(ruta_zip):
            tam = os.path.getsize(ruta_zip)
            print(f"    [OK]  Encontrado ({tam:,} bytes)")
        else:
            print("    [ERR] No encontrado")
            ok = False

    separador()
    print("[OK] Todo listo para ejecutar." if ok else "[ERR] Hay errores a corregir.")
    separador()
    return ok


# ============================================================
# MAIN
# ============================================================

def main():
    parser = argparse.ArgumentParser(
        description="Exporta SIGAP y sube a MySQL en la nube.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=(
            "Ejemplos:\n"
            "  py sigap_sync.py                  # proceso completo\n"
            "  py sigap_sync.py --test           # solo verifica conexiones\n"
            "  py sigap_sync.py --solo-generar   # genera ZIP sin subir\n"
            "  py sigap_sync.py --solo-subir     # sube ZIP ya existente\n"
        )
    )
    parser.add_argument("--config",       default="config.ini")
    parser.add_argument("--output", "-o", default=None)
    parser.add_argument("--test",         action="store_true")
    parser.add_argument("--solo-generar", action="store_true")
    parser.add_argument("--solo-subir",   action="store_true")
    args = parser.parse_args()

    separador()
    print("  SIGAP SYNC - Municipalidad de Pocito")
    print(f"  Fecha / Hora: {datetime.now().strftime('%d/%m/%Y  %H:%M:%S')}")
    separador()

    cfg        = cargar_config(args.config)
    output_dir = args.output or cfg.get("output_dir", "D:/Arc_Diarios")
    fecha_str  = date.today().strftime("%Y%m%d")
    ruta_zip   = os.path.join(output_dir, f"sigap_expedientes_{fecha_str}.zip")

    dir_logs    = os.path.join(output_dir, "logs")
    sys.stdout  = Logger(dir_logs)
    sys.stderr  = sys.stdout

    if args.test:
        verificar_prereqs(cfg, ruta_zip if args.solo_subir else None)
        sys.exit(0)

    if args.solo_generar:
        if not verificar_prereqs(cfg): sys.exit(1)
        generar_zip(cfg, output_dir)
        print(f"\n[i] Para subirlo: py sigap_sync.py --solo-subir")
        sys.exit(0)

    if args.solo_subir:
        if not os.path.exists(ruta_zip):
            print(f"[ERR] No se encontro el ZIP: {ruta_zip}")
            sys.exit(1)
        exito = subir_zip(cfg, ruta_zip)
        sys.exit(0 if exito else 1)

    # Proceso completo
    if not verificar_prereqs(cfg): sys.exit(1)
    ruta_zip = generar_zip(cfg, output_dir)
    exito    = subir_zip(cfg, ruta_zip)
    sys.exit(0 if exito else 1)


if __name__ == "__main__":
    main()
