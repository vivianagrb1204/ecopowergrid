SET @@SESSION.time_zone = '+00:00';

-- 1) Crear Base de Datos
CREATE DATABASE IF NOT EXISTS ecopowergrid_monitoreo
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ecopowergrid_monitoreo;

-- 2) Tablas núcleo (igual que el esquema base, pero en español)
CREATE TABLE IF NOT EXISTS dispositivos (
  dispositivo_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  numero_serie VARCHAR(64) NOT NULL UNIQUE,
  modelo VARCHAR(64) NOT NULL DEFAULT 'PV3600 PRO',
  etiqueta_ubicacion VARCHAR(128) NULL,
  fecha_instalacion DATE NULL,
  version_firmware VARCHAR(32) NULL,
  notas TEXT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS modulos_wifi (
  wifi_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dispositivo_id BIGINT UNSIGNED NOT NULL,
  direccion_mac VARCHAR(64) NOT NULL UNIQUE,
  proveedor VARCHAR(64) NULL,
  nombre_app VARCHAR(64) NULL,
  ultima_vista_utc DATETIME NULL,
  red_ok TINYINT(1) NOT NULL DEFAULT 0,
  srv_ok TINYINT(1) NOT NULL DEFAULT 0,
  com_ok TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (dispositivo_id) REFERENCES dispositivos(dispositivo_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS configuracion_dispositivo (
  dispositivo_id BIGINT UNSIGNED PRIMARY KEY,
  modo_prioridad ENUM('SBU','SUB','UtilityFirst') NULL,
  corriente_carga_a DECIMAL(6,2) NULL,
  voltaje_flotacion_v DECIMAL(6,2) NULL,
  voltaje_bulk_v DECIMAL(6,2) NULL,
  voltaje_corte_bateria_v DECIMAL(6,2) NULL,
  voltaje_recuperacion_bateria_v DECIMAL(6,2) NULL,
  alarma_bateria_alta_v DECIMAL(6,2) NULL,
  recuperacion_bateria_alta_v DECIMAL(6,2) NULL,
  eficiencia_mppt_pct DECIMAL(5,2) NULL,
  entrada_ac_seleccionable VARCHAR(32) NULL,
  actualizado_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (dispositivo_id) REFERENCES dispositivos(dispositivo_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mediciones (
  medicion_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dispositivo_id BIGINT UNSIGNED NOT NULL,
  ts_utc DATETIME(3) NOT NULL,
  voltaje_bateria_v DECIMAL(6,2) NULL,
  corriente_bateria_a DECIMAL(7,3) NULL,
  soc_bateria_pct DECIMAL(5,2) NULL,
  temperatura_bateria_c DECIMAL(5,2) NULL,
  voltaje_pv_v DECIMAL(6,2) NULL,
  corriente_pv_a DECIMAL(7,3) NULL,
  potencia_pv_w DECIMAL(10,2) NULL,
  estado_mppt ENUM('tracking','idle','fault','off') NULL,
  voltaje_salida_ac_v DECIMAL(6,2) NULL,
  frecuencia_salida_ac_hz DECIMAL(6,2) NULL,
  potencia_salida_ac_w DECIMAL(10,2) NULL,
  fp_salida_ac DECIMAL(5,3) NULL,
  carga_pct DECIMAL(5,2) NULL,
  entrada_ac_presente TINYINT(1) NOT NULL DEFAULT 0,
  voltaje_entrada_ac_v DECIMAL(6,2) NULL,
  frecuencia_entrada_ac_hz DECIMAL(6,2) NULL,
  modo_inversor ENUM('inverter','bypass','line','off') NULL,
  modo_prioridad ENUM('SBU','SUB','UtilityFirst') NULL,
  energia_pv_dia_wh DECIMAL(12,2) NULL,
  energia_pv_total_kwh DECIMAL(12,3) NULL,
  payload_crudo JSON NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_med_dispositivo_ts (dispositivo_id, ts_utc),
  INDEX idx_med_acin_ts (entrada_ac_presente, ts_utc),
  FOREIGN KEY (dispositivo_id) REFERENCES dispositivos(dispositivo_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS entradas_pv (
  entrada_pv_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  medicion_id BIGINT UNSIGNED NOT NULL,
  etiqueta_entrada VARCHAR(16) NOT NULL,
  voltaje_v DECIMAL(6,2) NULL,
  corriente_a DECIMAL(7,3) NULL,
  potencia_w DECIMAL(10,2) NULL,
  INDEX idx_pv_medicion (medicion_id),
  FOREIGN KEY (medicion_id) REFERENCES mediciones(medicion_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS alarmas (
  alarma_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dispositivo_id BIGINT UNSIGNED NOT NULL,
  ts_utc DATETIME(3) NOT NULL,
  codigo VARCHAR(64) NOT NULL,
  severidad ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
  mensaje VARCHAR(255) NULL,
  resuelta_utc DATETIME(3) NULL,
  reconocida_por VARCHAR(64) NULL,
  INDEX idx_alarma_dispositivo_ts (dispositivo_id, ts_utc),
  FOREIGN KEY (dispositivo_id) REFERENCES dispositivos(dispositivo_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS energia_diaria (
  dispositivo_id BIGINT UNSIGNED NOT NULL,
  dia_utc DATE NOT NULL,
  energia_pv_wh DECIMAL(12,2) NOT NULL,
  energia_carga_wh DECIMAL(12,2) NULL,
  energia_red_wh DECIMAL(12,2) NULL,
  PRIMARY KEY (dispositivo_id, dia_utc),
  FOREIGN KEY (dispositivo_id) REFERENCES dispositivos(dispositivo_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 3) Procedimientos para inicializar y gestionar particiones mensuales en 'mediciones'
DELIMITER $$

CREATE PROCEDURE sp_primer_dia_mes(IN d DATE, OUT primer_dia DATE)
BEGIN
  SET primer_dia = DATE_SUB(d, INTERVAL DAY(d)-1 DAY);
END$$

CREATE PROCEDURE sp_inicializar_particiones_mediciones(IN meses_atras INT, IN meses_adelante INT)
BEGIN
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

-- Procedimiento corregido (todas las DECLARE al inicio)
CREATE PROCEDURE sp_gestionar_particiones_mediciones(IN meses_retencion INT, IN meses_adelante INT)
BEGIN
  -- Todas las DECLARE deben ir al inicio del bloque BEGIN ... END
  DECLARE mes_actual DATE;
  DECLARE mes_objetivo DATE;
  DECLARE limite DATE;
  DECLARE pname VARCHAR(20);
  DECLARE i INT DEFAULT 0;
  DECLARE terminado INT DEFAULT 0;
  DECLARE nombre_particion VARCHAR(64);
  DECLARE descripcion_particion VARCHAR(64);

  -- Cursor y handler declarados antes de cualquier otra sentencia
  DECLARE cur CURSOR FOR
    SELECT PARTITION_NAME, PARTITION_DESCRIPTION
    FROM INFORMATION_SCHEMA.PARTITIONS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME='mediciones'
      AND PARTITION_NAME IS NOT NULL;

  DECLARE CONTINUE HANDLER FOR NOT FOUND SET terminado = 1;

  -- Si la tabla aún no está particionada, inicializar con 12 meses atrás y meses_adelante hacia adelante
  SELECT COUNT(*) INTO @es_part
  FROM INFORMATION_SCHEMA.PARTITIONS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mediciones';

  IF @es_part = 0 THEN
    CALL sp_inicializar_particiones_mediciones(12, meses_adelante);
  END IF;

  CALL sp_primer_dia_mes(CURRENT_DATE(), mes_actual);

  -- Asegurar particiones hasta meses_adelante
  WHILE i <= meses_adelante DO
    SET mes_objetivo = DATE_ADD(mes_actual, INTERVAL i MONTH);
    SET pname = CONCAT('p', DATE_FORMAT(mes_objetivo, '%Y_%m'));

    SELECT COUNT(*) INTO @existe
    FROM INFORMATION_SCHEMA.PARTITIONS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'mediciones'
      AND PARTITION_NAME = pname;

    IF @existe = 0 THEN
      SET limite = DATE_ADD(LAST_DAY(mes_objetivo), INTERVAL 1 DAY);
      SET @sql = CONCAT(
        'ALTER TABLE mediciones REORGANIZE PARTITION pMax INTO (',
        'PARTITION ', pname, ' VALUES LESS THAN (TO_DAYS(\'', DATE_FORMAT(limite, '%Y-%m-%d'), '\')), ',
        'PARTITION pMax VALUES LESS THAN (MAXVALUE))'
      );
      PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    SET i = i + 1;
  END WHILE;

  -- Eliminar particiones antiguas más allá de la retención
  SET @mes_corte = DATE_SUB(mes_actual, INTERVAL meses_retencion MONTH);

  OPEN cur;
  leer: LOOP
    FETCH cur INTO nombre_particion, descripcion_particion;
    IF terminado = 1 THEN LEAVE leer; END IF;

    IF nombre_particion <> 'pMax' THEN
      -- PARTITION_DESCRIPTION para RANGE(TO_DAYS()) es el límite numérico
      SET @fecha_limite = FROM_DAYS(CAST(descripcion_particion AS UNSIGNED));
      -- La partición contiene datos < fecha_limite; eliminar si fecha_limite <= primer día del mes de corte
      IF @fecha_limite <= DATE_ADD(@mes_corte, INTERVAL 1 MONTH) THEN
        SET @sql_drop = CONCAT('ALTER TABLE mediciones DROP PARTITION ', nombre_particion);
        PREPARE stmt2 FROM @sql_drop; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;
      END IF;
    END IF;
  END LOOP;
  CLOSE cur;
END$$

DELIMITER ;

-- 4) Inicialización de particionado (opcional en primera ejecución)
-- Crea particiones mensuales desde 12 meses atrás hasta 2 meses adelante y pMax.
CALL sp_inicializar_particiones_mediciones(12, 2);

-- 5) Habilitar el programador de eventos y crear evento diario para asegurar las siguientes particiones
SET GLOBAL event_scheduler = ON;

DROP EVENT IF EXISTS ev_gestionar_particiones_mediciones;
CREATE EVENT ev_gestionar_particiones_mediciones
  ON SCHEDULE EVERY 1 DAY
  STARTS CURRENT_TIMESTAMP + INTERVAL 1 MINUTE
DO
  CALL sp_gestionar_particiones_mediciones(12, 2);

-- 6) Vista y usuario de aplicación
CREATE OR REPLACE VIEW v_dispositivo_ultimo AS
SELECT m.*
FROM mediciones m
JOIN (
  SELECT dispositivo_id, MAX(ts_utc) AS max_ts
  FROM mediciones
  GROUP BY dispositivo_id
) ult
ON ult.dispositivo_id = m.dispositivo_id AND ult.max_ts = m.ts_utc;

CREATE USER IF NOT EXISTS 'ecopower_app'@'%'
  IDENTIFIED BY 'CambiaEstaClaveSegura!';
GRANT SELECT, INSERT, UPDATE, DELETE, EVENT
  ON ecopowergrid_monitoreo.* TO 'ecopower_app'@'%';
FLUSH PRIVILEGES;

-- Notas:
-- - Ejecuta el script con un usuario privilegiado (privilegio EVENT y capacidad de SET GLOBAL event_scheduler).
-- - Retención por defecto: 12 meses (ajusta en la llamada del evento).
-- - El evento corre a diario, asegura particiones para el mes actual y los próximos 2 meses, y borra lo anterior a la retención.
-- - Todas las horas se registran en UTC.