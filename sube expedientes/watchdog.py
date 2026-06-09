"""
========================================================================
watchdog.py
========================================================================
Proceso permanente que monitorea cambios en SIGAP y dispara la sync
incremental solo cuando hay datos nuevos.

- Corre como servicio de Windows (instalado con NSSM)
- Chequea cada 5 minutos si hay movimientos nuevos en SIGAP
- Si hay cambios → ejecuta sigap_sync_incremental.py
- Si no hay cambios → espera y vuelve a chequear
- Logs rotativos diarios en logs\watchdog_YYYYMMDD.log
========================================================================
"""

import os
import sys
import time
import subprocess
import logging
import configparser
from datetime import datetime, date
from logging.handlers import TimedRotatingFileHandler

# ── Rutas ─────────────────────────────────────────────────────
BASE_DIR      = os.path.dirname(os.path.abspath(__file__))
CONFIG_FILE   = os.path.join(BASE_DIR, "config.ini")
LOG_DIR       = os.path.join(BASE_DIR, "logs")
SYNC_SCRIPT   = os.path.join(BASE_DIR, "sigap_sync_incremental.py")
PYTHON_EXE    = r"C:\Users\Administrador.WIN-KEFVL1H7IVF\AppData\Local\Programs\Python\Python314\python.exe"

# ── Intervalo de chequeo en segundos (default 5 min) ──────────
CHECK_INTERVAL = 5 * 60


# ============================================================
# LOGGER con rotación diaria
# ============================================================
def setup_logger() -> logging.Logger:
    os.makedirs(LOG_DIR, exist_ok=True)
    log = logging.getLogger("watchdog")
    log.setLevel(logging.INFO)

    # Archivo rotativo diario — guarda 30 días
    # Se mantiene con encoding UTF-8 para soportar tildes sin problemas
    fh = TimedRotatingFileHandler(
        filename    = os.path.join(LOG_DIR, "watchdog.log"),
        when        = "midnight",
        interval    = 1,
        backupCount = 30,
        encoding    = "utf-8"
    )
    fh.suffix = "%Y%m%d"
    fh.setFormatter(logging.Formatter(
        fmt     = "%(asctime)s [%(levelname)s] %(message)s",
        datefmt = "%d/%m/%Y %H:%M:%S"
    ))

    # ------------------------------------------------------------------
    # MODIFICACIÓN:
    # Se eliminó la salida por consola (sys.stdout) para evitar:
    # 1. Error WinError 6 (NSSM no tiene consola interactiva).
    # 2. UnicodeEncodeError (Problemas de codificación cp1252 en CMD).
    # Ahora todo se registra de forma segura en watchdog.log
    # ------------------------------------------------------------------
    
    log.addHandler(fh)
    return log


# ============================================================
# LEER INTERVALO DESDE CONFIG
# ============================================================
def leer_intervalo() -> int:
    try:
        cfg = configparser.ConfigParser()
        cfg.read(CONFIG_FILE, encoding="utf-8")
        seg = int(cfg.get("watchdog", "intervalo_segundos", fallback=str(CHECK_INTERVAL)))
        return max(seg, 60)   # mínimo 1 minuto
    except Exception:
        return CHECK_INTERVAL


# ============================================================
# EJECUTAR SYNC
# ============================================================
def ejecutar_sync(log: logging.Logger) -> bool:
    """Lanza sigap_sync_incremental.py y espera resultado."""
    log.info("=" * 50)
    log.info("Cambios detectados — iniciando sync incremental...")
    log.info("=" * 50)

    try:
        resultado = subprocess.run(
            [PYTHON_EXE, SYNC_SCRIPT],
            capture_output = True,
            text           = True,
            encoding       = "utf-8",
            errors         = "replace",
            timeout        = 300,    # máximo 5 minutos para la sync
            cwd            = BASE_DIR
        )

        # Loguear salida del script
        if resultado.stdout:
            for linea in resultado.stdout.strip().splitlines():
                log.info(f"  [sync] {linea}")
        if resultado.stderr:
            for linea in resultado.stderr.strip().splitlines():
                log.warning(f"  [sync:err] {linea}")

        if resultado.returncode == 0:
            log.info("Sync completada exitosamente.")
            return True
        else:
            log.error(f"Sync terminó con error (código {resultado.returncode}).")
            return False

    except subprocess.TimeoutExpired:
        log.error("Sync cancelada: excedió 5 minutos de timeout.")
        return False
    except Exception as e:
        log.error(f"Error al ejecutar sync: {e}")
        return False


# ============================================================
# LOOP PRINCIPAL
# ============================================================
def main():
    log      = setup_logger()
    interval = leer_intervalo()

    log.info("=" * 60)
    log.info("SIGAP WATCHDOG iniciado — Municipalidad de Pocito")
    log.info(f"  Script sync : {SYNC_SCRIPT}")
    log.info(f"  Python      : {PYTHON_EXE}")
    log.info(f"  Intervalo   : {interval // 60} minutos")
    log.info(f"  Logs        : {LOG_DIR}")
    log.info("=" * 60)

    # Verificar que el script de sync existe
    if not os.path.exists(SYNC_SCRIPT):
        log.error(f"No se encontró: {SYNC_SCRIPT}")
        log.error("Verificar que sigap_sync_incremental.py está en la misma carpeta.")
        sys.exit(1)

    # Verificar que Python existe
    if not os.path.exists(PYTHON_EXE):
        log.error(f"Python no encontrado en: {PYTHON_EXE}")
        sys.exit(1)

    sync_en_curso  = False
    ultimo_error   = None
    errores_consec = 0

    while True:
        try:
            log.info(f"Chequeando cambios en SIGAP...")
            exito = ejecutar_sync(log)

            if exito:
                errores_consec = 0
                ultimo_error   = None
            else:
                errores_consec += 1
                ultimo_error    = datetime.now()
                log.warning(f"Errores consecutivos: {errores_consec}")

                # Si hay 5 errores seguidos, esperar más tiempo
                if errores_consec >= 5:
                    espera_extra = min(errores_consec * 60, 1800)  # máx 30 min
                    log.warning(f"Demasiados errores. Esperando {espera_extra//60} min extra...")
                    time.sleep(espera_extra)

        except KeyboardInterrupt:
            log.info("Watchdog detenido manualmente.")
            break
        except Exception as e:
            log.error(f"Error inesperado en loop principal: {e}")
            errores_consec += 1

        # El script incremental ya verifica si hay cambios internamente.
        # Si no hay cambios devuelve exitcode 0 sin hacer nada.
        log.info(f"Esperando {interval // 60} minutos para próximo chequeo...")
        time.sleep(interval)


if __name__ == "__main__":
    main()
