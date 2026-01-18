<?php
header('Content-Type: application/json; charset=utf-8');

$csv = __DIR__ . '/base.csv';
if (!file_exists($csv)) {
    echo json_encode(['ok' => false, 'message' => 'Archivo base.csv no encontrado']);
    exit;
}

$handle = fopen($csv, 'r');
$header = fgetcsv($handle);
if (!$header) {
    echo json_encode(['ok' => false, 'message' => 'Encabezado CSV inválido']);
    exit;
}

// Normalizar encabezados
$headerNorm = array_map(fn($h) => strtolower(str_replace([' ', '(', ')'], '', trim($h))), $header);

// Leer filas
$rows = [];
while (($row = fgetcsv($handle)) !== false) {
    $assoc = [];
    foreach ($headerNorm as $i => $key) {
        $assoc[$key] = $row[$i] ?? '';
    }
    $rows[] = $assoc;
}
fclose($handle);

$action = $_GET['action'] ?? 'latest';
if (empty($rows)) {
    echo json_encode(['ok' => true, 'data' => null]);
    exit;
}

// Función para convertir coma decimal a punto
function num($v) {
    return (float)str_replace(',', '.', $v);
}

// Mapeo de claves normalizadas
$K_RECORD = 'recordtime';
$K_AC = 'acvoltagegrade';
$K_VA = 'ratedpowerva';
$K_BATT = 'batteryvoltage';
$K_INV = 'invertervoltage';
$K_GRID = 'gridvoltage';

if ($action === 'latest') {
    $last = end($rows);
    $out = [
        'recordTime' => $last[$K_RECORD] ?? null,
        'acVoltage' => num($last[$K_AC] ?? 0),
        'ratedPower' => num($last[$K_VA] ?? 0),
        'batteryVoltage' => num($last[$K_BATT] ?? 0),
        'inverterVoltage' => num($last[$K_INV] ?? 0),
        'gridVoltage' => num($last[$K_GRID] ?? 0),
    ];
    echo json_encode(['ok' => true, 'data' => $out]);
    exit;
}

if ($action === 'series') {
    $from = $_GET['from'] ?? null;
    $to = $_GET['to'] ?? null;
    if (!$from || !$to) {
        echo json_encode(['ok' => false, 'message' => 'Parámetros from/to requeridos']);
        exit;
    }

    $labels = [];
    $acVoltage = [];
    $ratedPower = [];
    $batteryVoltage = [];
    $inverterVoltage = [];
    $gridVoltage = [];

    foreach ($rows as $r) {
        $labels[] = $r[$K_RECORD];
        $acVoltage[] = num($r[$K_AC]);
        $ratedPower[] = num($r[$K_VA]);
        $batteryVoltage[] = num($r[$K_BATT]);
        $inverterVoltage[] = num($r[$K_INV]);
        $gridVoltage[] = num($r[$K_GRID]);
    }

    echo json_encode(['ok' => true, 'data' => compact('labels','acVoltage','ratedPower','batteryVoltage','inverterVoltage','gridVoltage')]);
    exit;
}
