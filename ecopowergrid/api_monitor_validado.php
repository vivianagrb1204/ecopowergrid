<?php
/*******************************************************
 * monitor_interfaz_completa.php - VERSIÓN AMPLIADA
 *******************************************************/

// 1. CONFIGURACIÓN
define('CSV_PATH', __DIR__ . '/base.csv');
require_once __DIR__ . '/config.php'; // Conexión DB

if (!file_exists(CSV_PATH)) { die('ERROR: No se encontró base.csv'); }

/////////////////////////////////////////////////////////
// FUNCIONES BACKEND
/////////////////////////////////////////////////////////

function clean_num($v) {
    if ($v === null || $v === '') return 0;
    $v = str_replace(',', '.', trim($v));
    // Quitar unidades si existen (ej: "KWH", "V")
    $v = preg_replace('/[^0-9.]/', '', $v);
    return is_numeric($v) ? (float)$v : 0;
}

function read_csv_all() {
    $rows = [];
    if (($h = fopen(CSV_PATH, "r")) !== FALSE) {
        $headers = fgetcsv($h); 
        while (($data = fgetcsv($h)) !== FALSE) {
            if (count($data) == count($headers)) {
                $rows[] = array_combine($headers, $data);
            }
        }
        fclose($h);
    }
    return $rows;
}

// --- API ACTIONS ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $rows = read_csv_all();

    if ($_GET['action'] === 'latest') {
        $last = end($rows);
        echo json_encode([
            'ok' => true,
            'row' => [
                'p_compra'   => clean_num($last['Rated power(VA)'] ?? 0),
                'v_batt'     => clean_num($last['Battery voltage'] ?? 0),
                'i_grid'     => clean_num($last['Grid current'] ?? 0),
                'load_pct'   => clean_num($last['Load percent'] ?? 0),
                'p_batt'     => clean_num($last['Batt power'] ?? 0),
                'i_batt'     => clean_num($last['Batt current'] ?? 0),
                'fecha'      => $last['RecordTime'] ?? ''
            ]
        ]);
        exit;
    }

    if ($_GET['action'] === 'series') {
        $from = $_GET['from'] ?? null;
        $to = $_GET['to'] ?? null;
        $filtered = [];

        foreach ($rows as $r) {
            $rawDate = explode(' ', $r['RecordTime'])[0];
            $d = DateTime::createFromFormat('j/n/Y', $rawDate);
            if (!$d) $d = DateTime::createFromFormat('d/m/Y', $rawDate);
            
            if ($d) {
                $curr = $d->format('Y-m-d');
                if ($from && $to) {
                    if ($curr >= $from && $curr <= $to) $filtered[] = $r;
                } else { $filtered[] = $r; }
            }
        }
        if (!$from && !$to) $filtered = array_slice($rows, -40);

        echo json_encode([
            'ok' => true,
            'labels'   => array_column($filtered, 'RecordTime'),
            'p_compra' => array_map(fn($r) => clean_num($r['Rated power(VA)']), $filtered),
            'v_batt'   => array_map(fn($r) => clean_num($r['Battery voltage']), $filtered),
            'p_batt'   => array_map(fn($r) => clean_num($r['Batt power']), $filtered)
        ]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <title>EcoPowerGrid Pro Monitor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        :root { --bg: #f4f7f6; --card: #ffffff; --text: #1a202c; --border: #e2e8f0; --accent: #2563eb; }
        [data-theme="dark"] { --bg: #0b1220; --card: #161e2d; --text: #f3f4f6; --border: #2d3748; --accent: #38bdf8; }
        body { background: var(--bg); color: var(--text); transition: 0.3s; font-family: sans-serif; }
        .card-m { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 1.2rem; height: 100%; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .val { font-size: 1.8rem; font-weight: 800; color: var(--accent); }
        .unit { font-size: 0.9rem; color: #718096; margin-left: 3px; }
        .navbar { background: var(--card); border-bottom: 1px solid var(--border); }
    </style>
</head>
<body>

<nav class="navbar mb-4 p-3 shadow-sm">
    <div class="container d-flex justify-content-between align-items-center">
        <span class="fw-bold fs-4 text-uppercase"><i class="bi bi-lightning-fill text-warning"></i> ECOPOWERGRID PRO</span>
        <button id="themeToggle" class="btn btn-outline-secondary"><i class="bi bi-sun-fill" id="themeIcon"></i></button>
    </div>
</nav>

<div class="container pb-5">
    <div class="row mb-4 g-2 p-3 rounded-3 shadow-sm card-m">
        <div class="col-md-4">
            <label class="small fw-bold text-secondary">DESDE</label>
            <input type="date" id="dateFrom" class="form-control">
        </div>
        <div class="col-md-4">
            <label class="small fw-bold text-secondary">HASTA</label>
            <input type="date" id="dateTo" class="form-control">
        </div>
        <div class="col-md-4 d-flex align-items-end">
            <button onclick="refreshUI()" class="btn btn-primary w-100 fw-bold">FILTRAR DATOS</button>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4 col-lg-2">
            <div class="card-m text-center">
                <small class="text-secondary fw-bold">POTENCIA COMPRA</small>
                <div class="val" id="disp_compra">--</div><span class="unit">VA</span>
            </div>
        </div>
        <div class="col-md-4 col-lg-2">
            <div class="card-m text-center">
                <small class="text-secondary fw-bold">VOLTAJE BATT</small>
                <div class="val" id="disp_v_batt">--</div><span class="unit">V</span>
            </div>
        </div>
        <div class="col-md-4 col-lg-2">
            <div class="card-m text-center">
                <small class="text-secondary fw-bold">CORRIENTE RED</small>
                <div class="val text-info" id="disp_i_grid">--</div><span class="unit">A</span>
            </div>
        </div>
        <div class="col-md-4 col-lg-2">
            <div class="card-m text-center">
                <small class="text-secondary fw-bold">% CARGA</small>
                <div class="val text-warning" id="disp_load_pct">--</div><span class="unit">%</span>
            </div>
        </div>
        <div class="col-md-4 col-lg-2">
            <div class="card-m text-center">
                <small class="text-secondary fw-bold">POTENCIA BATT</small>
                <div class="val text-success" id="disp_p_batt">--</div><span class="unit">W</span>
            </div>
        </div>
        <div class="col-md-4 col-lg-2">
            <div class="card-m text-center text-truncate">
                <small class="text-secondary fw-bold">CORRIENTE BATT</small>
                <div class="val text-danger" id="disp_i_batt">--</div><span class="unit">A</span>
            </div>
        </div>
    </div>

    

    <div class="card-m">
        <h6 class="mb-4 fw-bold"><i class="bi bi-activity me-2"></i>Comparativa: Compra vs Batería</h6>
        <div style="height: 450px;"><canvas id="dualChart"></canvas></div>
    </div>
</div>

<script>
let mainChart = null;
const html = document.documentElement;

// --- TEMA ---
document.getElementById('themeToggle').onclick = () => {
    const isDark = html.getAttribute('data-theme') === 'dark';
    const next = isDark ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    document.getElementById('themeIcon').className = isDark ? 'bi bi-moon-stars-fill' : 'bi bi-sun-fill';
    localStorage.setItem('theme-pref', next);
    refreshUI();
};

if(localStorage.getItem('theme-pref') === 'light') {
    html.setAttribute('data-theme', 'light');
    document.getElementById('themeIcon').className = 'bi bi-moon-stars-fill';
}

// --- DATOS ---
async function refreshUI() {
    try {
        const r1 = await fetch('?action=latest');
        const d1 = await r1.json();
        if(d1.ok) {
            document.getElementById('disp_compra').innerText = d1.row.p_compra;
            document.getElementById('disp_v_batt').innerText = d1.row.v_batt;
            document.getElementById('disp_i_grid').innerText = d1.row.i_grid;
            document.getElementById('disp_load_pct').innerText = d1.row.load_pct;
            document.getElementById('disp_p_batt').innerText = d1.row.p_batt;
            document.getElementById('disp_i_batt').innerText = d1.row.i_batt;
        }

        const from = document.getElementById('dateFrom').value;
        const to = document.getElementById('dateTo').value;
        const r2 = await fetch(`?action=series${from?'&from='+from:''}${to?'&to='+to:''}`);
        const d2 = await r2.json();
        if(d2.ok) render(d2);
    } catch(e) { console.error("Error cargando datos"); }
}

function render(data) {
    const ctx = document.getElementById('dualChart').getContext('2d');
    const isDark = html.getAttribute('data-theme') === 'dark';
    if(mainChart) mainChart.destroy();
    
    mainChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.labels.map(l => l.split(' ')[1]),
            datasets: [
                {
                    label: 'Potencia Compra (VA)',
                    data: data.p_compra,
                    borderColor: '#f39c12',
                    yAxisID: 'yP', tension: 0.4, pointRadius: 0
                },
                {
                    label: 'Potencia Batería (W)',
                    data: data.p_batt,
                    borderColor: '#2ecc71',
                    yAxisID: 'yP', tension: 0.4, pointRadius: 0
                },
                {
                    label: 'Voltaje Batería (V)',
                    data: data.v_batt,
                    borderColor: isDark ? '#38bdf8' : '#0d6efd',
                    yAxisID: 'yV', tension: 0.4, pointRadius: 0
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: {
                yP: { position: 'right', title: {display:true, text:'Potencia (W/VA)'}, grid:{drawOnChartArea:false} },
                yV: { position: 'left', title: {display:true, text:'Voltaje (V)'}, grid:{color: isDark ? '#2d3748' : '#e2e8f0'} }
            }
        }
    });
}

setInterval(refreshUI, 15000);
refreshUI();
</script>
</body>
</html>