-- Tabla de usuarios internos para la app web de expedientes.
-- La app también intenta crearla automáticamente desde api/auth.php.
CREATE TABLE IF NOT EXISTS `usuarios_expe2` (
  `id` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `role` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'empleado',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_usuarios_expe2_username` (`username`),
  KEY `idx_usuarios_expe2_role` (`role`),
  KEY `idx_usuarios_expe2_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
