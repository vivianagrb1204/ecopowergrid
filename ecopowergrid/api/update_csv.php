<?php
header('Content-Type: application/json; charset=utf-8');

$csv = __DIR__ . '/base.csv';
if (!file_exists($csv)) {
    echo json_encode(['ok' => false, 'message' => 'Archivo base.csv no encontrado']);
    exit;
}

// Simulación: agregar una línea con valores aleatorios
$handle = fopen($csv, 'a');
$fecha = date('d/m/Y H:i:s');
$nuevaFila = [120, 10000, '54,5', 238, 234, $fecha]; // Ajusta según encabezados reales
fputcsv($handle, $nuevaFila);
fclose($handle);

echo json_encode(['ok' => true, 'message' => 'Archivo CSV actualizado correctamente']);
