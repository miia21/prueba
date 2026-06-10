# Consulta de expedientes municipales

Aplicación web ubicada en `html/expedientes/` para consultar expedientes municipales sincronizados desde SIGAP y visualizar su recorrido por sectores.

## Archivos de la app web

- `index.html`: entrada principal de la interfaz.
- `styles.css`: estilos visuales, diseño responsive, tarjetas, badges y línea de tiempo.
- `app.js`: validaciones, consumo de API y renderizado de resultados.
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
./api/consulta.php?numero=1&ano=2024
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

### Respuesta pública

```json
{
  "expediente": {},
  "movimientos": [],
  "meta": {
    "vista": "publica",
    "movimientos_limit": 80
  }
}
```

La vista pública oculta campos sensibles como documento, usuario interno, domicilio, teléfono y correo.

### Vista interna preparada para futuro

`consulta.php` deja preparada una vista interna si se configura la variable de entorno `EXPEDIENTES_INTERNAL_TOKEN` y se envía el header `X-Internal-Token` correcto junto con `vista=interna`.

No hay login implementado en esta versión. No se recomienda exponer la vista interna sin autenticación real.

## Pendientes sugeridos

- Mover credenciales de base de datos a variables de entorno o configuración no versionada.
- Implementar autenticación real para empleados municipales.
- Agregar auditoría de consultas.
- Agregar paginación o botón “ver más” para expedientes con muchos movimientos.
- Definir formalmente qué campos son públicos y cuáles internos.
