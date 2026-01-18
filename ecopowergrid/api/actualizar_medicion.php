<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/config.php'; // Conexión PDO

$csv = __DIR__ . '/base.csv';
if (!is_file($csv)) {
    echo json_encode(['ok' => false, 'message' => 'Archivo base.csv no encontrado']);
    exit;
}

// Leer última línea del CSV
$handle = fopen($csv, 'r');
$header = fgetcsv($handle);
$lastRow = null;
while (($row = fgetcsv($handle)) !== false) {
    $lastRow = $row;
}
fclose($handle);

if (!$lastRow) {
    echo json_encode(['ok' => false, 'message' => 'CSV vacío']);
    exit;
}

$data = array_combine($header, $lastRow);

// Convertir fecha local a UTC
$fechaLocal = DateTime::createFromFormat('d/m/Y H:i:s', $data['RecordTime'], new DateTimeZone('America/Guayaquil'));
$tsUtc = $fechaLocal ? $fechaLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');

// Preparar valores
$dispositivoId       = 1; // Ajustar según tu lógica
$voltajeBateria      = (float)str_replace(',', '.', $data['Battery voltage']);
$voltajeACSalida     = (float)str_replace(',', '.', $data['AC voltage grade']);
$voltajeACEntrada    = (float)str_replace(',', '.', $data['Grid voltage']);
$potenciaSalidaAC    = (float)str_replace(',', '.', $data['Rated power(VA)']);
$voltajePV           = isset($data['PV voltage']) ? (float)str_replace(',', '.', $data['PV voltage']) : null;
$corrientePV         = isset($data['Charger current']) ? (float)str_replace(',', '.', $data['Charger current']) : null;

// Actualizar o insertar
try {
    $sql = "
        INSERT INTO mediciones (
            dispositivo_id, ts_utc, voltaje_bateria_v, voltaje_salida_ac_v, voltaje_entrada_ac_v,
            potencia_salida_ac_w, voltaje_pv_v, corriente_pv_a
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            voltaje_bateria_v = VALUES(voltaje_bateria_v),
            voltaje_salida_ac_v = VALUES(voltaje_salida_ac_v),
            voltaje_entrada_ac_v = VALUES(voltaje_entrada_ac_v),
            potencia_salida_ac_w = VALUES(potencia_salida_ac_w),
            voltaje_pv_v = VALUES(voltaje_pv_v),
            corriente_pv_a = VALUES(corriente_pv_a)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $dispositivoId, $tsUtc, $voltajeBateria, $voltajeACSalida, $voltajeACEntrada,
        $potenciaSalidaAC, $voltajePV, $corrientePV
    ]);

    echo json_encode(['ok' => true, 'message' => 'Registro actualizado correctamente']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
