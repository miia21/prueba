-- phpMyAdmin SQL Dump
-- version 5.2.1deb3
-- https://www.phpmyadmin.net/
--
-- Servidor: localhost:3306
-- Tiempo de generación: 09-06-2026 a las 15:03:34
-- Versión del servidor: 8.0.45-0ubuntu0.24.04.1
-- Versión de PHP: 8.3.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `sigap_expedientes`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `sectmuni`
--

CREATE TABLE `sectmuni` (
  `CODIGO` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `DESCRIPCION` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `OBSERVACIONES` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `SECRETARIA` tinyint(1) NOT NULL DEFAULT '0',
  `CARGOMAX` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `RESPONSABLE` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `NOMBRECORTO` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `CODIGOINVEN` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `CODIGOANTERIOR` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `VIGENTE` tinyint(1) NOT NULL DEFAULT '1',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `sectmuni`
--

INSERT INTO `sectmuni` (`CODIGO`, `DESCRIPCION`, `OBSERVACIONES`, `SECRETARIA`, `CARGOMAX`, `RESPONSABLE`, `NOMBRECORTO`, `CODIGOINVEN`, `CODIGOANTERIOR`, `VIGENTE`, `updated_at`) VALUES
('AD', '*ADEL', '', 0, '', '', '', 'AD1', '', 1, '2026-06-09 15:02:45'),
('AL', '*ASESOR LETRADO', '', 0, '', 'SILVINA COSENTINO', '', 'AL1', '', 1, '2026-06-09 15:02:45'),
('AM', '*ARCHIVO MUNICIPAL', '', 0, 'ARCHIVO MUNICIPAL', 'ARCHIVO MUNICIPAL', '', 'AM1', '', 1, '2026-06-09 15:02:45'),
('AN', '*OFICINA DE NIÑEZ', '', 0, 'AREA NIÑEZ', '', '', 'AN1', '', 1, '2026-06-09 15:02:45'),
('AO', 'AO', '', 0, 'COORDINADORA DE TURISMO', '', '', '123', '', 0, '2026-06-09 15:02:45'),
('AP', '*OF. DE AMBIENTE Y ARBOLADO PUBLICO', '', 0, 'COOR.DE AMBIENTE Y ARBOLADO PUBLICO', '', '', 'AAP', '', 1, '2026-06-09 15:02:45'),
('AR', '*ANEXO MUNICIPAL', '', 0, '', '', '', 'AX1', '', 1, '2026-06-09 15:02:45'),
('AS', '*SEC ACCIÓN SOCIAL,CULTURA Y DEPORT', '', 0, 'ACCION SOCIAL', '', '', 'AS1', '', 0, '2026-06-09 15:02:45'),
('C1', '*OFICINA COORDINACIÓN DE PRENSA', '', 0, '', '', '', 'C11', '', 1, '2026-06-09 15:02:45'),
('C2', 'DIRECTORA DE GOBIERNO', '', 0, '', '', '', '', '', 0, '2026-06-09 15:02:45'),
('C3', 'COORDINADOR DE PLANES SOCIALES', '', 0, '', '', '', '', '', 0, '2026-06-09 15:02:45'),
('C4', '*DIRECCIÓN ZONA NORTE ', '', 0, '', '', '', 'C41', '', 1, '2026-06-09 15:02:45'),
('C5', '*COORDINACIÓN DE GOBIERNO', '', 0, '', '', '', 'C51', '', 0, '2026-06-09 15:02:45'),
('C9', '*COORDINACIÓN DE OBRAS', '', 0, '', 'CARLOS PAEZ', '', 'C91', '', 1, '2026-06-09 15:02:45'),
('CA', '*DIRECCIÓN ZONA SUR', '', 0, '', '', '', 'CA1', '', 0, '2026-06-09 15:02:45'),
('CC', 'COCINA', '', 0, 'COCINA', 'COCINA', '', '', '', 0, '2026-06-09 15:02:45'),
('CD', '*HONORABLE CONCEJO DELIB POCITO', '', 0, '', 'JOSE FUNES', '', 'CD1', '', 1, '2026-06-09 15:02:45'),
('CE', '*CEMENTERIO MUNICIPAL', '', 0, '', 'CEMENTERIO', '', 'CE1', '', 1, '2026-06-09 15:02:45'),
('CG', '*DIRECCIÓN DE GOBIERNO', '', 0, '', 'SANDRA ZARATE', '', 'CG1', '', 1, '2026-06-09 15:02:45'),
('CI', 'CENTRO INTEGRAL COMUNITARIOS', '', 0, 'CENTRO INTEGRAL COMUNITARIOS', 'CENTRO INTEGRAL COMUNITARIOS', '', '', '', 0, '2026-06-09 15:02:45'),
('CN', '*CONTADURIA', '', 0, '', 'LUCAS MILÁN', '', 'CN1', '', 1, '2026-06-09 15:02:45'),
('CO', '*OFICINA DE COMPRAS', '', 0, 'DIRECTOR', 'LORENA YANINA AGÜERO', '', 'CO1', '', 1, '2026-06-09 15:02:45'),
('CP', 'CONTADORA MUNICIPAL', '', 0, 'CONTADORA MUNICIPAL', 'STELA CAPARROS', '', 'XZS', '', 0, '2026-06-09 15:02:45'),
('CR', '*COORDINACION DE RENTAS', '', 0, 'COORDINADOR', 'JOSE LUIS ESTEVE', '', 'CRE', '', 1, '2026-06-09 15:02:45'),
('CT', 'COORDINADORA DE TURISMO', '', 0, '', '', '', 'CTU', '', 0, '2026-06-09 15:02:45'),
('CU', '*COORDINACIÓN DE TURISMO', '', 0, 'COORDINADOR', '', '', 'CT1', '', 0, '2026-06-09 15:02:45'),
('CV', 'COORDINADOR DE COLONIAS DE VERANO', '', 0, '', '', '', '', '', 0, '2026-06-09 15:02:45'),
('CX', '*DIRECCION DE TURISMO Y CULTURA ', '', 0, 'DIRECTORA', 'PAMELA DEL CARMEN CASTRO', '', 'TC9', '', 1, '2026-06-09 15:02:45'),
('CY', '*COORD.DE COMUNICACION Y CONTENIDOS', '', 0, 'COORDINADOR', 'DANIEL TEJADA', '', 'CCC', '', 1, '2026-06-09 15:02:45'),
('CZ', '*COORDINACION DE JUVENTUDES', '', 0, 'COORDINADOR', 'NICOLAS AGÜERO', '', 'CJU', '', 1, '2026-06-09 15:02:45'),
('D1', '*DIRECCIÓN DE SERVICIOS PUBLICOS', '', 0, '', '', '', 'D11', '', 1, '2026-06-09 15:02:45'),
('D2', 'DIRECTOR DE ACCION SOCIAL', '', 0, '', '', '', '', '', 0, '2026-06-09 15:02:45'),
('D3', 'DIRECTOR ZONA NORTE', '', 0, '', '', '', '', '', 0, '2026-06-09 15:02:45'),
('D4', 'DIRECTOR ZONA SUR', '', 0, '', '', '', '', '', 0, '2026-06-09 15:02:45'),
('D5', '*COORDINACIÓN DE SERVICIOS', '', 0, '', '', '', 'D51', '', 1, '2026-06-09 15:02:45'),
('D6', '*DIRECCIÓN DE CULTURA', '', 0, '', 'DANIEL DIAZ', '', 'D61', '', 0, '2026-06-09 15:02:45'),
('D9', '*DIRECCION DE DEPORTES', '', 0, '', '', '', 'DDE', '', 0, '2026-06-09 15:02:45'),
('DA', 'DEPOSITO DE ACCION SOCIAL', '', 0, 'DEPOSITO DE ACCION SOCIAL', 'DEPOSITO DE ACCION SOCIAL', '', '', '', 0, '2026-06-09 15:02:45'),
('DC', 'DC', '', 0, 'DIRECTOR DE CULTURA', 'DIRECTOR DE CULTURA', '', '', '', 0, '2026-06-09 15:02:45'),
('DD', '*OFICINA DE DEPORTES', '', 0, 'DIRECTORES Y COORDINADORES', 'PELAITAY, JULIO, GALLARDO', '', 'DDD', '', 1, '2026-06-09 15:02:45'),
('DE', '*DESPACHO ', '', 0, 'DESPACHO ', 'DESPACHO ', '', 'DE1', '', 1, '2026-06-09 15:02:45'),
('DI', '*DIR GOB. ELECTRONICA E INFORMÁTICA', '', 0, '', '', '', 'DI1', '', 0, '2026-06-09 15:02:45'),
('DO', 'DEPOSITO', '', 0, 'DEPOSITO', 'DEPOSITO', '', '', '', 0, '2026-06-09 15:02:45'),
('DP', '*COORDINACIÓN DE DEPORTES', '', 0, 'DEPORTES', '', '', 'DP1', '', 0, '2026-06-09 15:02:45'),
('DX', '*DIRECCION DE PLANIFICACION', '', 0, 'DIRECTOR', 'CRISTIAN ALBERTO MORALES', '', 'DPL', '', 1, '2026-06-09 15:02:45'),
('EE', 'INICIADOR EXTERNO', ' ', 0, ' ', ' ', ' ', ' ', ' ', 1, '2026-06-09 15:02:45'),
('EL', 'ELECTRICISTA', '', 0, 'ELECTRICISTA', 'ELECTRICISTA', '', '', '', 0, '2026-06-09 15:02:45'),
('EX', 'ENTE EXTERNO', '', 0, '', '', '', 'EX1', '', 0, '2026-06-09 15:02:45'),
('GO', '*SECRETARIA DE GOBIERNO', '', 0, '', 'SERGIO SEPÚLVEDA', '', 'GO1', '', 1, '2026-06-09 15:02:45'),
('IE', 'INICIADORES EXTERNOS', '', 0, 'INICIADORES EXTERNOS', 'INICIADORES EXTERNOS', '', '', '', 0, '2026-06-09 15:02:45'),
('IG', '*COORD.INFORMÁTICA& GOB.ELECTRONICO', '', 0, '', '', '', 'IGB', '', 1, '2026-06-09 15:02:45'),
('IM', 'IM', '', 0, 'INSPECTORES MUNICIPALES', '', '', 'IMI', '', 0, '2026-06-09 15:02:45'),
('IN', '*INTENDENCIA', '', 0, 'INTENDENCIA', 'INTENDENCIA', '', 'IN1', '', 1, '2026-06-09 15:02:45'),
('IR', 'INSPECTOR', '', 0, 'INSPECTOR MUNICIPAL', 'INSPECTOR MUNICIPAL', '', '', '', 0, '2026-06-09 15:02:45'),
('IS', 'INSPECCION', '', 0, 'INSPECCION', 'INSPECCION', '', '', '', 0, '2026-06-09 15:02:45'),
('IT', 'INTENDENTE MUNICIPAL', '', 0, '', '', '', '', '', 0, '2026-06-09 15:02:45'),
('IU', '*OFICINA INSPECCIONES MUNICIPALES', '', 0, '', 'INSPECTOR MUNICIPAL', '', 'IU1', '', 1, '2026-06-09 15:02:45'),
('J3', 'JEFE DE COMPRAS', '', 0, '', '', '', '', '', 0, '2026-06-09 15:02:45'),
('JA', 'SUBSECRETARIO DE PROYECTOS ESPECIAL', '', 0, '', '', '', '', '', 0, '2026-06-09 15:02:45'),
('JC', 'JEFE  DE CEMENTERIO', '', 0, 'JEFE DE SECCION CEMENTERIO', '', '', '', '', 0, '2026-06-09 15:02:45'),
('JF', '*JUZGADO DE FALTAS Y CONVIVENCIA', '', 0, 'JUEZ', 'JUEZ SERGIO MARTIN ESCAMILLA', '', 'JFC', '', 1, '2026-06-09 15:02:45'),
('JJ', 'JEFE OFICINA DESPACHO Y ARCHIVO', '', 0, '', '', '', '', '', 0, '2026-06-09 15:02:45'),
('JM', 'JEFE MESA DE ENTRADAS Y SALIDAS', '', 0, '', '', '', '', '', 0, '2026-06-09 15:02:45'),
('JP', 'JUZGADO DE PAZ', '', 0, 'JUZGADO DE PAZ', 'JUZGADO DE PAZ', '', '', '', 1, '2026-06-09 15:02:45'),
('ME', '*MESA DE ENTRADAS Y SALIDAS', '', 0, '', 'MESA DE ENTRADA', '', 'ME1', '', 1, '2026-06-09 15:02:45'),
('MU', '*DIR.MUJER, DIVERSIDAD Y GENERO', '', 0, '', 'MARIA ROMINA SALICA', '', 'MU1', '', 1, '2026-06-09 15:02:45'),
('OC', '*COORDINACION ZONA NORTE', '', 0, '*COORDINACION ZONA NORTE', '*COORDINACION ZONA NORTE', '', 'CZN', '', 1, '2026-06-09 15:02:45'),
('OD', 'OD', '', 0, 'OFICINA DE DESPACHO', '', '', 'ODO', '', 0, '2026-06-09 15:02:45'),
('OE', '*OF COORDINACIÓN DE PLANES SOCIALES', '', 0, '', '', '', 'OE1', '', 1, '2026-06-09 15:02:45'),
('OO', '*COORDINADOR DE OFICINA DE EMPLEO', '', 0, 'COORDINADOR DE OFICINA DE EMPLEO', 'COORDINADOR DE OFICINA DE EMPLEO', '', 'O12', '', 1, '2026-06-09 15:02:45'),
('OP', '*SEC OBRAS Y SERVICIOS PÚBLICOS', '', 0, '', '', '', 'OP1', '', 1, '2026-06-09 15:02:45'),
('OS', '*DELEGACION OBRA SOCIAL', '', 0, 'OBRA SOCIAL', 'OBRA SOCIAL', '', 'ZZ1', '', 1, '2026-06-09 15:02:45'),
('OX', '*DIRECCION DE OBRAS', '', 0, 'DIRECTOR', 'NESTOR ROLANDO MANRIQUE', '', 'DOB', '', 1, '2026-06-09 15:02:45'),
('PA', 'OFICINA DE PATRIMONIO', '', 0, 'OFICINA DE PATRIMONIO', 'OFICINA DE PATRIMONIO', '', '', '', 0, '2026-06-09 15:02:45'),
('PC', 'PRESIDENTE CONCEJO DELIBERANTE', '', 0, '', '', '', '', '', 0, '2026-06-09 15:02:45'),
('PD', '*PUNTO DIGITAL SUR', '', 0, '', 'LEONARDO DIAZ', '', 'PD1', '', 1, '2026-06-09 15:02:45'),
('PE', '*OFICINA DE PERSONAL', '', 0, '', '', '', 'PE1', '', 1, '2026-06-09 15:02:45'),
('PM', 'PUESTA EN MARCHA', '', 0, 'PUESTA EN MARCHA', 'PUESTA EN MARCHA', '', '', '', 0, '2026-06-09 15:02:45'),
('PO', 'POLIDEPORTIVO MUNICIPAL', '', 0, 'POLIDEPORTIVO MUNICIPAL', 'POLIDEPORTIVO MUNICIPAL', '', '', '', 0, '2026-06-09 15:02:45'),
('PT', '*SUB-SEC DE PRODUCCIÓN Y TURISMO', '', 0, '', '', '', 'PT1', '', 0, '2026-06-09 15:02:45'),
('PU', '*DIRECCIÓN DE OBRAS PUBLICAS', '', 0, '', '', '', 'DOP', '', 1, '2026-06-09 15:02:45'),
('RE', '*DIRECCIÓN DE RENTAS', '', 0, '', '', '', 'RE1', '', 1, '2026-06-09 15:02:45'),
('S1', 'CABRERA EDUARDO SEC OBRAS', '', 0, '', '', '', '000', '', 0, '2026-06-09 15:02:45'),
('S2', 'SECRETARIO DE DEPORTES Y CULTURA', '', 0, '', '', '', '', '', 0, '2026-06-09 15:02:45'),
('S3', '*COORDINACIÓN DE ACCIÓN SOCIAL', '', 0, '', '', '', 'ACA', '', 1, '2026-06-09 15:02:45'),
('S4', 'CONTADOR MUNICIPAL', '', 0, '', '', '', '111', '', 1, '2026-06-09 15:02:45'),
('S5', 'TESORERO MUNICIPAL', '', 0, '', '', '', '', '', 0, '2026-06-09 15:02:45'),
('S6', 'DIRECTOR DE RENTAS', '', 0, '', '', '', '', '', 0, '2026-06-09 15:02:45'),
('S7', '*OFICINA DE SERVICIO SOCIAL', '', 0, '', '', '', 'S71', '', 1, '2026-06-09 15:02:45'),
('S8', 'CABRERA EDUARDO (SEC. OBRAS)', '', 0, '', '', '', 'S88', '', 0, '2026-06-09 15:02:45'),
('SD', '*SECRETARIA DE DESARROLLO SOCIAL', '', 0, 'SECRETARIA', '', '', 'SDS', '', 1, '2026-06-09 15:02:45'),
('SH', '*SEC DE ADMINISTRACIÓN Y HACIENDA', '', 0, 'SECRETARIA DE HACIENDA', 'FEDERICO BARCELO', '', 'SH1', '', 1, '2026-06-09 15:02:45'),
('SI', '*SUB SECRETARIA DE PROD. E INDUSTR', '', 0, 'SUB SECRETARIO', '', '', 'SI1', '', 0, '2026-06-09 15:02:45'),
('SP', 'SALA DE PRIMEROS AUXILIOS', '', 0, 'SALA DE PRIMEROS AUXILIOS', 'SALA DE PRIMEROS AUXILIOS', '', '', '', 0, '2026-06-09 15:02:45'),
('ST', 'COORDINADOR DE DEPORTES', '', 0, 'COORDINADOR DE DEPORTES', '', '', '', '', 0, '2026-06-09 15:02:45'),
('TA', '*SUB-SEC DE OBRAS Y SERV PÚBLICOS', '', 0, '', '', '', 'TA1', '', 1, '2026-06-09 15:02:45'),
('TE', '*TESORERIA', '', 0, '', 'RAMON JOSE FUNES', '', 'TE1', '', 1, '2026-06-09 15:02:45'),
('TR', 'OTROS', '', 0, 'OTROS', 'OTROS', '', '', '', 0, '2026-06-09 15:02:45'),
('TU', 'AREA DE TURISMO', '', 0, 'AREA DE TURISMO', 'AREA DE TURISMO', '', '', '', 0, '2026-06-09 15:02:45'),
('XZ', '*COORD.OBRAS ZONA SUR', '', 0, '', '', '', 'CZS', '', 1, '2026-06-09 15:02:45'),
('ZZ', 'Puesta en Marcha', 'Para la migracion masiva de expedientes anteriores a la puesta en marcha', 0, '', '', '', '', '', 0, '2026-06-09 15:02:45');

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `sectmuni`
--
ALTER TABLE `sectmuni`
  ADD PRIMARY KEY (`CODIGO`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
