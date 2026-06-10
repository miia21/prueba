<?php
// Copiar como config.local.php en el servidor real y completar con credenciales locales.
// No versionar config.local.php.

const EXPEDIENTES_DB_HOST = 'localhost';
const EXPEDIENTES_DB_NAME = 'sigap_expedientes';
const EXPEDIENTES_DB_USER = 'usuario_mysql';
const EXPEDIENTES_DB_PASS = 'clave_mysql';
const EXPEDIENTES_DB_CHARSET = 'utf8mb4';

// Opcional: habilita vista interna para integraciones confiables.
const EXPEDIENTES_INTERNAL_TOKEN = 'cambiar-por-un-token-largo';

// Opcional: ruta de auditoría. Si no se define, usa el directorio temporal del sistema.
// const EXPEDIENTES_AUDIT_LOG = '/var/log/expedientes/audit.log';
