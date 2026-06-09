@echo off
:: ============================================================
:: instalar_servicio.bat
:: Instala el watchdog SIGAP como servicio de Windows usando NSSM
:: EJECUTAR COMO ADMINISTRADOR
:: ============================================================

set BASE_DIR=D:\Sube Expedientes
set PYTHON=C:\Users\Administrador.WIN-KEFVL1H7IVF\AppData\Local\Programs\Python\Python314\python.exe
set SCRIPT=%BASE_DIR%\watchdog.py
set SERVICIO=SIGAPWatchdog
set NSSM=%BASE_DIR%\nssm.exe

echo.
echo ============================================================
echo  Instalador Servicio SIGAP Watchdog
echo  Municipalidad de Pocito
echo ============================================================
echo.

:: Verificar que se corre como Administrador
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERR] Este script debe ejecutarse como Administrador.
    echo       Clic derecho sobre el archivo ^> Ejecutar como administrador
    pause
    exit /b 1
)

:: Verificar que NSSM existe
if not exist "%NSSM%" (
    echo [ERR] No se encontro nssm.exe en: %NSSM%
    echo.
    echo  Descargar NSSM desde: https://nssm.cc/download
    echo  Copiar nssm.exe a: %BASE_DIR%\
    echo.
    pause
    exit /b 1
)

:: Verificar Python
if not exist "%PYTHON%" (
    echo [ERR] Python no encontrado en: %PYTHON%
    pause
    exit /b 1
)

:: Verificar script watchdog
if not exist "%SCRIPT%" (
    echo [ERR] watchdog.py no encontrado en: %SCRIPT%
    pause
    exit /b 1
)

echo [OK] Archivos verificados.
echo.

:: Detener y eliminar servicio si ya existe
sc query "%SERVICIO%" >nul 2>&1
if %errorlevel% equ 0 (
    echo [!] El servicio ya existe. Desinstalando version anterior...
    "%NSSM%" stop "%SERVICIO%" >nul 2>&1
    timeout /t 3 /nobreak >nul
    "%NSSM%" remove "%SERVICIO%" confirm >nul 2>&1
    echo [OK] Servicio anterior eliminado.
    echo.
)

:: Instalar servicio
echo Instalando servicio "%SERVICIO%"...
"%NSSM%" install "%SERVICIO%" "%PYTHON%" "%SCRIPT%"

:: Configurar propiedades del servicio
"%NSSM%" set "%SERVICIO%" DisplayName "SIGAP Watchdog - Municipalidad Pocito"
"%NSSM%" set "%SERVICIO%" Description "Monitorea cambios en SIGAP y sincroniza expedientes a la web"
"%NSSM%" set "%SERVICIO%" AppDirectory "%BASE_DIR%"
"%NSSM%" set "%SERVICIO%" Start SERVICE_AUTO_START

:: Redirigir stdout/stderr a logs
"%NSSM%" set "%SERVICIO%" AppStdout "%BASE_DIR%\logs\service_stdout.log"
"%NSSM%" set "%SERVICIO%" AppStderr "%BASE_DIR%\logs\service_stderr.log"
"%NSSM%" set "%SERVICIO%" AppStdoutCreationDisposition 4
"%NSSM%" set "%SERVICIO%" AppStderrCreationDisposition 4

:: Reiniciar si falla (esperar 30 segundos)
"%NSSM%" set "%SERVICIO%" AppExit Default Restart
"%NSSM%" set "%SERVICIO%" AppRestartDelay 30000

echo.
echo [OK] Servicio configurado.
echo.

:: Iniciar el servicio
echo Iniciando servicio...
"%NSSM%" start "%SERVICIO%"
timeout /t 3 /nobreak >nul

:: Verificar estado
sc query "%SERVICIO%" | findstr "STATE"
echo.

echo ============================================================
echo  Servicio instalado correctamente.
echo.
echo  Comandos utiles:
echo    Ver estado  : sc query %SERVICIO%
echo    Detener     : net stop %SERVICIO%
echo    Iniciar     : net start %SERVICIO%
echo    Desinstalar : %NSSM% remove %SERVICIO% confirm
echo.
echo  Logs en: %BASE_DIR%\logs\
echo ============================================================
echo.
pause
