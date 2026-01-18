-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1:3307
-- Tiempo de generación: 16-01-2026 a las 17:30:48
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `ecopowergrid_monitoreo`
--

DELIMITER $$
--
-- Procedimientos
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_inicializar_particiones_mediciones` (IN `meses_atras` INT, IN `meses_adelante` INT)   BEGIN
  DECLARE mes_inicio DATE;
  DECLARE mes_fin DATE;
  DECLARE d DATE;
  DECLARE limite DATE;

  -- Rango: [mes_actual - meses_atras, mes_actual + meses_adelante]
  CALL sp_primer_dia_mes(CURRENT_DATE(), mes_inicio);
  SET mes_inicio = DATE_SUB(mes_inicio, INTERVAL meses_atras MONTH);
  SET mes_fin = DATE_ADD(mes_inicio, INTERVAL meses_atras + meses_adelante + 1 MONTH);
  SET d = mes_inicio;

  SET @sql = 'ALTER TABLE mediciones PARTITION BY RANGE (TO_DAYS(ts_utc)) (';
  WHILE d < mes_fin DO
    SET limite = DATE_ADD(LAST_DAY(d), INTERVAL 1 DAY);
    SET @pname = CONCAT('p', DATE_FORMAT(d, '%Y_%m'));
    SET @sql = CONCAT(@sql,
      'PARTITION ', @pname,
      ' VALUES LESS THAN (TO_DAYS(\'', DATE_FORMAT(limite, '%Y-%m-%d'), '\')),' );
    SET d = DATE_ADD(d, INTERVAL 1 MONTH);
  END WHILE;
  SET @sql = CONCAT(@sql, 'PARTITION pMax VALUES LESS THAN (MAXVALUE))');
  PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_primer_dia_mes` (IN `d` DATE, OUT `primer_dia` DATE)   BEGIN
  SET primer_dia = DATE_SUB(d, INTERVAL DAY(d)-1 DAY);
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `alarmas`
--

CREATE TABLE `alarmas` (
  `alarma_id` bigint(20) UNSIGNED NOT NULL,
  `dispositivo_id` bigint(20) UNSIGNED NOT NULL,
  `ts_utc` datetime(3) NOT NULL,
  `codigo` varchar(64) NOT NULL,
  `severidad` enum('info','warning','critical') NOT NULL DEFAULT 'warning',
  `mensaje` varchar(255) DEFAULT NULL,
  `resuelta_utc` datetime(3) DEFAULT NULL,
  `reconocida_por` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `configuracion_dispositivo`
--

CREATE TABLE `configuracion_dispositivo` (
  `dispositivo_id` bigint(20) UNSIGNED NOT NULL,
  `modo_prioridad` enum('SBU','SUB','UtilityFirst') DEFAULT NULL,
  `corriente_carga_a` decimal(6,2) DEFAULT NULL,
  `voltaje_flotacion_v` decimal(6,2) DEFAULT NULL,
  `voltaje_bulk_v` decimal(6,2) DEFAULT NULL,
  `voltaje_corte_bateria_v` decimal(6,2) DEFAULT NULL,
  `voltaje_recuperacion_bateria_v` decimal(6,2) DEFAULT NULL,
  `alarma_bateria_alta_v` decimal(6,2) DEFAULT NULL,
  `recuperacion_bateria_alta_v` decimal(6,2) DEFAULT NULL,
  `eficiencia_mppt_pct` decimal(5,2) DEFAULT NULL,
  `entrada_ac_seleccionable` varchar(32) DEFAULT NULL,
  `actualizado_utc` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `dispositivos`
--

CREATE TABLE `dispositivos` (
  `dispositivo_id` bigint(20) UNSIGNED NOT NULL,
  `numero_serie` varchar(64) NOT NULL,
  `modelo` varchar(64) NOT NULL DEFAULT 'PV3600 PRO',
  `etiqueta_ubicacion` varchar(128) DEFAULT NULL,
  `fecha_instalacion` date DEFAULT NULL,
  `version_firmware` varchar(32) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `dispositivos`
--

INSERT INTO `dispositivos` (`dispositivo_id`, `numero_serie`, `modelo`, `etiqueta_ubicacion`, `fecha_instalacion`, `version_firmware`, `notas`, `creado_en`) VALUES
(1, 'PV3600-01', 'PV3600 PRO Series', 'Quito - Lab Principal', '2026-01-11', NULL, NULL, '2026-01-11 15:32:11');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `energia_diaria`
--

CREATE TABLE `energia_diaria` (
  `dispositivo_id` bigint(20) UNSIGNED NOT NULL,
  `dia_utc` date NOT NULL,
  `energia_pv_wh` decimal(12,2) NOT NULL,
  `energia_carga_wh` decimal(12,2) DEFAULT NULL,
  `energia_red_wh` decimal(12,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `entradas_pv`
--

CREATE TABLE `entradas_pv` (
  `entrada_pv_id` bigint(20) UNSIGNED NOT NULL,
  `medicion_id` bigint(20) UNSIGNED NOT NULL,
  `etiqueta_entrada` varchar(16) NOT NULL,
  `voltaje_v` decimal(6,2) DEFAULT NULL,
  `corriente_a` decimal(7,3) DEFAULT NULL,
  `potencia_w` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `mediciones`
--

CREATE TABLE `mediciones` (
  `medicion_id` bigint(20) UNSIGNED NOT NULL,
  `dispositivo_id` bigint(20) UNSIGNED NOT NULL,
  `ts_utc` datetime(3) NOT NULL,
  `voltaje_bateria_v` decimal(6,2) DEFAULT NULL,
  `corriente_bateria_a` decimal(7,3) DEFAULT NULL,
  `soc_bateria_pct` decimal(5,2) DEFAULT NULL,
  `temperatura_bateria_c` decimal(5,2) DEFAULT NULL,
  `voltaje_pv_v` decimal(6,2) DEFAULT NULL,
  `corriente_pv_a` decimal(7,3) DEFAULT NULL,
  `potencia_pv_w` decimal(10,2) DEFAULT NULL,
  `estado_mppt` enum('tracking','idle','fault','off') DEFAULT NULL,
  `voltaje_salida_ac_v` decimal(6,2) DEFAULT NULL,
  `frecuencia_salida_ac_hz` decimal(6,2) DEFAULT NULL,
  `potencia_salida_ac_w` decimal(10,2) DEFAULT NULL,
  `fp_salida_ac` decimal(5,3) DEFAULT NULL,
  `carga_pct` decimal(5,2) DEFAULT NULL,
  `entrada_ac_presente` tinyint(1) NOT NULL DEFAULT 0,
  `voltaje_entrada_ac_v` decimal(6,2) DEFAULT NULL,
  `frecuencia_entrada_ac_hz` decimal(6,2) DEFAULT NULL,
  `modo_inversor` enum('inverter','bypass','line','off') DEFAULT NULL,
  `modo_prioridad` enum('SBU','SUB','UtilityFirst') DEFAULT NULL,
  `energia_pv_dia_wh` decimal(12,2) DEFAULT NULL,
  `energia_pv_total_kwh` decimal(12,3) DEFAULT NULL,
  `payload_crudo` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload_crudo`)),
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `mediciones`
--

INSERT INTO `mediciones` (`medicion_id`, `dispositivo_id`, `ts_utc`, `voltaje_bateria_v`, `corriente_bateria_a`, `soc_bateria_pct`, `temperatura_bateria_c`, `voltaje_pv_v`, `corriente_pv_a`, `potencia_pv_w`, `estado_mppt`, `voltaje_salida_ac_v`, `frecuencia_salida_ac_hz`, `potencia_salida_ac_w`, `fp_salida_ac`, `carga_pct`, `entrada_ac_presente`, `voltaje_entrada_ac_v`, `frecuencia_entrada_ac_hz`, `modo_inversor`, `modo_prioridad`, `energia_pv_dia_wh`, `energia_pv_total_kwh`, `payload_crudo`, `creado_en`) VALUES
(1, 1, '2026-01-11 11:02:09.000', 52.10, NULL, NULL, NULL, 320.50, 5.200, NULL, NULL, NULL, NULL, 740.00, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, '{\"src\": \"seed\"}', '2026-01-11 16:02:29'),
(2, 1, '2026-01-15 15:19:42.000', 52.30, NULL, NULL, NULL, 120.50, 10.200, NULL, NULL, NULL, NULL, 3500.00, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-01-15 15:19:42'),
(3, 1, '2026-01-15 15:30:09.000', 52.30, NULL, NULL, NULL, 120.50, 10.200, NULL, NULL, NULL, NULL, 3500.00, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-01-15 15:30:09'),
(4, 1, '2026-01-15 15:39:49.000', 52.30, NULL, NULL, NULL, 120.50, 10.200, NULL, NULL, NULL, NULL, 3500.00, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-01-15 15:39:49');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `modulos_wifi`
--

CREATE TABLE `modulos_wifi` (
  `wifi_id` bigint(20) UNSIGNED NOT NULL,
  `dispositivo_id` bigint(20) UNSIGNED NOT NULL,
  `direccion_mac` varchar(64) NOT NULL,
  `proveedor` varchar(64) DEFAULT NULL,
  `nombre_app` varchar(64) DEFAULT NULL,
  `ultima_vista_utc` datetime DEFAULT NULL,
  `red_ok` tinyint(1) NOT NULL DEFAULT 0,
  `srv_ok` tinyint(1) NOT NULL DEFAULT 0,
  `com_ok` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `v_dispositivo_ultimo`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `v_dispositivo_ultimo` (
`medicion_id` bigint(20) unsigned
,`dispositivo_id` bigint(20) unsigned
,`ts_utc` datetime(3)
,`voltaje_bateria_v` decimal(6,2)
,`corriente_bateria_a` decimal(7,3)
,`soc_bateria_pct` decimal(5,2)
,`temperatura_bateria_c` decimal(5,2)
,`voltaje_pv_v` decimal(6,2)
,`corriente_pv_a` decimal(7,3)
,`potencia_pv_w` decimal(10,2)
,`estado_mppt` enum('tracking','idle','fault','off')
,`voltaje_salida_ac_v` decimal(6,2)
,`frecuencia_salida_ac_hz` decimal(6,2)
,`potencia_salida_ac_w` decimal(10,2)
,`fp_salida_ac` decimal(5,3)
,`carga_pct` decimal(5,2)
,`entrada_ac_presente` tinyint(1)
,`voltaje_entrada_ac_v` decimal(6,2)
,`frecuencia_entrada_ac_hz` decimal(6,2)
,`modo_inversor` enum('inverter','bypass','line','off')
,`modo_prioridad` enum('SBU','SUB','UtilityFirst')
,`energia_pv_dia_wh` decimal(12,2)
,`energia_pv_total_kwh` decimal(12,3)
,`payload_crudo` longtext
,`creado_en` timestamp
);

-- --------------------------------------------------------

--
-- Estructura para la vista `v_dispositivo_ultimo`
--
DROP TABLE IF EXISTS `v_dispositivo_ultimo`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_dispositivo_ultimo`  AS SELECT `m`.`medicion_id` AS `medicion_id`, `m`.`dispositivo_id` AS `dispositivo_id`, `m`.`ts_utc` AS `ts_utc`, `m`.`voltaje_bateria_v` AS `voltaje_bateria_v`, `m`.`corriente_bateria_a` AS `corriente_bateria_a`, `m`.`soc_bateria_pct` AS `soc_bateria_pct`, `m`.`temperatura_bateria_c` AS `temperatura_bateria_c`, `m`.`voltaje_pv_v` AS `voltaje_pv_v`, `m`.`corriente_pv_a` AS `corriente_pv_a`, `m`.`potencia_pv_w` AS `potencia_pv_w`, `m`.`estado_mppt` AS `estado_mppt`, `m`.`voltaje_salida_ac_v` AS `voltaje_salida_ac_v`, `m`.`frecuencia_salida_ac_hz` AS `frecuencia_salida_ac_hz`, `m`.`potencia_salida_ac_w` AS `potencia_salida_ac_w`, `m`.`fp_salida_ac` AS `fp_salida_ac`, `m`.`carga_pct` AS `carga_pct`, `m`.`entrada_ac_presente` AS `entrada_ac_presente`, `m`.`voltaje_entrada_ac_v` AS `voltaje_entrada_ac_v`, `m`.`frecuencia_entrada_ac_hz` AS `frecuencia_entrada_ac_hz`, `m`.`modo_inversor` AS `modo_inversor`, `m`.`modo_prioridad` AS `modo_prioridad`, `m`.`energia_pv_dia_wh` AS `energia_pv_dia_wh`, `m`.`energia_pv_total_kwh` AS `energia_pv_total_kwh`, `m`.`payload_crudo` AS `payload_crudo`, `m`.`creado_en` AS `creado_en` FROM (`mediciones` `m` join (select `mediciones`.`dispositivo_id` AS `dispositivo_id`,max(`mediciones`.`ts_utc`) AS `max_ts` from `mediciones` group by `mediciones`.`dispositivo_id`) `ult` on(`ult`.`dispositivo_id` = `m`.`dispositivo_id` and `ult`.`max_ts` = `m`.`ts_utc`)) ;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `alarmas`
--
ALTER TABLE `alarmas`
  ADD PRIMARY KEY (`alarma_id`),
  ADD KEY `idx_alarma_dispositivo_ts` (`dispositivo_id`,`ts_utc`);

--
-- Indices de la tabla `configuracion_dispositivo`
--
ALTER TABLE `configuracion_dispositivo`
  ADD PRIMARY KEY (`dispositivo_id`);

--
-- Indices de la tabla `dispositivos`
--
ALTER TABLE `dispositivos`
  ADD PRIMARY KEY (`dispositivo_id`),
  ADD UNIQUE KEY `numero_serie` (`numero_serie`);

--
-- Indices de la tabla `energia_diaria`
--
ALTER TABLE `energia_diaria`
  ADD PRIMARY KEY (`dispositivo_id`,`dia_utc`);

--
-- Indices de la tabla `entradas_pv`
--
ALTER TABLE `entradas_pv`
  ADD PRIMARY KEY (`entrada_pv_id`),
  ADD KEY `idx_pv_medicion` (`medicion_id`);

--
-- Indices de la tabla `mediciones`
--
ALTER TABLE `mediciones`
  ADD PRIMARY KEY (`medicion_id`),
  ADD UNIQUE KEY `uniq_dispositivo_ts` (`dispositivo_id`,`ts_utc`),
  ADD KEY `idx_med_dispositivo_ts` (`dispositivo_id`,`ts_utc`),
  ADD KEY `idx_med_acin_ts` (`entrada_ac_presente`,`ts_utc`);

--
-- Indices de la tabla `modulos_wifi`
--
ALTER TABLE `modulos_wifi`
  ADD PRIMARY KEY (`wifi_id`),
  ADD UNIQUE KEY `direccion_mac` (`direccion_mac`),
  ADD KEY `dispositivo_id` (`dispositivo_id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `alarmas`
--
ALTER TABLE `alarmas`
  MODIFY `alarma_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `dispositivos`
--
ALTER TABLE `dispositivos`
  MODIFY `dispositivo_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `entradas_pv`
--
ALTER TABLE `entradas_pv`
  MODIFY `entrada_pv_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `mediciones`
--
ALTER TABLE `mediciones`
  MODIFY `medicion_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de la tabla `modulos_wifi`
--
ALTER TABLE `modulos_wifi`
  MODIFY `wifi_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `alarmas`
--
ALTER TABLE `alarmas`
  ADD CONSTRAINT `alarmas_ibfk_1` FOREIGN KEY (`dispositivo_id`) REFERENCES `dispositivos` (`dispositivo_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `configuracion_dispositivo`
--
ALTER TABLE `configuracion_dispositivo`
  ADD CONSTRAINT `configuracion_dispositivo_ibfk_1` FOREIGN KEY (`dispositivo_id`) REFERENCES `dispositivos` (`dispositivo_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `energia_diaria`
--
ALTER TABLE `energia_diaria`
  ADD CONSTRAINT `energia_diaria_ibfk_1` FOREIGN KEY (`dispositivo_id`) REFERENCES `dispositivos` (`dispositivo_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `entradas_pv`
--
ALTER TABLE `entradas_pv`
  ADD CONSTRAINT `entradas_pv_ibfk_1` FOREIGN KEY (`medicion_id`) REFERENCES `mediciones` (`medicion_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `mediciones`
--
ALTER TABLE `mediciones`
  ADD CONSTRAINT `mediciones_ibfk_1` FOREIGN KEY (`dispositivo_id`) REFERENCES `dispositivos` (`dispositivo_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `modulos_wifi`
--
ALTER TABLE `modulos_wifi`
  ADD CONSTRAINT `modulos_wifi_ibfk_1` FOREIGN KEY (`dispositivo_id`) REFERENCES `dispositivos` (`dispositivo_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
