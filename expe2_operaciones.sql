-- Tablas locales de seguimiento interno para la app web de expedientes.
-- No reemplazan ni modifican las tablas oficiales/sincronizadas expediente, expemovi ni sectmuni.

CREATE TABLE IF NOT EXISTS `expe2_recepciones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `numero` bigint NOT NULL,
  `ano` smallint NOT NULL,
  `letra` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `sector_codigo` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recibido_por` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recibido_en` datetime NOT NULL,
  `observaciones` text COLLATE utf8mb4_unicode_ci,
  `origen` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'web',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_expe2_recepciones_exp` (`numero`,`ano`),
  KEY `idx_expe2_recepciones_sector` (`sector_codigo`),
  KEY `idx_expe2_recepciones_fecha` (`recibido_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `expe2_movimientos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `numero` bigint NOT NULL,
  `ano` smallint NOT NULL,
  `letra` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `sector_origen` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sector_destino` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `estado` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'enviado',
  `observaciones` text COLLATE utf8mb4_unicode_ci,
  `enviado_por` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `enviado_en` datetime NOT NULL,
  `recibido` tinyint(1) NOT NULL DEFAULT 0,
  `recibido_por` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recibido_en` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_expe2_movimientos_exp` (`numero`,`ano`),
  KEY `idx_expe2_movimientos_destino` (`sector_destino`,`recibido`),
  KEY `idx_expe2_movimientos_fecha` (`enviado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `expe2_estado_local` (
  `numero` bigint NOT NULL,
  `ano` smallint NOT NULL,
  `letra` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `sector_actual` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `estado_local` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ultimo_movimiento_id` bigint unsigned DEFAULT NULL,
  `actualizado_por` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actualizado_en` datetime NOT NULL,
  PRIMARY KEY (`numero`,`ano`),
  KEY `idx_expe2_estado_sector` (`sector_actual`),
  KEY `idx_expe2_estado_estado` (`estado_local`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `expe2_auditoria` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `numero` bigint NOT NULL,
  `ano` smallint NOT NULL,
  `accion` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `usuario_id` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sector_origen` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sector_destino` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `detalle_json` longtext COLLATE utf8mb4_unicode_ci,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_expe2_auditoria_exp` (`numero`,`ano`),
  KEY `idx_expe2_auditoria_usuario` (`usuario_id`),
  KEY `idx_expe2_auditoria_accion` (`accion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
