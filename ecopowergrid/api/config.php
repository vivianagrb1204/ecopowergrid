<?php
// api/config.php
$DB_HOST = '127.0.0.1';
$DB_PORT = '3306';
$DB_NAME = 'ecopowergrid_monitoreo';
$DB_USER = 'root';
$DB_PASS = 'root';

$dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Error de conexión: ' . $e->getMessage()]);
    exit;
}
