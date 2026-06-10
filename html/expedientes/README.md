# Consulta de expedientes municipales

Aplicación web ubicada en `html/expedientes/` para consultar expedientes municipales sincronizados desde SIGAP y visualizar su recorrido por sectores.

## Archivos de la app web

- `index.html`: entrada principal de la interfaz.
- `styles.css`: estilos visuales, diseño responsive, tarjetas, badges y línea de tiempo.
- `app.js`: validaciones, consumo de API, paginación de movimientos y renderizado de resultados.
- `api/config.php`: configuración compartida por variables de entorno o `config.local.php` no versionado.
- `api/config.local.example.php`: plantilla segura para configurar el servidor.
- `api/consulta.php`: endpoint de consulta de expedientes y movimientos.
- `api/status.php`: endpoint de estado básico/última actualización.

## Archivos de sincronización no modificados

La sincronización de datos se mantiene separada y no forma parte de esta versión visual/funcional. No se deben modificar desde la app web:

- `sube expedientes/sigap_sync.py`
- `sube expedientes/sigap_sync_incremental.py`
- `sube expedientes/watchdog.py`
- `sube expedientes/instalar_servicio.bat`
- `sube expedientes/nssm.exe`
- `sube expedientes/config.ini`
- `sube expedientes/last_sync.txt`

## Configuración

La app ya no necesita credenciales hardcodeadas en `consulta.php` ni `status.php`. La configuración se resuelve en este orden:

1. Variables de entorno `EXPEDIENTES_*`.
2. Archivo local no versionado `html/expedientes/api/config.local.php`.
3. Valores mínimos por defecto para desarrollo local.

Para configurar por archivo local:

```bash
cp html/expedientes/api/config.local.example.php html/expedientes/api/config.local.php
```

Completar en `config.local.php`:

```php
const EXPEDIENTES_DB_HOST = 'localhost';
const EXPEDIENTES_DB_NAME = 'sigap_expedientes';
const EXPEDIENTES_DB_USER = 'usuario_mysql';
const EXPEDIENTES_DB_PASS = 'clave_mysql';
```

`config.local.php` está ignorado por Git y no debe subirse al repositorio.

## Auditoría de consultas

`api/consulta.php` registra eventos de auditoría en JSON Lines:

- consultas exitosas o no encontradas;
- errores de validación;
- rate limit;
- intentos no autorizados de vista interna;
- errores de conexión o consulta.

Por defecto se escribe en el directorio temporal del sistema como `expedientes_audit.log`. Se puede personalizar con:

```text
EXPEDIENTES_AUDIT_LOG=/var/log/expedientes/audit.log
```

## Cómo probar

Desde el servidor web, abrir la ruta donde esté publicada la carpeta:

```text
http://SERVIDOR/expedientes/
```

Si el servidor expone el árbol completo, puede ser:

```text
http://SERVIDOR/html/expedientes/
```

La app consume estos endpoints relativos:

```text
./api/consulta.php?numero=1&ano=2024&limit=12&offset=0
./api/status.php
```

## Datos de ejemplo

Los archivos SQL incluidos contienen estructura y datos de prueba. Para una base cargada con esos SQL, se pueden probar combinaciones como:

- Número `1`, año `2024`
- Número `1`, año `2025`
- Número `1`, año `2026`

La letra es opcional en la interfaz porque la estructura actual usa `NUMERO` y `ANO` como clave principal de expediente.

## Endpoint `api/consulta.php`

### Parámetros

- `numero` requerido, numérico, hasta 10 dígitos.
- `ano` requerido, 4 dígitos, entre 1990 y el año próximo al actual.
- `letra` opcional, una letra.
- `vista` opcional. Por defecto devuelve vista `publica`.
- `limit` opcional, entre 1 y 80. Por defecto devuelve 12 movimientos.
- `offset` opcional, desde 0. Permite paginar movimientos.

### Respuesta pública

```json
{
  "expediente": {},
  "movimientos": [],
  "meta": {
    "vista": "publica",
    "limit": 12,
    "offset": 0,
    "movimientos_total": 24,
    "returned": 12,
    "has_more": true
  }
}
```

La vista pública oculta campos sensibles como documento, usuario interno, domicilio, teléfono, correo y empresa.

### Campos públicos definidos

Expediente público:

- `NUMERO`, `LETRA`, `ANO`
- `FECHAINICIO`, `FECHACARGA`, `updated_at`
- `SECTORINICIA`, `SECTORINICIA_NOMBRE`
- `EXTERNOINICIA`, `INICIADOR`, `DESTINO`
- `SECTORDESTINO`, `EXTERNODESTINO`
- `TIPOEXPEDIENTE`, `ESTADO`, `TEMA`, `MOTIVO`
- `IMPRESO`, `ANULADO`, `PAGADO`, `INCOMPLETO`
- `SECTACTUAL`, `SECTACTUAL_NOMBRE`

Movimiento público:

- `NUMERO`, `ANO`, `FECHAHORA`
- `SECTORACTUAL`, `SECTORACTUAL_NOMBRE`, `SECTORPROVENIENTE`
- `EXTERNOACTUAL`, `LUGAR`, `ESTADOACTUAL`
- `FOJAS`, `PERMANECIO`, `OBSERVACIONES`, `RECIBIDO`, `FECHARECEPCION`

Campos internos no expuestos públicamente: documento, usuario interno, domicilio, teléfono, celular, correo y empresa.

### Vista interna preparada para empleados

`consulta.php` permite `vista=interna` únicamente si se configura `EXPEDIENTES_INTERNAL_TOKEN` y se envía el header `X-Internal-Token` correcto.

Ejemplo:

```bash
curl -H "X-Internal-Token: TOKEN" \
  "https://SERVIDOR/expedientes/api/consulta.php?numero=1&ano=2024&vista=interna"
```

Si se pide `vista=interna` sin token válido, el endpoint responde `401`. Esto no reemplaza un login real, pero evita una falsa seguridad por parámetro público.

## Pendientes sugeridos

- Implementar login real con usuarios, roles, sesiones seguras y auditoría por usuario identificado.
- Revisar normativamente qué datos de `MOTIVO` y `OBSERVACIONES` deben mostrarse en vista pública.
- Migrar auditoría desde archivo plano a una tabla controlada si se requiere reporting institucional.
- Agregar exportación PDF/Excel solo para usuarios autenticados.
