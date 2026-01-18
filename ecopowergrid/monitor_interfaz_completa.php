<?php
/*******************************************************
 * monitor_interfaz_completa.php
 * Interfaz + API para EcoPowerGrid (UI + endpoints AJAX)
 * - Lee CSV local
 * - Inserta/Sincroniza a MySQL vía PDO
 * - Devuelve JSON para UI (último registro y series)
 *******************************************************/

/////////////////////////////////////////////////////////
// CONFIGURACIÓN
/////////////////////////////////////////////////////////
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);

// Ruta del CSV (ajústala a tu estructura)
define('CSV_PATH', __DIR__ . '/base.csv');

if (!file_exists(CSV_PATH)) {
    die('NO EXISTE: ' . CSV_PATH);
}

if (!is_readable(CSV_PATH)) {
    die('NO ES LEGIBLE: ' . CSV_PATH);
}



// Config DB (ajusta credenciales)
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'ecopowergrid_monitoreo');
define('DB_USER', 'root');
define('DB_PASS', 'root');
define('DB_CHARSET', 'utf8mb4');

// Nombre de tabla
define('DB_TABLE', 'mediciones');

/////////////////////////////////////////////////////////
// FUNCIONES BACKEND (CSV / DB / UTIL)
/////////////////////////////////////////////////////////

function send_json($arr, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function normalize_key($str) {

 $str = str_replace(
        [' ', '(', ')', '%', '/', '-'],
        '_',
        $str
    );

    $str = strtolower(trim($str));

    // quitar acentos
    $str = strtr($str, [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n'
    ]);

    // reemplazar TODO lo que no sea letra o número por _
    $str = preg_replace('/[^a-z0-9]+/', '_', $str);
 $str = preg_replace('/_+/', '_', $str);
    // quitar _ al inicio y final
    $str = trim($str, '_');

    return trim($str, '_');
}


/**
 * Detecta delimitador de CSV entre coma/semicolon.
 */
function detect_delimiter($line) {
    return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
}

/**
 * Lee CSV y devuelve:
 */

function read_csv_all($path = CSV_PATH) {
    if (!file_exists($path)) return ['ok' => false, 'rows' => []];
    $handle = fopen($path, 'r'); // Se usa $handle consistentemente 
    $headerRaw = fgetcsv($handle);
    if (!$headerRaw) { fclose($handle); return ['ok' => false, 'rows' => []]; }
    $headers = array_map('normalize_key', $headerRaw);
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        if (count($headers) == count($row)) $rows[] = array_combine($headers, $row);
    }
    fclose($handle);
    return ['ok' => true, 'rows' => $rows];
}

function num($v) {
    if ($v === null || $v === '') return 0;
    $v = str_replace(',', '.', $v); 
    $v = preg_replace('/[^0-9.]/', '', $v); 
    return is_numeric($v) ? (float)$v : 0;
}

/**
 * Devuelve el último registro significativo del CSV
 */
function get_last() {
    $all = read_csv_all();
    if (!$all['ok'] || empty($all['rows'])) {
        return ['ok' => true, 'empty' => true, 'row' => null];
    }
    return ['ok' => true, 'empty' => false, 'row' => end($all['rows'])];
}
 
function pdo_conn() {
    $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET;
    $opt = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ];
    return new PDO($dsn, DB_USER, DB_PASS, $opt);
}

function ensure_table(PDO $pdo) {
    $sql = "
        CREATE TABLE IF NOT EXISTS `".DB_TABLE."` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `fecha` DATETIME NOT NULL,
            `voltaje_ac` DOUBLE NULL,
            `potencia_nominal` DOUBLE NULL,
            `voltaje_bateria` DOUBLE NULL,
            `voltaje_inversor` DOUBLE NULL,
            `voltaje_red` DOUBLE NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_fecha` (`fecha`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($sql);
}

/**
 * Inserta una fila (si no existe por fecha). Devuelve 1 insertado, 0 duplicado
 */
function insert_row(PDO $pdo, $r) {
    // 1. Procesar fecha del CSV (ej: 13/1/2026 12:43:39)
    $rawDate = $r['recordtime'] ?? date('d/m/Y H:i:s');
    $dateObj = DateTime::createFromFormat('j/n/Y H:i:s', $rawDate, new DateTimeZone('America/Guayaquil'));
    
    // 2. Convertir a UTC para MySQL
    $ts_utc = $dateObj ? $dateObj->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');

    // 3. SQL con nombres de columna correctos (ts_utc)
    $sql = "INSERT INTO `".DB_TABLE."`
        (dispositivo_id, ts_utc, voltaje_bateria_v, voltaje_salida_ac_v, voltaje_entrada_ac_v, potencia_salida_ac_w)
        VALUES (:disp, :ts, :vbatt, :vvac, :vgrid, :pout)
        ON DUPLICATE KEY UPDATE
          voltaje_bateria_v = VALUES(voltaje_bateria_v),
          potencia_salida_ac_w = VALUES(potencia_salida_ac_w)";

    $st = $pdo->prepare($sql);
    $st->execute([
        ':disp'  => 1,
        ':ts'    => $ts_utc,
        ':vbatt' => num($r['battery_voltage'] ?? 0),
        ':vvac'  => num($r['inverter_voltage'] ?? 0),
        ':vgrid' => num($r['grid_voltage'] ?? 0),
        ':pout'  => num($r['pload'] ?? 0)
    ]);
    return $st->rowCount() >= 1 ? 1 : 0;
}
/* =====================================================
   API ENDPOINTS
   ===================================================== */
if (isset($_GET['action'])) {

    header('Content-Type: application/json; charset=utf-8');
    $csv = read_csv_all();

    try {

        $action = $_GET['action'];
// Acción para obtener las métricas actuales
        /* ===============================
           DATA LATEST
        =============================== */
      if ($action === 'data-latest') {
    $res = get_last();



    
    if (!$res['ok']) {
        send_json([
            'ok' => true,
            'empty' => $res['empty'] ?? false,
            'row' => $res['row'] ?? null,
            'message' => $res['message'] ?? 'Error leyendo CSV'
        ]);
    }
    
    $is_empty = isset($res['empty']) ? $res['empty'] : false;

    send_json([
        'ok' => true,
        'empty' => $is_empty,
        'row' => $res['row'] ?? null
    ]);
}



        /* ===============================
           DATA SERIES (GRÁFICOS)
        =============================== */
if ($action === 'data-series') {
    $csv = read_csv_all();
    if (!$csv['ok'] || empty($csv['rows'])) {
        send_json(['ok' => false, 'error' => 'CSV vacío']);
    }

    $from = $_GET['from'] ?? null; // Viene del input date (YYYY-MM-DD)
    $to = $_GET['to'] ?? null;
    $filtered = [];

    foreach ($csv['rows'] as $r) {
        // El CSV tiene formato "13/1/2026 18:59:03", extraemos la fecha antes del espacio
       $rawDatePart = explode(' ', $r['recordtime'])[0]; 
                $d = DateTime::createFromFormat('j/n/Y', $rawDatePart);
        
        if ($d) {
            $currentDate = $d->format('Y-m-d');
            // Validar si la fecha está dentro del rango solicitado
            if ($from && $to) {
                if ($currentDate >= $from && $currentDate <= $to) {
                    $filtered[] = $r;
                }
            } else {
                // Si no hay filtro, mostrar los últimos 50 registros por defecto
                $filtered[] = $r;
            }
        }
    }

    // Si no hay filtro y el archivo es grande, limitar para optimizar carga
    if (!$from && count($filtered) > 50) {
        $filtered = array_slice($filtered, -50);
    }

    send_json([
        'ok' => true,
        'labels' => array_column($filtered, 'recordtime'),
        'series' => [
            'voltaje_ac' => array_map(fn($r) => num($r['ac_voltage_grade']), $filtered),
            'potencia_nominal' => array_map(fn($r) => num($r['rated_power_va']), $filtered),
            'voltaje_bateria' => array_map(fn($r) => num($r['battery_voltage']), $filtered),
            'voltaje_inversor' => array_map(fn($r) => num($r['inverter_voltage']), $filtered),
            'voltaje_red' => array_map(fn($r) => num($r['grid_voltage']), $filtered)
        ]
    ]);
}

        /* ===============================
           SAVE LAST TO DB
        =============================== */
        if ($action === 'save-last') {

            $last = get_last();

            if (!$last['ok']) {
                send_json([
                    'ok' => false,
                    'message' => $last['message'] ?? 'Error CSV'
                ]);
            }

            if (!empty($last['empty'])) {
                send_json([
                    'ok' => false,
                    'empty' => true,
                    'message' => 'CSV vacío, no hay nada que guardar'
                ]);
            }

            $pdo = pdo_conn();
            ensure_table($pdo);
            $inserted = insert_row($pdo, $last['row']);

            send_json([
                'ok' => true,
                'inserted' => $inserted
            ]);
        }

        /* ===============================
           SYNC ALL CSV TO DB
        =============================== */
        if ($action === 'sync-all') {

            $all = read_csv_all();

            if (!$all['ok'] || empty($all['rows'])) {
                send_json([
                    'ok' => false,
                    'message' => 'CSV vacío'
                ]);
            }

            $pdo = pdo_conn();
            ensure_table($pdo);
            $pdo->beginTransaction();

            $inserted = 0;
            $total = 0;

            foreach ($all['rows'] as $r) {
                $total++;
                $inserted += insert_row($pdo, $r) ? 1 : 0;
            }

            $pdo->commit();

            send_json([
                'ok' => true,
                'total' => $total,
                'inserted' => $inserted,
                'duplicates' => $total - $inserted
            ]);
        }

        /* ===============================
           ACCIÓN NO VÁLIDA
        =============================== */
        send_json([
            'ok' => false,
            'message' => 'Acción no válida'
        ], 400);

    } catch (Throwable $e) {

        send_json(['ok' => false, 'message' => $e->getMessage()], 500);
    }

    exit;
}


// Si no hay action, se renderiza la interfaz HTML a continuación.
?>
<!doctype html>
<html lang="es" data-theme="light">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>Monitor Solar PV — EcoPowerGrid</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Chart.js v4 -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

    <!-- Iconos (opcional, para el botón flotante) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            
            --bg-input: #ffffff;
            --card-bg: #ffffff;
            --text: #1e293b;
            --muted: #6b7280;
            --primary: #2563eb;
            --accent: #22c55e;
            --shadow: 0 10px 25px rgba(0,0,0,0.08);
            --chip: #e2e8f0;
            --border: #e5e7eb;
            --bg-body: #f8fafc;
             --bg-card: #ffffff;
         --text-main: #1e293b;
         --border-color: #e2e8f0;
             --grid-color: rgba(0, 0, 0, 0.1);
--bg-main: #f8fafc;
    --bg-card: #ffffff;
    --bg-input: #ffffff;
    --text-color: #1e293b;
    --border-color: #dee2e6;

            /* Chart colors (light) */
            --chart-line: #2563eb;
            --chart-fill: rgba(37,99,235,0.15);
            --grid: rgba(0,0,0,0.08);
        }
        html[data-theme="dark"] {
            --bg: #0b1220;
            --card-bg: #0f172a;
            --text: #e5e7eb;
            --muted: #94a3b8;
            --primary: #60a5fa;
            --accent: #22c55e;
            --shadow: 0 10px 25px rgba(0,0,0,0.55);
            --chip: #1f2937;
            --border: #283244;
--bg-main: #0b1220;
    --bg-card: #161e2d;
    --bg-input: #111827;
    --text-color: #f3f4f6;
    --border-color: #2d3748;

            /* Chart colors (dark) */
            --chart-line: #60a5fa;
            --chart-fill: rgba(96,165,250,0.18);
            --grid: rgba(255,255,255,0.08);
        }
/* Estilos para el panel en Modo Claro */
[data-theme='light'] .chart-card {
    background-color: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05) !important;
}

[data-theme='light'] .chart-card h6 {
    color: #1e293b !important;
}

[data-theme='light'] .badge.bg-dark {
    background-color: #f1f5f9 !important;
    color: #64748b !important;
    border: 1px solid #cbd5e1 !important;
}

/* Contenedor general de la gráfica */
.chart-card {
    border-radius: 16px;
    padding: 24px;
    transition: all 0.3s ease;
}
.text-tema-labels {
    color: var(--text-color) !important;
    opacity: 0.8;
}

.input-tema {
    background-color: var(--bg-input) !important;
    color: var(--text-input) !important;
    border: 1px solid var(--border-input) !important;
    transition: all 0.3s ease;
}
        body {
            background: var(--bg);
            color: var(--text);
            font-smooth: always;
            -webkit-font-smoothing: antialiased;
        }
.panel-filtrado {
    background-color: var(--bg-card) !important;
    border: 1px solid var(--border-color);
    transition: background-color 0.3s ease, border-color 0.3s ease;
}

        .navbar {
            background: linear-gradient(90deg, var(--primary), #7c3aed);
            box-shadow: var(--shadow);
        }
        .navbar .brand-title {
            font-weight: 700;
            letter-spacing: .3px;
        }
        .chip {
            background: var(--chip);
            color: var(--text);
            border-radius: 12px;
            padding: 4px 10px;
            font-size: .875rem;
            border: 1px solid var(--border);
        }
        .card.metric {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: var(--shadow);
            transition: transform .25s ease, box-shadow .25s ease, background .3s ease;
            overflow: hidden;
        }
        .card.metric:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.14);
        }
        .metric-title {
            font-size: .9rem;
            color: var(--muted);
            margin-bottom: .35rem;
        }
        .metric-value {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text);
        }
        .content {
            animation: fadeIn .35s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .btn-theme {
            border: 1px solid rgba(255,255,255,.35);
            color: #fff;
        }
        .btn-group .btn {
            white-space: nowrap;
        }
        .chart-card {
    background: #161e2d;
    border: 1px solid #2d3748;
    border-radius: 16px;
    padding: 25px;
    margin-top: 2rem;
    box-shadow: 0 25px 35px rgba(0, 0, 0, 0.4);
    /* Asegura que el panel crezca con la pantalla */
    width: 100%; 
    transition: all 0.3s ease;
}

#lineChart {
    /* Forzamos un renderizado nítido */
    image-rendering: auto;
}
        .footer-note {
            color: var(--muted);
            font-size: .9rem;
        }
        /* Botón flotante */
        .fab {
            position: fixed;
            right: 24px;
            bottom: 24px;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: var(--primary);
            color: #fff;
            display: grid;
            place-items: center;
            box-shadow: var(--shadow);
            cursor: pointer;
            transition: transform .2s ease, box-shadow .2s ease, background .2s ease;
            z-index: 1050;
        }
        .fab:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 34px rgba(0,0,0,0.22);
            background: #3b82f6;
        }

        /* Barra de progreso simulada */
        .progress-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.35);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }
        .progress-box {
            width: min(520px, 92vw);
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px 16px;
            box-shadow: var(--shadow);
        }
        .progress-title {
            font-weight: 700;
            margin-bottom: 8px;
        }
        .form-select, .form-control, .btn {
            border-radius: 10px;
        }
    .btn-flotante {
    font-family: Arial, sans-serif;
    font-weight: bold;
    color: #ffffff;
    background-color: #1D6F42; /* Excel */
    border-radius: 20px;
    padding: 10px 18px;
    position: fixed;
    bottom: 30px;
    left: 30px;
    z-index: 1000;
    text-decoration: none;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    display: flex;
    align-items: center;
    gap: 10px;
    transition: transform 0.2s, background-color 0.2s;
}

.btn-flotante:hover {
    background-color: #155231;
    transform: scale(1.05);
    color: white;
}
    
    
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container-fluid">
        <span class="navbar-brand brand-title">Monitor Solar PV — EcoPowerGrid</span>
        <div class="ms-auto d-flex gap-2 align-items-center">
            <span id="ultimaAct" class="chip">Última actualización: —</span>
            <div class="btn-group me-2" role="group" aria-label="Acciones CSV">
                <button id="btnRefresh" class="btn btn-light btn-sm">Actualizar CSV</button>
                <button id="btnSaveLast" class="btn btn-light btn-sm">Guardar último en BD</button>
                <button id="btnSyncAll" class="btn btn-light btn-sm">Sincronizar todo CSV</button>
            </div>
            <button id="btnTheme" class="btn btn-sm btn-theme">
                <i class="bi bi-moon-stars me-1"></i>
                <span class="d-none d-sm-inline">Tema</span>
            </button>
        </div>
    </div>
</nav>



    <div class="row g-3">
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card p-3 metric">
                <div class="metric-title">Voltaje AC</div>
                <div class="metric-value"><span id="mVac">—</span> <small class="text-primary">V</small></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card p-3 metric">
                <div class="metric-title">Potencia Nominal</div>
                <div class="metric-value"><span id="mPnom">—</span> <small class="text-primary">VA</small></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
           <div class="card p-3 metric">
                <div class="metric-title">Voltaje Batería</div>
                <div class="metric-value"><span id="mVbatt">—</span> <small class="text-primary">V</small></div>
            </div>
  
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card p-3 metric">
                <div class="metric-title">Voltaje Inversor</div>
                <div class="metric-value"><span id="mVinv">—</span> <small class="text-primary">V</small></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card p-3 metric">
                <div class="metric-title">Voltaje Red</div>
                <div class="metric-value"><span id="mVred">—</span> <small class="text-primary">V</small></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card p-3 metric text-truncate">
                <div class="metric-title">Último Registro</div>
                <div class="metric-value" style="font-size: 1rem;"><span id="mFecha">—</span></div>
            </div>
        </div>
    </div>

 <main class="container-fluid my-4 content px-4">
<div class="row mb-4 g-3 card-m mx-0 p-3 shadow-sm" style="background-color: var(--bg-card); border-radius: 12px;">
        <div class="col-md-4">
        <label class="small fw-bold text-secondary">DESDE</label>
        <input type="date" id="dateFrom" class="form-control input-tema">
    </div>
    <div class="col-md-4">
        <label class="small fw-bold text-secondary">HASTA</label>
        <input type="date" id="dateTo" class="form-control input-tema">
    </div>
    <div class="col-md-4 d-flex align-items-end">
        <button onclick="refreshData()" class="btn btn-primary w-100 fw-bold">
            FILTRAR DATOS
        </button>
    </div>
</div>
<div class="chart-card panel-filtrado shadow-sm p-4 mx-auto" style="border-radius: 12px; width: 100%;">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h6 class="m-0 fw-bold label-tema">
                <i class="bi bi-activity me-2 text-primary"></i>Histórico Multiserie 
            </h6>
        </div>
        
        <div style="height: 700px; width: 100%; position: relative;">
            <canvas id="lineChart"></canvas>
        </div>
    </div>
</main>

</main>
    <p class="mt-3 footer-note text-center">Datos sincronizados desde el archivo CSV local y base de datos MySQL.</p>
</main>


<!-- Botón flotante (Guardar último en BD) -->
<button class="fab" id="fabSave" title="Guardar último en BD">
    <i class="bi bi-save-fill fs-4"></i>
</button>

<!-- Overlay de progreso -->
<div class="progress-overlay" id="progressOverlay">
    <div class="progress-box">
        <div class="progress-title">Sincronizando CSV a Base de Datos…</div>
        <div class="progress mb-2" role="progressbar" aria-label="Sincronización" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar progress-bar-striped progress-bar-animated" id="progressBar" style="width: 2%">2%</div>
        </div>
        <div class="d-flex justify-content-between small">
            <span id="progressDetail" class="text-secondary">Preparando…</span>
            <span id="progressPct" class="text-secondary">2%</span>
        </div>
    </div>
</div>

<script>
/* =============================
   Tema (Dark/Light) persistente
   ============================= */
(function themeInit(){
    const saved = localStorage.getItem('ecopower_theme');
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    const start = saved || (prefersDark ? 'dark' : 'light');
    document.documentElement.setAttribute('data-theme', start);
})();
document.getElementById('btnTheme').addEventListener('click', () => {
    const html = document.documentElement;
    const cur  = html.getAttribute('data-theme') || 'light';
    const next = (cur === 'light') ? 'dark' : 'light';
    html.setAttribute('data-theme', next);
    localStorage.setItem('ecopower_theme', next);
    // Sincronizar colores del gráfico con el tema
    refreshChartTheme();
});

/* =============================
   Utilidades UI
   ============================= */
function toastSuccess(title, text='') {
    Swal.fire({
        icon: 'success',
        title: title,
        text: text,
        timer: 1500,
        showConfirmButton: false
    });
}
function toastError(title, text='') {
    Swal.fire({
        icon: 'error',
        title: title,
        text: text
    });
}
function toastInfo(title, text='') {
    Swal.fire({
        icon: 'info',
        title: title,
        text: text
    });
}
function setLastUpdateNow() {
    const now = new Date();
    const s = now.toLocaleString();
    document.getElementById('ultimaAct').textContent = 'Última actualización: ' + s;
}

/* =============================
   Lectura de CSV: Último registro
   ============================= */
async function fetchLatest() {
    try {
        const res = await fetch('?action=data-latest');
        const js = await res.json();
        if (!js.ok) {
            toastError('Error leyendo CSV', js.message || '');
            return false;
        }
        if (js.empty) {
            // Validación CSV vacío
            toastInfo('CSV vacío', 'No hay datos disponibles para mostrar.');
            setMetrics(null);
            return false;
        }
        setMetrics(js.row);
        setLastUpdateNow();
        return true;
    } catch (e) {
        toastError('Error de red', e.message);
        return false;
    }
}


function fmtNum(v, decimals=2) {
    if (v === null || v === undefined || v === '' || isNaN(v)) return '—';
    const n = Number(v);
    return Intl.NumberFormat('es-EC', { maximumFractionDigits: decimals }).format(n);
}

function setMetrics(row) {
    const $ = (id) => document.getElementById(id);
    if (!row) return;

    // Usamos las claves normalizadas que vienen del backend
    $('mVac').textContent   = fmtNum(row.ac_voltage_grade);
    $('mPnom').textContent  = fmtNum(row.rated_power_va);
    $('mVbatt').textContent = fmtNum(row.battery_voltage);
    $('mVinv').textContent  = fmtNum(row.inverter_voltage);
    $('mVred').textContent  = fmtNum(row.grid_voltage);
    $('mFecha').textContent = row.recordtime || '—';
}

/* =============================
   Series para Chart.js
   ============================= */
let chartInstance = null;
let lastSeriesPayload = null;

async function fetchSeries() {
    try {
        const res = await fetch('?action=data-series');
        const js = await res.json();


        
        if (!js.ok) {
            toastError('Error leyendo series', js.message || '');
            return null;
        }
        if (js.empty) {
            toastInfo('CSV vacío', 'No hay datos para graficar.');
            return {labels:[], series:{}};
        }
        lastSeriesPayload = js;
        return js;
    } catch (e) {
        toastError('Error de red', e.message);
        return null;
    }
}

function getThemeColors() {
    const styles = getComputedStyle(document.documentElement);
    return {
        line: styles.getPropertyValue('--chart-line').trim() || '#2563eb',
        fill: styles.getPropertyValue('--chart-fill').trim() || 'rgba(37,99,235,0.15)',
        grid: styles.getPropertyValue('--grid').trim() || 'rgba(0,0,0,0.08)',
        text: styles.getPropertyValue('--text').trim() || '#1f2937',
        cardBg: styles.getPropertyValue('--card-bg').trim() || '#ffffff'
    };
}

function buildChart(labels, values, labelText) {
    const ctx = document.getElementById('lineChart').getContext('2d');
    const c = getThemeColors();
if (!labels.length || !values.length) {
    console.warn('No hay datos para graficar');
    return;
}

    if (chartInstance) {
        chartInstance.destroy();
    }
    chartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: labelText,
                data: values,
                borderColor: c.line,
                backgroundColor: c.fill,
                tension: 0.25,
                borderWidth: 2,
                pointRadius: 0,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: {
                    ticks: { color: c.text },
                    grid: { color: c.grid, drawOnChartArea: false }
                },
                y: {
                    ticks: { color: c.text },
                    grid: { color: c.grid }
                }
            },
            plugins: {
                legend: { labels: { color: c.text } },
                tooltip: {
                    callbacks: {
                        // Muestra valores con formateo
                        label: (ctx) => `${labelText}: ${fmtNum(ctx.parsed.y)}`
                    }
                }
            }
        }
    });
}

function refreshChartTheme() {
    if (!lastSeriesPayload) return;

    const labels = lastSeriesPayload.labels.map(l => l.split(' ')[1]); // Solo la hora para limpieza visual
    const data = lastSeriesPayload.series;

    const datasets = [
        {
            label: 'Voltaje AC (V)',
            data: data.voltaje_ac,
            borderColor: '#ff6384', // Rojo
            borderDash: [], // Línea sólida
            tension: 0.3,
            yAxisID: 'y'
        },
        {
            label: 'Potencia (VA)',
            data: data.potencia_nominal,
            borderColor: '#36a2eb', // Azul
            borderDash: [5, 5], // Línea punteada
            tension: 0.3,
            yAxisID: 'y1' // Eje secundario para potencias grandes
        },
        {
            label: 'Voltaje Batería (V)',
            data: data.voltaje_bateria,
            borderColor: '#4bc0c0', // Turquesa
            borderDash: [2, 2], // Puntos
            tension: 0.3,
            yAxisID: 'y'
        },
        {
            label: 'Voltaje Inversor (V)',
            data: data.voltaje_inversor,
            borderColor: '#ff9f40', // Naranja
            borderWidth: 3,
            tension: 0.3,
            yAxisID: 'y'
        }
    ];

    renderMultiChart(labels, datasets);
}

function renderMultiChart(labels, datasets) {
    const ctx = document.getElementById('lineChart').getContext('2d');
    if (chartInstance) chartInstance.destroy();

    chartInstance = new Chart(ctx, {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive: true,
            scales: {
                y: { 
                    type: 'linear', 
                    display: true, 
                    position: 'left',
                    title: { display: true, text: 'Voltaje (V)' }
                },
                y1: { 
                    type: 'linear', 
                    display: true, 
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    title: { display: true, text: 'Potencia (VA)' }
                }
            }
        }
    });
}

/* =============================
   Acciones BD
   ============================= */
async function saveLastToDB() {
    try {
        const res = await fetch('?action=save-last');
        const js = await res.json();
        if (!js.ok) {
            if (js.empty) {
                toastInfo('CSV vacío', js.message || 'No hay datos para guardar.');
            } else {
                toastError('No se pudo guardar', js.message || '');
            }
            return;
        }
        toastSuccess('Registro guardado', `Insertados: ${js.inserted || 0}`);
    } catch (e) {
        toastError('Error de red', e.message);
    }
}

let progressTimer = null;
function showProgress() {
    const overlay = document.getElementById('progressOverlay');
    overlay.style.display = 'flex';
    const bar  = document.getElementById('progressBar');
    const pctT = document.getElementById('progressPct');
    const det  = document.getElementById('progressDetail');
    let pct = 2;

    // Simulación suave mientras corre la petición real
    progressTimer = setInterval(() => {
        // acercamiento asintótico a 90%
        pct = Math.min(90, pct + Math.max(1, (90 - pct) * 0.07));
        bar.style.width = `${pct}%`;
        bar.textContent = `${Math.round(pct)}%`;
        pctT.textContent = `${Math.round(pct)}%`;
        det.textContent = 'Sincronizando…';
    }, 160);
}
function hideProgress(finalText='Completado') {
    const overlay = document.getElementById('progressOverlay');
    const bar  = document.getElementById('progressBar');
    const pctT = document.getElementById('progressPct');
    const det  = document.getElementById('progressDetail');
    if (progressTimer) clearInterval(progressTimer);
    bar.style.width = '100%';
    bar.textContent = '100%';
    pctT.textContent = '100%';
    det.textContent = finalText;
    setTimeout(()=> { overlay.style.display = 'none'; }, 420);
}

async function syncAllToDB() {
    showProgress();
    try {
        const res = await fetch('?action=sync-all');
        const js = await res.json();
        if (!js.ok) {
            hideProgress('Falló la sincronización');
            if (js.empty) {
                toastInfo('CSV vacío', js.message || 'No hay datos para sincronizar.');
            } else {
                toastError('Error al sincronizar', js.message || '');
            }
            return;
        }
        hideProgress('Datos sincronizados');
        Swal.fire({
            icon: 'success',
            title: 'Sincronización completa',
            html: `
                <div class="text-start">
                  <div><strong>Total leídos:</strong> ${js.total}</div>
                  <div><strong>Insertados/actualizados:</strong> ${js.inserted}</div>
                  <div><strong>Duplicados omitidos:</strong> ${js.duplicates}</div>
                </div>
            `,
            confirmButtonText: 'OK'
        });
    } catch (e) {
        hideProgress('Error de red');
        toastError('Error de red', e.message);
    }
}

/* =============================
   Eventos UI
   ============================= */
document.getElementById('btnRefresh').addEventListener('click', async () => {
    const ok = await fetchLatest();
    if (ok) {
        const js = await fetchSeries();
        if (js) refreshChartTheme();
    }
});
document.getElementById('btnSaveLast').addEventListener('click', saveLastToDB);
document.getElementById('fabSave').addEventListener('click', saveLastToDB);
document.getElementById('btnSyncAll').addEventListener('click', syncAllToDB);
document.getElementById('serieSelect').addEventListener('change', refreshChartTheme);

/* =============================
   Auto-init y auto-refresh
   ============================= */
(async function init(){
    const ok = await fetchLatest();
    const js  = await fetchSeries();
    if (js) refreshChartTheme();

    // Auto-actualización cada 10s si hay datos
    setInterval(async () => {
        const has = await fetchLatest();
        if (has) {
            const payload = await fetchSeries();
            if (payload) refreshChartTheme();
        }
    }, 10000);
})();

async function refreshData() {
    try {
        // Capturar los valores de los inputs de fecha (IDs: dateFrom y dateTo)
        const from = document.getElementById('dateFrom').value;
        const to = document.getElementById('dateTo').value;

        // 1. Actualizar widgets superiores (Tarjetas de Voltaje, Potencia, etc.)
        const r1 = await fetch('?action=data-latest');
        const d1 = await r1.json();
        // Nota: Asegúrate de que la función setMetrics exista para actualizar los widgets
        if(d1.ok && d1.row) setMetrics(d1.row); 

        // 2. Actualizar Gráfico con Filtros enviados por URL
        const r2 = await fetch(`?action=data-series&from=${from}&to=${to}`);
        const d2 = await r2.json();
        
        // Nota: Esta función es la que dibuja el gráfico multiserie
        if(d2.ok) renderMultiChart(d2); 
        
    } catch(e) {
        console.error("Error al filtrar datos:", e);
    }
}

// --- IMPORTANTE: Llama a la función para que cargue datos al abrir la página ---
refreshData();

// Opcional: Auto-refresco cada 30 segundos
setInterval(refreshData, 30000);


</script>

<a href="api/exportar_excel.php" class="btn-flotante">
    <i class="bi bi-file-earmark-spreadsheet-fill"></i>
    <span>Reporte</span>
</a>

</body>
</html>