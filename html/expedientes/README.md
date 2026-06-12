# Consulta de expedientes municipales

Aplicación web ubicada en `html/expedientes/` para consultar expedientes municipales sincronizados desde SIGAP, visualizar el recorrido por sectores y operar una vista interna con login, dashboard y gestión básica de usuarios.

## Archivos de la app web

- `index.html`: entrada principal de la interfaz.
- `styles.css`: estilos visuales, diseño responsive, tablas, dashboard y formularios internos.
- `app.js`: validaciones, consumo de API, paginación de movimientos, login, dashboard y usuarios.
- `api/config.php`: configuración compartida por variables de entorno o `config.local.php` no versionado.
- `api/config.local.example.php`: plantilla segura para configurar el servidor.
- `api/auth.php`: helpers de sesión y almacenamiento de usuarios en la tabla MySQL `usuarios_expe2`.
- `api/me.php`, `api/setup.php`, `api/login.php`, `api/logout.php`: endpoints de autenticación.
- `api/users.php`: gestión básica de usuarios para administradores.
- `api/dashboard.php`: métricas internas para administradores y supervisores.
- `api/consulta.php`: endpoint de consulta de expedientes y movimientos.
- `api/operaciones.php`: helpers y creación de tablas locales `expe2_*` para seguimiento interno.
- `api/sectores.php`, `api/recepciones.php`, `api/movimientos-locales.php`, `api/bandeja.php`: endpoints internos para sectores, recepción local, derivaciones internas y bandeja.
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

La app no necesita credenciales hardcodeadas en `consulta.php` ni `status.php`. La configuración se resuelve en este orden:

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

## Login y usuarios

La primera vez que se abre la sección interna y no existen usuarios en la base, la app muestra un formulario para crear el primer administrador. Los usuarios se guardan en la tabla MySQL `usuarios_expe2`.

La app intenta crear esa tabla automáticamente desde `api/auth.php` con `CREATE TABLE IF NOT EXISTS`. Si el usuario MySQL configurado no tiene permiso `CREATE`, importar manualmente `usuarios_expe2.sql` antes de usar el login.

Roles disponibles:

- `admin`: ve dashboard, gestiona usuarios y puede operar cualquier sector.
- `supervisor`: ve dashboard y puede operar cualquier sector, sin gestionar usuarios.
- `empleado`: no ve dashboard general; consulta expedientes y solo puede recibir/derivar expedientes correspondientes a su sector asignado.

Los empleados deben tener `sector_codigo` asignado. Esta gestión es suficiente para una primera versión interna, pero no reemplaza una solución institucional completa con recuperación de contraseña, 2FA o integración con directorio municipal.

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

La consulta pública usa solamente tres campos: número, letra opcional y año.

La app consume estos endpoints relativos:

```text
./api/consulta.php?numero=1&ano=2024&limit=12&offset=0
./api/status.php
./api/me.php
./api/dashboard.php
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

La vista pública solo permite ver expedientes externos. Los expedientes internos se responden como no encontrados para usuarios sin sesión. Al iniciar sesión, usuarios internos pueden consultar expedientes externos e internos según sus permisos.

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

## Endpoint `api/dashboard.php`

Requiere sesión iniciada. Devuelve:

- totales de expedientes, movimientos y sectores vigentes;
- solo disponible para usuarios `admin` o `supervisor`;
- totales de recepciones, movimientos y expedientes en seguimiento interno;
- última actualización;
- distribución por estado;
- sectores oficiales con más expedientes;
- sectores con más seguimiento interno.

## Endpoint `api/users.php`

Requiere usuario `admin`.

- `GET`: lista usuarios.
- `POST`: crea usuario.
- `PATCH`: actualiza nombre, rol, estado activo o contraseña.

## Pendientes sugeridos

- Agregar migraciones formales/versionadas para administrar cambios futuros en `usuarios_expe2` y `expe2_*`.
- Agregar recuperación de contraseña, 2FA y políticas de expiración.
- Revisar normativamente qué datos de `MOTIVO` y `OBSERVACIONES` deben mostrarse en vista pública.
- Migrar auditoría desde archivo plano a una tabla controlada si se requiere reporting institucional.
- Agregar exportación PDF/Excel solo para usuarios autenticados.

## Seguimiento interno sin modificar SIGAP

Esta versión agrega un módulo interno para que oficinas externas registren recepción y movimientos propios de expedientes. El módulo **no escribe** sobre las tablas sincronizadas `expediente`, `expemovi` ni `sectmuni`; esas tablas se siguen usando como fuente oficial de lectura.

Las operaciones internas se guardan en tablas separadas:

- `expe2_recepciones`: recepciones locales registradas desde la app web.
- `expe2_movimientos`: derivaciones/movimientos internos entre sectores u oficinas dentro de esta app.
- `expe2_estado_local`: estado interno vigente del expediente en esta app.
- `expe2_auditoria`: auditoría de acciones internas.

La app intenta crear estas tablas automáticamente desde `api/operaciones.php`. Si el usuario MySQL no tiene permiso `CREATE`, importar manualmente `expe2_operaciones.sql`.

Endpoints internos agregados:

- `GET ./api/sectores.php`: lista sectores vigentes desde `sectmuni` para formularios internos.
- `POST ./api/recepciones.php`: registra recepción local de un expediente oficial existente; empleados solo pueden recibir en su sector.
- `POST ./api/movimientos-locales.php`: registra una derivación interna sin modificar el sistema oficial; empleados solo pueden mover expedientes cuyo sector interno/oficial actual coincide con su sector asignado.
- `GET ./api/bandeja.php`: lista expedientes y últimos movimientos con seguimiento interno.

Las respuestas de `api/consulta.php` incluyen `seguimiento_local` solo cuando hay sesión interna autenticada. La vista pública conserva los datos oficiales sincronizados y oculta expedientes internos.

La gestión de recepción y derivación se realiza desde la ficha del expediente: primero se busca el expediente y, si el usuario tiene permiso sobre el sector correspondiente, aparecen las acciones internas disponibles.
