<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

function clean_val($v) {
    if ($v === null || $v === '') return 0;
    // Elimina unidades y espacios, cambia coma por punto
    $v = preg_replace('/[^0-9,.-]/', '', $v);
    return (float)str_replace(',', '.', $v);
}

$csv = __DIR__ . '/base.csv';
if (!file_exists($csv)) { die(json_encode(['ok' => false, 'message' => 'CSV no existe'])); }

$handle = fopen($csv, 'r');
$header = fgetcsv($handle);
$rows = [];
while (($row = fgetcsv($handle)) !== false) {
    if (count($header) === count($row)) $rows[] = array_combine($header, $row);
}
fclose($handle);

// Procesar datos
$dataSync = (isset($_GET['modo']) && $_GET['modo'] === 'todo') ? $rows : [end($rows)];
$count = 0;

try {
    $sql = "INSERT IGNORE INTO mediciones (dispositivo_id, ts_utc, voltaje_bateria_v, voltaje_salida_ac_v, voltaje_entrada_ac_v, potencia_salida_ac_w, voltaje_pv_v, corriente_pv_a) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);

    foreach ($dataSync as $r) {
        // Formato flexible para d/m/Y o j/n/Y
       $rawDate = $r['RecordTime'];
// Soporta formatos con un solo dígito en día/mes 
$dateObj = DateTime::createFromFormat('j/n/Y H:i:s', $rawDate, new DateTimeZone('America/Guayaquil'));

if ($dateObj) {
    $tsUtc = $dateObj->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
} else {
    $tsUtc = date('Y-m-d H:i:s');
}

        if (!$dateObj) continue;
        $tsUtc = $dateObj->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $stmt->execute([
            1,
            $tsUtc,
            clean_val($r['Battery voltage']),
            clean_val($r['Inverter voltage']),
            clean_val($r['Grid voltage']),
            clean_val($r['PLoad']),
            clean_val($r['PV voltage'] ?? 0),
            clean_val($r['Charger current'] ?? 0)
        ]);
        $count++;
    }
    echo json_encode(['ok' => true, 'message' => "Sincronizados $count registros."]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}