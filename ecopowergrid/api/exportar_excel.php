<?php
/**
 * api/exportar_excel.php - Formato XML compatible con Excel (.xlsx)
 */

// Limpiar salida
if (ob_get_length()) ob_end_clean();

require_once __DIR__ . '/config.php'; 

if (!isset($pdo)) die("Error de conexión.");

try {
    $sql = "SELECT ts_utc, voltaje_bateria_v, voltaje_salida_ac_v, potencia_salida_ac_w, voltaje_pv_v, corriente_pv_a 
            FROM mediciones ORDER BY ts_utc DESC LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) die("Sin datos.");

    // Cabeceras para engañar al navegador y que crea que es un .xls real
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="Reporte_Movimientos.xls"');
    header('Cache-Control: max-age=0');

    // Estructura XML mínima para Excel
    echo "<?xml version=\"1.0\"?>\n";
    echo "<?mso-application progid=\"Excel.Sheet\"?>\n";
    echo "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\"\n";
    echo " xmlns:o=\"urn:schemas-microsoft-com:office:office\"\n";
    echo " xmlns:x=\"urn:schemas-microsoft-com:office:excel\"\n";
    echo " xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\">\n";
    echo " <Worksheet ss:Name=\"Ultimos 10\">\n";
    echo "  <Table>\n";

    // Encabezados
    echo "   <Row>\n";
    foreach (array_keys($rows[0]) as $col) {
        echo "    <Cell><Data ss:Type=\"String\">" . htmlspecialchars($col) . "</Data></Cell>\n";
    }
    echo "   </Row>\n";

    // Datos
    foreach ($rows as $row) {
        echo "   <Row>\n";
        foreach ($row as $val) {
            $type = is_numeric($val) ? 'Number' : 'String';
            echo "    <Cell><Data ss:Type=\"$type\">" . htmlspecialchars($val) . "</Data></Cell>\n";
        }
        echo "   </Row>\n";
    }

    echo "  </Table>\n";
    echo " </Worksheet>\n";
    echo "</Workbook>";
    exit;

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}