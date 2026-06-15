<?php
session_start();

// Configure Content Security Policy
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://code.jquery.com https://cdn.jsdelivr.net https://unpkg.com 'unsafe-inline' 'unsafe-eval'; style-src 'self' https://cdn.jsdelivr.net https://fonts.googleapis.com https://unpkg.com 'unsafe-inline'; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https://unpkg.com https://*.tile.openstreetmap.org;");

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Load Datasets
function load_datasets() {
    $poi_file = __DIR__ . '/dataset/poi.csv';
    $crowd_file = __DIR__ . '/dataset/crowd_score.csv';
    $failed_file = __DIR__ . '/dataset/failed_poi.csv';

    $pois = [];
    $failed_pois = [];
    $crowd_scores = [];

    // 1. Parse POI dataset
    if (file_exists($poi_file) && ($handle = fopen($poi_file, "r")) !== FALSE) {
        $headers = fgetcsv($handle, 1000, ",");
        $headers = array_map(function($h) {
            return trim(preg_replace('/^\x{EF}\x{BB}\x{BF}/', '', $h));
        }, $headers);
        $header_map = array_flip($headers);
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if (count($data) < count($headers)) continue;
            $poi_id = $data[$header_map['poi_id']];
            $pois[$poi_id] = [
                'id' => $poi_id,
                'input_name' => $data[$header_map['input_name']],
                'matched_name' => $data[$header_map['matched_name']],
                'matched_address' => $data[$header_map['matched_address']],
                'category' => $data[$header_map['category']],
                'venue_type' => $data[$header_map['venue_type']],
                'latitude' => (float)$data[$header_map['latitude']],
                'longitude' => (float)$data[$header_map['longitude']],
                'duration_min' => (int)$data[$header_map['duration_min']],
                'dwell_time_avg' => (int)$data[$header_map['dwell_time_avg']],
                'rating' => (float)$data[$header_map['rating']],
                'reviews' => (int)$data[$header_map['reviews']]
            ];
        }
        fclose($handle);
    }

    // 2. Parse Failed POIs dataset
    if (file_exists($failed_file) && ($handle = fopen($failed_file, "r")) !== FALSE) {
        $headers = fgetcsv($handle, 1000, ",");
        $headers = array_map(function($h) {
            return trim(preg_replace('/^\x{EF}\x{BB}\x{BF}/', '', $h));
        }, $headers);
        $header_map_failed = array_flip($headers);
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if (count($data) < count($headers)) continue;
            $failed_pois[] = [
                'id' => $data[$header_map_failed['poi_id']],
                'name' => $data[$header_map_failed['poi_name']],
                'message' => $data[$header_map_failed['message']]
            ];
        }
        fclose($handle);
    }

    // 3. Parse Crowd Scores dataset
    if (file_exists($crowd_file) && ($handle = fopen($crowd_file, "r")) !== FALSE) {
        $headers = fgetcsv($handle, 1000, ",");
        $headers = array_map(function($h) {
            return trim(preg_replace('/^\x{EF}\x{BB}\x{BF}/', '', $h));
        }, $headers);
        $header_map_crowd = array_flip($headers);
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if (count($data) < count($headers)) continue;
            $poi_id = $data[$header_map_crowd['poi_id']];
            $day_int = (int)$data[$header_map_crowd['day_int']];
            $hour = (int)$data[$header_map_crowd['hour']];
            $score = (float)$data[$header_map_crowd['crowd_score']];
            
            if (!isset($crowd_scores[$poi_id])) {
                $crowd_scores[$poi_id] = [];
            }
            if (!isset($crowd_scores[$poi_id][$day_int])) {
                $crowd_scores[$poi_id][$day_int] = [];
            }
            $crowd_scores[$poi_id][$day_int][$hour] = $score;
        }
        fclose($handle);
    }

    return [$pois, $failed_pois, $crowd_scores];
}

// Decode Permutation into a Schedule
function decode_schedule($permutation, $days, $start_hour, $end_hour, $pois_db, $crowd_scores, $start_day_int) {
    $schedule = [];
    for ($d = 1; $d <= $days; $d++) {
        $schedule[$d] = [];
    }
    
    $day_cursors = array_fill(1, $days, (float)$start_hour);
    $visited = [];
    $unvisited = [];

    foreach ($permutation as $poi_id) {
        if (!isset($pois_db[$poi_id])) {
            $unvisited[] = $poi_id;
            continue;
        }
        
        $poi = $pois_db[$poi_id];
        $duration_hours = $poi['duration_min'] / 60;
        $scheduled = false;

        // Try to place POI on the earliest day it fits
        for ($d = 1; $d <= $days; $d++) {
            $cursor = $day_cursors[$d];
            $need_travel = ($cursor > $start_hour);
            $travel_time = $need_travel ? 0.5 : 0.0; // 30 mins travel time
            
            $proposed_start = $cursor + $travel_time;
            $proposed_end = $proposed_start + $duration_hours;

            if ($proposed_end <= $end_hour) {
                // Map to day of the week (0 = Monday, 6 = Sunday)
                $day_of_week_int = ($start_day_int + ($d - 1)) % 7;
                
                // Calculate crowd score for this visit (average of hours overlapping)
                $density_sum = 0;
                $density_count = 0;
                $start_h = (int)floor($proposed_start);
                $end_h = (int)ceil($proposed_end) - 1;
                
                for ($h = $start_h; $h <= $end_h; $h++) {
                    $hour_key = ($h >= 0 && $h <= 23) ? $h : 12;
                    $score = isset($crowd_scores[$poi_id][$day_of_week_int][$hour_key]) 
                        ? $crowd_scores[$poi_id][$day_of_week_int][$hour_key] 
                        : 0;
                    $density_sum += $score;
                    $density_count++;
                }
                
                $avg_density = $density_count > 0 ? $density_sum / $density_count : 0;

                $schedule[$d][] = [
                    'poi' => $poi,
                    'start' => $proposed_start,
                    'end' => $proposed_end,
                    'avg_density' => $avg_density,
                    'travel_time_before' => $travel_time * 60
                ];

                $day_cursors[$d] = $proposed_end;
                $visited[] = $poi_id;
                $scheduled = true;
                break;
            }
        }

        if (!$scheduled) {
            $unvisited[] = $poi_id;
        }
    }

    return [
        'schedule' => $schedule,
        'visited' => $visited,
        'unvisited' => $unvisited
    ];
}

// Calculate schedule cost
function evaluate_cost($decoded, $total_selected) {
    $visited_count = count($decoded['visited']);
    $unvisited_count = count($decoded['unvisited']);
    
    $total_density = 0;
    foreach ($decoded['schedule'] as $day_list) {
        foreach ($day_list as $visit) {
            $total_density += $visit['avg_density'];
        }
    }

    $avg_visited_density = $visited_count > 0 ? $total_density / $visited_count : 0;
    
    // Cost formula prioritizing scheduling maximum POIs, then minimizing average density
    $cost = ($unvisited_count * 1000) + $avg_visited_density;
    
    return [
        'cost' => $cost,
        'avg_density' => $avg_visited_density,
        'visited_count' => $visited_count,
        'unvisited_count' => $unvisited_count
    ];
}

// Handle AJAX optimization requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'optimize') {
    header('Content-Type: application/json');
    
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['status' => 'error', 'message' => 'Validasi keamanan CSRF gagal. Silakan muat ulang halaman.']);
        exit;
    }

    // Input parameters parsing
    $selected_pois = isset($_POST['pois']) ? $_POST['pois'] : [];
    $start_day = isset($_POST['start_day']) ? (int)$_POST['start_day'] : 0;
    $days = isset($_POST['days']) ? (int)$_POST['days'] : 1;
    $start_hour = isset($_POST['start_hour']) ? (float)$_POST['start_hour'] : 8.0;
    $end_hour = isset($_POST['end_hour']) ? (float)$_POST['end_hour'] : 18.0;

    // Data validations
    if (empty($selected_pois)) {
        echo json_encode(['status' => 'error', 'message' => 'Pilih minimal satu tempat wisata yang ingin dikunjungi.']);
        exit;
    }
    if ($days < 1 || $days > 7) {
        echo json_encode(['status' => 'error', 'message' => 'Jumlah hari wisata tidak valid (harus 1 - 7 hari).']);
        exit;
    }
    if ($start_hour >= $end_hour) {
        echo json_encode(['status' => 'error', 'message' => 'Jam mulai harus lebih awal daripada jam selesai harian.']);
        exit;
    }

    // Load dataset
    list($pois_db, $failed_pois, $crowd_scores) = load_datasets();

    $start_time_ms = microtime(true) * 1000;

    // 1. Calculate Baseline Schedule (using selected order)
    $baseline_decoded = decode_schedule($selected_pois, $days, $start_hour, $end_hour, $pois_db, $crowd_scores, $start_day);
    $baseline_metrics = evaluate_cost($baseline_decoded, count($selected_pois));

    // 2. Steepest-Ascent Hill Climbing Optimization
    $current_permutation = $selected_pois;
    $current_decoded = $baseline_decoded;
    $current_metrics = $baseline_metrics;
    
    $history = [];
    $history[] = [
        'step' => 0,
        'action' => 'Jadwal Awal Masukan',
        'cost' => $current_metrics['cost'],
        'avg_density' => $current_metrics['avg_density'],
        'visited_count' => $current_metrics['visited_count'],
        'schedule' => $current_decoded['schedule'],
        'visited' => $current_decoded['visited'],
        'unvisited' => $current_decoded['unvisited']
    ];

    $step = 1;
    $max_steps = 100;
    $improved = true;

    while ($improved && $step <= $max_steps) {
        $improved = false;
        $best_neighbor_permutation = null;
        $best_neighbor_decoded = null;
        $best_neighbor_metrics = null;
        $best_neighbor_action = '';
        
        $K = count($current_permutation);
        
        // Scan all possible pairwise swaps (neighbors)
        for ($i = 0; $i < $K - 1; $i++) {
            for ($j = $i + 1; $j < $K; $j++) {
                $neighbor_permutation = $current_permutation;
                
                // Perform swap
                $temp = $neighbor_permutation[$i];
                $neighbor_permutation[$i] = $neighbor_permutation[$j];
                $neighbor_permutation[$j] = $temp;

                $decoded = decode_schedule($neighbor_permutation, $days, $start_hour, $end_hour, $pois_db, $crowd_scores, $start_day);
                $metrics = evaluate_cost($decoded, $K);

                if ($best_neighbor_metrics === null || $metrics['cost'] < $best_neighbor_metrics['cost']) {
                    $best_neighbor_permutation = $neighbor_permutation;
                    $best_neighbor_decoded = $decoded;
                    $best_neighbor_metrics = $metrics;
                    
                    $nameA = isset($pois_db[$current_permutation[$i]]) ? $pois_db[$current_permutation[$i]]['input_name'] : $current_permutation[$i];
                    $nameB = isset($pois_db[$current_permutation[$j]]) ? $pois_db[$current_permutation[$j]]['input_name'] : $current_permutation[$j];
                    $best_neighbor_action = "Tukar urutan '{$nameA}' dengan '{$nameB}'";
                }
            }
        }

        // Move to neighbor if better than current cost
        if ($best_neighbor_metrics !== null && $best_neighbor_metrics['cost'] < $current_metrics['cost']) {
            $current_permutation = $best_neighbor_permutation;
            $current_decoded = $best_neighbor_decoded;
            $current_metrics = $best_neighbor_metrics;
            $improved = true;

            $history[] = [
                'step' => $step,
                'action' => $best_neighbor_action,
                'cost' => $current_metrics['cost'],
                'avg_density' => $current_metrics['avg_density'],
                'visited_count' => $current_metrics['visited_count'],
                'schedule' => $current_decoded['schedule'],
                'visited' => $current_decoded['visited'],
                'unvisited' => $current_decoded['unvisited']
            ];
            $step++;
        }
    }

    $end_time_ms = microtime(true) * 1000;
    $computation_time = round($end_time_ms - $start_time_ms, 2);

    echo json_encode([
        'status' => 'success',
        'data' => [
            'baseline' => [
                'schedule' => $baseline_decoded['schedule'],
                'visited' => $baseline_decoded['visited'],
                'unvisited' => $baseline_decoded['unvisited'],
                'avg_density' => round($baseline_metrics['avg_density'], 1),
                'visited_count' => $baseline_metrics['visited_count']
            ],
            'optimized' => [
                'schedule' => $current_decoded['schedule'],
                'visited' => $current_decoded['visited'],
                'unvisited' => $current_decoded['unvisited'],
                'avg_density' => round($current_metrics['avg_density'], 1),
                'visited_count' => $current_metrics['visited_count']
            ],
            'steps' => $history,
            'time_ms' => $computation_time
        ]
    ]);
    exit;
}

// Load POI datasets for UI render
list($pois_db, $failed_pois, $crowd_scores) = load_datasets();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOURIZME - Crowd Avoidance Scheduler</title>
    <!-- Tailwind CSS v4 Browser CDN -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Leaflet Maps CSS and JS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
        /* Custom scrollbar adjustments for clean flat layout */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
</head>
<body class="bg-neutral-100 text-neutral-800 m-0 p-0 overflow-hidden select-none">

    <div class="flex h-screen w-screen overflow-hidden relative">
        
        <!-- Sidebar Backdrop for Mobile -->
        <div id="sidebar-backdrop" class="fixed inset-0 bg-neutral-900/50 z-40 hidden md:hidden"></div>

        <!-- SIDEBAR PANEL (Configuration) -->
        <div id="sidebar" class="fixed md:static inset-y-0 left-0 z-50 w-80 md:w-96 bg-white border-r border-neutral-200 flex flex-col h-full transform -translate-x-full md:translate-x-0 transition-transform duration-200 ease-in-out flex-shrink-0">
            <!-- Brand Logo & Close Button for Mobile -->
            <div class="p-6 border-b border-neutral-200 flex justify-between items-center">
                <div>
                    <h1 class="text-xl font-bold text-neutral-900 leading-none">TOURIZME</h1>
                    <p class="text-[10px] text-neutral-500 mt-1 uppercase">Hill Climbing Crowd Optimizer</p>
                </div>
                <button type="button" id="btn-close-sidebar" class="md:hidden text-neutral-400 hover:text-neutral-600 focus:outline-none text-xl p-1 font-mono leading-none">
                    &times;
                </button>
            </div>
            
            <!-- Parameters Form -->
            <div class="flex-1 overflow-y-auto p-6 space-y-6">
                
                <!-- Trip Duration and Start Day -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-neutral-500 uppercase mb-2">Hari Mulai</label>
                        <select id="start-day" class="w-full bg-white border border-neutral-300 p-2 text-sm rounded-none focus:outline-none focus:border-[#0f766e]">
                            <option value="0">Senin</option>
                            <option value="1">Selasa</option>
                            <option value="2">Rabu</option>
                            <option value="3">Kamis</option>
                            <option value="4">Jumat</option>
                            <option value="5">Sabtu</option>
                            <option value="6">Minggu</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-neutral-500 uppercase mb-2">Durasi (Hari)</label>
                        <select id="trip-days" class="w-full bg-white border border-neutral-300 p-2 text-sm rounded-none focus:outline-none focus:border-[#0f766e]">
                            <option value="1">1 Hari</option>
                            <option value="2">2 Hari</option>
                            <option value="3">3 Hari</option>
                            <option value="4">4 Hari</option>
                            <option value="5">5 Hari</option>
                            <option value="6">6 Hari</option>
                            <option value="7">7 Hari</option>
                        </select>
                    </div>
                </div>

                <!-- Daily Time Window -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-neutral-500 uppercase mb-2">Jam Mulai</label>
                        <select id="start-hour" class="w-full bg-white border border-neutral-300 p-2 text-sm rounded-none focus:outline-none focus:border-[#0f766e]">
                            <?php for($h=6; $h<=20; $h++): ?>
                                <option value="<?php echo $h; ?>" <?php echo $h===8 ? 'selected' : ''; ?>>
                                    <?php echo sprintf('%02d:00', $h); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-neutral-500 uppercase mb-2">Jam Selesai</label>
                        <select id="end-hour" class="w-full bg-white border border-neutral-300 p-2 text-sm rounded-none focus:outline-none focus:border-[#0f766e]">
                            <?php for($h=8; $h<=23; $h++): ?>
                                <option value="<?php echo $h; ?>" <?php echo $h===18 ? 'selected' : ''; ?>>
                                    <?php echo sprintf('%02d:00', $h); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <!-- POI Selector Checklist -->
                <div>
                    <div class="flex justify-between items-baseline mb-2">
                        <label class="block text-xs font-semibold text-neutral-500 uppercase">Daftar Tempat Wisata</label>
                        <button type="button" id="select-all-pois" class="text-[10px] font-semibold text-[#0f766e] uppercase hover:underline">Pilih Semua</button>
                    </div>
                    <div class="border border-neutral-200 divide-y divide-neutral-100 max-h-[30vh] overflow-y-auto bg-neutral-50">
                        <?php if (empty($pois_db)): ?>
                            <div class="p-4 text-xs text-neutral-400 text-center">Dataset POI tidak ditemukan atau kosong</div>
                        <?php else: ?>
                            <?php foreach ($pois_db as $id => $poi): ?>
                                <label class="flex items-start gap-3 p-2.5 hover:bg-white cursor-pointer select-none transition duration-100">
                                    <input type="checkbox" name="pois[]" value="<?php echo htmlspecialchars($id); ?>" 
                                           class="poi-checkbox mt-1 h-4 w-4 text-[#0f766e] rounded-none border-neutral-300 focus:ring-0 focus:ring-offset-0 focus:outline-none">
                                    <div class="flex-1 min-w-0">
                                        <div class="text-xs font-semibold text-neutral-800 leading-snug truncate">
                                            <?php echo htmlspecialchars($poi['input_name']); ?>
                                        </div>
                                        <div class="flex justify-between items-center text-[10px] text-neutral-500 mt-0.5">
                                            <span>
                                                <?php echo htmlspecialchars(ucfirst($poi['category'])); ?> &middot; 
                                                <?php echo round($poi['duration_min'] / 60, 1); ?> jam
                                            </span>
                                            <span class="text-amber-600 font-medium">★ <?php echo number_format($poi['rating'], 1); ?></span>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Failed POIs Info -->
                <?php if (!empty($failed_pois)): ?>
                    <div>
                        <label class="block text-xs font-semibold text-neutral-500 uppercase mb-2">Tidak Tersedia untuk Optimasi</label>
                        <div class="space-y-1 bg-neutral-50 p-2.5 border border-neutral-200 text-[11px] text-neutral-500 max-h-24 overflow-y-auto">
                            <?php foreach ($failed_pois as $f_poi): ?>
                                <div class="flex justify-between border-b border-neutral-100 pb-1 last:border-0 last:pb-0">
                                    <span class="font-medium text-neutral-600 truncate mr-2" title="<?php echo htmlspecialchars($f_poi['message']); ?>">
                                        <?php echo htmlspecialchars($f_poi['name']); ?>
                                    </span>
                                    <span class="text-[9px] bg-neutral-200 text-neutral-600 px-1 uppercase font-semibold h-fit flex-shrink-0">No Forecast</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

            </div>

            <!-- Run Button -->
            <div class="p-6 border-t border-neutral-200 bg-white">
                <button type="button" id="btn-optimize" class="w-full bg-[#0f766e] text-white py-3 font-semibold text-xs tracking-normal uppercase hover:bg-[#0c5c56] focus:outline-none rounded-none transition duration-150 cursor-pointer">
                    Mulai Proses Optimasi
                </button>
            </div>
        </div>
        
        <!-- MAIN WORKSPACE -->
        <div class="flex-1 flex flex-col h-full bg-neutral-100 overflow-hidden relative">
            
            <!-- Mobile Navigation Bar (Header) -->
            <div class="md:hidden flex items-center justify-between bg-white border-b border-neutral-200 px-4 py-3 flex-shrink-0 z-20">
                <button type="button" id="btn-toggle-sidebar" class="text-neutral-700 focus:outline-none p-1.5 border border-neutral-300 rounded-none bg-neutral-50 hover:bg-neutral-100 flex items-center justify-center cursor-pointer">
                    <div class="space-y-1 w-4 h-3 flex flex-col justify-between">
                        <div class="h-[2px] bg-neutral-800 w-full"></div>
                        <div class="h-[2px] bg-neutral-800 w-full"></div>
                        <div class="h-[2px] bg-neutral-800 w-full"></div>
                    </div>
                </button>
                <span class="text-xs font-bold text-neutral-900 uppercase tracking-normal">TOURIZME</span>
                <div class="w-8 h-8"></div>
            </div>

            <!-- Welcome Instructions (Initial) -->
            <div id="welcome-panel" class="absolute inset-0 bg-neutral-50 flex flex-col justify-center items-center p-12 text-center z-10 transition duration-300 top-[53px] md:top-0">
                <div class="max-w-md">
                    <h2 class="text-xl font-bold text-neutral-900">Perencanaan Jadwal Bebas Kepadatan</h2>
                    <p class="text-xs text-neutral-500 mt-2 leading-relaxed">
                        Gunakan menu di sebelah kiri untuk menentukan hari, jam operasional wisata, serta destinasi wisata yang ingin Anda kunjungi. Sistem akan mencari struktur itinerary terbaik menggunakan metode Hill Climbing.
                    </p>
                    <div class="mt-6 flex flex-col gap-2.5 text-left bg-white p-5 border border-neutral-200 text-xs text-neutral-600">
                        <div class="flex gap-3 items-start">
                            <span class="font-bold text-[#0f766e]">1.</span>
                            <span>Konfigurasi rentang hari dan jam harian kunjungan.</span>
                        </div>
                        <div class="flex gap-3 items-start">
                            <span class="font-bold text-[#0f766e]">2.</span>
                            <span>Centang daftar lokasi wisata (POI) yang ingin dituju.</span>
                        </div>
                        <div class="flex gap-3 items-start">
                            <span class="font-bold text-[#0f766e]">3.</span>
                            <span>Klik <strong>Mulai Proses Optimasi</strong> untuk melihat hasil simulasi pencarian.</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dashboard Results Screen (Visible after optimization) -->
            <div id="results-panel" class="hidden flex-1 flex flex-col h-full overflow-hidden">
                
                <!-- Top Header Actions -->
                <div class="bg-white border-b border-neutral-200 px-4 md:px-6 py-3 md:py-4 flex justify-between items-center flex-shrink-0">
                    <div class="min-w-0 pr-2">
                        <h2 class="text-xs md:text-sm font-bold text-neutral-900 uppercase truncate">Itinerary Hasil Optimasi</h2>
                        <p class="text-[9px] md:text-[10px] text-neutral-500 mt-0.5 truncate">Hasil pencarian Hill Climbing</p>
                    </div>
                    <div class="flex gap-1.5 flex-shrink-0">
                        <button type="button" id="btn-export-csv" class="border border-neutral-300 text-neutral-700 bg-white px-2 md:px-3 py-1.5 font-semibold text-[10px] md:text-xs uppercase hover:bg-neutral-50 rounded-none cursor-pointer">
                            CSV
                        </button>
                        <button type="button" id="btn-reset" class="bg-neutral-800 text-white px-2 md:px-3 py-1.5 font-semibold text-[10px] md:text-xs uppercase hover:bg-neutral-900 rounded-none cursor-pointer">
                            Revisi
                        </button>
                    </div>
                </div>

                <!-- Metrics Box -->
                <div class="grid grid-cols-3 border-b border-neutral-200 bg-white flex-shrink-0 divide-x divide-neutral-200">
                    <!-- Density Metric -->
                    <div class="p-3 md:p-6">
                        <span class="text-[9px] md:text-[10px] text-neutral-400 font-semibold block uppercase leading-none">Skor Kepadatan</span>
                        <div class="flex items-baseline gap-1 mt-1 md:mt-2">
                            <span id="metric-opt-density" class="text-lg md:text-3xl font-bold text-neutral-900 leading-none">-</span>
                            <span id="metric-base-density-diff" class="text-[9px] md:text-xs font-semibold leading-none">-</span>
                        </div>
                        <span class="text-[9px] md:text-[10px] text-neutral-500 mt-1 block">Awal: <span id="metric-base-density" class="font-medium">-</span></span>
                    </div>
                    
                    <!-- Visited Count Metric -->
                    <div class="p-3 md:p-6">
                        <span class="text-[9px] md:text-[10px] text-neutral-400 font-semibold block uppercase leading-none">POI Terkunjungi</span>
                        <div class="flex items-baseline gap-0.5 mt-1 md:mt-2">
                            <span id="metric-opt-visited" class="text-lg md:text-3xl font-bold text-neutral-900 leading-none">-</span>
                            <span class="text-[10px] text-neutral-500 leading-none">/ <span id="metric-total-selected">-</span></span>
                        </div>
                        <span class="text-[9px] md:text-[10px] text-neutral-500 mt-1 block">Awal: <span id="metric-base-visited" class="font-medium">-</span></span>
                    </div>
                    
                    <!-- Computation Time -->
                    <div class="p-3 md:p-6">
                        <span class="text-[9px] md:text-[10px] text-neutral-400 font-semibold block uppercase leading-none">Komputasi</span>
                        <div class="text-lg md:text-3xl font-bold text-neutral-900 mt-1 md:mt-2 leading-none">
                            <span id="metric-time">-</span> <span class="text-[10px] font-normal text-neutral-500">ms</span>
                        </div>
                        <span class="text-[9px] md:text-[10px] text-neutral-500 mt-1 block"><span id="metric-steps-count" class="font-semibold">-</span> langkah</span>
                    </div>
                </div>

                <!-- Mobile View Mode Tabs -->
                <div class="flex md:hidden border-b border-neutral-200 bg-white flex-shrink-0">
                    <button type="button" id="tab-itinerary" class="flex-1 py-2.5 text-xs font-bold uppercase border-b-2 border-[#0f766e] text-[#0f766e] rounded-none cursor-pointer">
                        Daftar Jadwal
                    </button>
                    <button type="button" id="tab-map" class="flex-1 py-2.5 text-xs font-bold uppercase border-b-2 border-transparent text-neutral-500 rounded-none cursor-pointer">
                        Peta Rute
                    </button>
                </div>

                <!-- Itinerary Columns Layout & Map Visualizer -->
                <div class="flex-1 flex overflow-hidden bg-neutral-100 min-h-0 relative">
                    <!-- Lanes Container -->
                    <div id="itinerary-lanes-container" class="flex-1 flex overflow-x-auto gap-4 md:gap-6 p-4 md:p-6 items-start overflow-y-auto">
                        <!-- Day Lanes rendered dynamically here -->
                    </div>
                    
                    <!-- Map Visualizer Panel (Absolute overlay on mobile, static sidebar on desktop) -->
                    <div id="map-panel" class="absolute md:static inset-0 md:inset-auto md:w-96 bg-white border-t md:border-t-0 md:border-l border-neutral-200 flex-shrink-0 flex flex-col h-full hidden md:flex z-10">
                        <div class="p-3 border-b border-neutral-100 flex-shrink-0 flex justify-between items-center bg-white">
                            <h3 class="text-xs font-bold text-neutral-800 uppercase">Visualisasi Rute Peta</h3>
                            <span class="text-[9px] bg-[#0f766e]/10 text-[#0f766e] px-1.5 py-0.5 uppercase font-semibold">OpenStreetMap</span>
                        </div>
                        <div id="map" class="flex-1 w-full h-full z-0 bg-neutral-50 rounded-none"></div>
                    </div>
                </div>

                <!-- Algorithm Stepper (Execution Trace) Panel -->
                <div class="bg-white border-t border-neutral-200 p-4 md:p-6 flex flex-col flex-shrink-0 h-56 md:h-72">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 mb-3">
                        <div>
                            <h3 class="text-xs font-bold text-neutral-900 uppercase">Jejak Pencarian Hill Climbing</h3>
                            <p class="text-[9px] md:text-[10px] text-neutral-500">Pilih langkah di daftar atau putar simulasi untuk melihat perubahan jadwal</p>
                        </div>
                        <div class="flex gap-1.5 items-center w-full sm:w-auto">
                            <button type="button" id="btn-anim-prev" class="flex-1 sm:flex-none border border-neutral-300 text-neutral-600 px-2.5 py-1 text-[10px] md:text-xs hover:bg-neutral-50 rounded-none disabled:opacity-40 cursor-pointer">&larr; Mundur</button>
                            <button type="button" id="btn-anim-play" class="flex-1 sm:flex-none bg-[#0f766e] text-white px-3 py-1 text-[10px] md:text-xs font-semibold uppercase hover:bg-[#0c5c56] rounded-none cursor-pointer">Putar</button>
                            <button type="button" id="btn-anim-next" class="flex-1 sm:flex-none border border-neutral-300 text-neutral-600 px-2.5 py-1 text-[10px] md:text-xs hover:bg-neutral-50 rounded-none disabled:opacity-40 cursor-pointer">Maju &rarr;</button>
                        </div>
                    </div>
                    
                    <!-- Console/Log List -->
                    <div class="flex-1 overflow-auto bg-neutral-900 text-neutral-300 font-mono text-[10px] md:text-[11px] p-3 md:p-4 rounded-none">
                        <div class="min-w-[500px]">
                            <table class="w-full text-left select-none">
                                <thead>
                                    <tr class="text-neutral-500 border-b border-neutral-800 pb-1 uppercase text-[8px] md:text-[9px]">
                                        <th class="py-1 w-12">Langkah</th>
                                        <th class="py-1">Keputusan Optimizer</th>
                                        <th class="py-1 w-20 text-right">Cost</th>
                                        <th class="py-1 w-24 text-right">Kepadatan</th>
                                        <th class="py-1 w-20 text-right">Terkunjungi</th>
                                    </tr>
                                </thead>
                                <tbody id="log-tbody" class="cursor-pointer">
                                    <!-- Steps rows rendered dynamically -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>

        </div>

    </div>

    <!-- Hidden global values -->
    <script>
        window.csrfToken = "<?php echo $_SESSION['csrf_token']; ?>";
        const DAY_NAMES = ["Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu", "Minggu"];
    </script>

    <!-- AJAX & UI Logic Code -->
    <script src="https://code.jquery.com/jquery-4.0.0.min.js" integrity="sha256-OaVG6prZf4v69dPg6PhVattBXkcOWQB62pdZ3ORyrao=" crossorigin="anonymous"></script>
    <script>
        $(document).ready(function() {
            let stepsData = [];
            let currentStepIndex = 0;
            let animationInterval = null;
            let map = null;
            let markersLayer = null;
            let polylineLayer = null;

            // Select all POIs convenience button
            $('#select-all-pois').click(function(e) {
                e.preventDefault();
                let allChecked = $('.poi-checkbox:checked').length === $('.poi-checkbox').length;
                $('.poi-checkbox').prop('checked', !allChecked);
            });

            // Action: Reset/Revision Button
            $('#btn-reset').click(function() {
                stopAnimation();
                $('#results-panel').addClass('hidden');
                $('#welcome-panel').removeClass('hidden');
                if ($(window).width() < 768) {
                    $('#sidebar').removeClass('-translate-x-full');
                    $('#sidebar-backdrop').removeClass('hidden');
                }
            });

            // Toggle Sidebar Drawer for Mobile
            $('#btn-toggle-sidebar').click(function() {
                $('#sidebar').removeClass('-translate-x-full');
                $('#sidebar-backdrop').removeClass('hidden');
            });

            $('#btn-close-sidebar, #sidebar-backdrop').click(function() {
                closeSidebar();
            });

            function closeSidebar() {
                $('#sidebar').addClass('-translate-x-full');
                $('#sidebar-backdrop').addClass('hidden');
            }

            // Mobile Tab Switches
            $('#tab-itinerary').click(function() {
                $(this).addClass('border-[#0f766e] text-[#0f766e]').removeClass('border-transparent text-neutral-500');
                $('#tab-map').removeClass('border-[#0f766e] text-[#0f766e]').addClass('border-transparent text-neutral-500');
                $('#itinerary-lanes-container').removeClass('hidden');
                $('#map-panel').addClass('hidden md:flex');
            });

            // Make sure active mode is visually clean
            $('#tab-map').click(function() {
                $(this).addClass('border-[#0f766e] text-[#0f766e]').removeClass('border-transparent text-neutral-500');
                $('#tab-itinerary').removeClass('border-[#0f766e] text-[#0f766e]').addClass('border-transparent text-neutral-500');
                $('#itinerary-lanes-container').addClass('hidden');
                $('#map-panel').removeClass('hidden').addClass('flex');
                if (map) {
                    setTimeout(() => {
                        map.invalidateSize();
                    }, 100);
                }
            });

            // Run optimizer via POST
            $('#btn-optimize').click(function() {
                let pois = [];
                $('.poi-checkbox:checked').each(function() {
                    pois.push($(this).val());
                });

                if (pois.length === 0) {
                    alert('Silakan pilih minimal satu tempat wisata (POI) terlebih dahulu.');
                    return;
                }

                let startDay = $('#start-day').val();
                let tripDays = $('#trip-days').val();
                let startHour = parseFloat($('#start-hour').val());
                let endHour = parseFloat($('#end-hour').val());

                if (startHour >= endHour) {
                    alert('Jam mulai harus lebih awal daripada jam selesai harian.');
                    return;
                }

                closeSidebar();
                $('#btn-optimize').prop('disabled', true).text('Memproses Optimasi...');

                $.ajax({
                    url: 'index.php',
                    type: 'POST',
                    data: {
                        action: 'optimize',
                        csrf_token: window.csrfToken,
                        pois: pois,
                        start_day: startDay,
                        days: tripDays,
                        start_hour: startHour,
                        end_hour: endHour
                    },
                    dataType: 'json',
                    success: function(response) {
                        $('#btn-optimize').prop('disabled', false).text('Mulai Proses Optimasi');
                        
                        if (response.status === 'error') {
                            alert(response.message);
                            return;
                        }

                        // Load data
                        stepsData = response.data.steps;
                        let timeMs = response.data.time_ms;
                        let baseline = response.data.baseline;
                        let optimized = response.data.optimized;

                        // Setup layout
                        $('#welcome-panel').addClass('hidden');
                        $('#results-panel').removeClass('hidden');

                        // Set final comparison metrics
                        $('#metric-opt-density').text(optimized.avg_density + '%');
                        $('#metric-base-density').text(baseline.avg_density + '%');
                        
                        // Diff calculation
                        let diff = Math.round(optimized.avg_density - baseline.avg_density);
                        let diffText = diff > 0 ? '+' + diff + '%' : diff + '%';
                        let diffClass = diff < 0 ? 'text-emerald-600' : (diff > 0 ? 'text-rose-600' : 'text-neutral-500');
                        $('#metric-base-density-diff').text(diffText).attr('class', 'text-xs font-semibold ' + diffClass);

                        $('#metric-opt-visited').text(optimized.visited_count);
                        $('#metric-base-visited').text(baseline.visited_count);
                        $('#metric-total-selected').text(pois.length);

                        $('#metric-time').text(timeMs);
                        $('#metric-steps-count').text(stepsData.length - 1);

                        // Render trace logs
                        renderTraceLogs();

                        // Set step index to final optimized state
                        currentStepIndex = stepsData.length - 1;
                        renderStep(currentStepIndex);
                    },
                    error: function() {
                        $('#btn-optimize').prop('disabled', false).text('Mulai Proses Optimasi');
                        alert('Terjadi kegagalan komunikasi server. Silakan coba kembali.');
                    }
                });
            });

            // Format time float to HH:MM format
            function formatTime(hourFloat) {
                let hours = Math.floor(hourFloat);
                let minutes = Math.round((hourFloat - hours) * 60);
                return (hours < 10 ? '0' + hours : hours) + ':' + (minutes < 10 ? '0' + minutes : minutes);
            }

            // Render Trace Logs inside the bottom panel table
            function renderTraceLogs() {
                let tbody = $('#log-tbody');
                tbody.empty();

                stepsData.forEach((step, idx) => {
                    let tr = $('<tr>').attr('data-index', idx)
                        .addClass('hover:bg-neutral-800 border-b border-neutral-800 text-neutral-300 font-mono text-[11px]');
                    
                    let tdStep = $('<td>').addClass('py-2 font-semibold').text(step.step);
                    let tdAction = $('<td>').addClass('py-2 text-neutral-200').text(step.action);
                    let tdCost = $('<td>').addClass('py-2 text-right').text(Math.round(step.cost));
                    let tdDensity = $('<td>').addClass('py-2 text-right').text(Math.round(step.avg_density) + '%');
                    let tdVisited = $('<td>').addClass('py-2 text-right').text(step.visited_count);

                    tr.append(tdStep, tdAction, tdCost, tdDensity, tdVisited);
                    tbody.append(tr);
                });

                // Attach click handler to log rows
                $('#log-tbody tr').click(function() {
                    stopAnimation();
                    let idx = parseInt($(this).attr('data-index'));
                    currentStepIndex = idx;
                    renderStep(idx);
                });
            }

            // Render a specific step iteration details and timelines
            function renderStep(idx) {
                currentStepIndex = idx;
                let step = stepsData[idx];

                // Update row highlight
                $('#log-tbody tr').removeClass('bg-neutral-800 text-white font-bold');
                $('#log-tbody tr[data-index="' + idx + '"]').addClass('bg-neutral-800 text-white font-bold');

                // Update metrics details based on step values
                $('#metric-opt-density').text(Math.round(step.avg_density) + '%');
                $('#metric-opt-visited').text(step.visited_count);

                // Update timeline container
                let container = $('#itinerary-lanes-container');
                container.empty();

                let startDayInt = parseInt($('#start-day').val());

                for (let d in step.schedule) {
                    let dayNum = parseInt(d);
                    let dayOfWeekName = DAY_NAMES[(startDayInt + (dayNum - 1)) % 7];

                    // Lane element
                    let lane = $('<div>').addClass('w-80 flex-shrink-0 bg-white border border-neutral-200 p-4 rounded-none flex flex-col self-stretch max-h-full');
                    
                    let laneHeader = $('<div>').addClass('border-b border-neutral-200 pb-3 mb-4 flex-shrink-0');
                    let dayTitle = $('<h3>').addClass('text-sm font-bold text-neutral-900').text('Hari ' + dayNum);
                    let daySub = $('<p>').addClass('text-[10px] text-neutral-500 uppercase font-semibold mt-0.5').text(dayOfWeekName);
                    laneHeader.append(dayTitle, daySub);
                    lane.append(laneHeader);

                    // Scrollable area for timeline visits
                    let laneTimeline = $('<div>').addClass('flex-1 overflow-y-auto space-y-3 pr-1');

                    let dayVisits = step.schedule[d];
                    if (dayVisits.length === 0) {
                        laneTimeline.append($('<div>').addClass('text-xs text-neutral-400 italic py-8 text-center').text('Tidak ada jadwal kunjungan'));
                    } else {
                        dayVisits.forEach((visit, visitIdx) => {
                            // If not first visit, show travel buffer
                            if (visit.travel_time_before > 0) {
                                let travelDiv = $('<div>').addClass('flex items-center gap-2 pl-3 text-[10px] text-neutral-400 font-mono');
                                let arrow = $('<span>').text('↓');
                                let label = $('<span>').text('Perjalanan (' + visit.travel_time_before + ' menit)');
                                travelDiv.append(arrow, label);
                                laneTimeline.append(travelDiv);
                            }

                            // Visit card element (no shadow, no rounded corners, left solid border representing crowd)
                            let density = Math.round(visit.avg_density);
                            let borderClass = 'border-l-4 border-emerald-600 bg-neutral-50'; // Low crowd default
                            let densityLabel = 'Rendah';

                            if (density > 70) {
                                borderClass = 'border-l-4 border-rose-600 bg-neutral-50';
                                densityLabel = 'Padat';
                            } else if (density > 35) {
                                borderClass = 'border-l-4 border-amber-500 bg-neutral-50';
                                densityLabel = 'Sedang';
                            }

                            let card = $('<div>').addClass('p-3 rounded-none flex flex-col border border-neutral-200 border-l-0 ' + borderClass);
                            let cardTime = $('<span>').addClass('text-[10px] text-neutral-500 font-semibold font-mono').text(formatTime(visit.start) + ' - ' + formatTime(visit.end));
                            let cardTitle = $('<h4>').addClass('text-xs font-bold text-neutral-800 leading-tight mt-1').text(visit.poi.input_name);
                            
                            let cardFooter = $('<div>').addClass('flex justify-between items-center text-[9px] text-neutral-500 mt-2 border-t border-neutral-100 pt-1.5');
                            let category = $('<span>').text(visit.poi.category.charAt(0).toUpperCase() + visit.poi.category.slice(1));
                            let densityText = $('<span>').addClass('font-semibold').text('Kepadatan: ' + density + '% (' + densityLabel + ')');

                            cardFooter.append(category, densityText);
                            card.append(cardTime, cardTitle, cardFooter);
                            laneTimeline.append(card);
                        });
                    }

                    lane.append(laneTimeline);
                    container.append(lane);
                }

                // Append unvisited POIs if any
                if (step.unvisited && step.unvisited.length > 0) {
                    let laneUnvisited = $('<div>').addClass('w-80 flex-shrink-0 bg-neutral-50 border border-neutral-200 border-dashed p-4 rounded-none flex flex-col self-stretch max-h-full opacity-75');
                    let laneHeader = $('<div>').addClass('border-b border-neutral-200 pb-3 mb-4 flex-shrink-0');
                    let title = $('<h3>').addClass('text-sm font-bold text-neutral-500').text('Tidak Muat Jadwal');
                    let subtitle = $('<p>').addClass('text-[10px] text-neutral-400 uppercase font-semibold mt-0.5').text('Waktu tidak mencukupi');
                    laneHeader.append(title, subtitle);
                    laneUnvisited.append(laneHeader);

                    let listDiv = $('<div>').addClass('flex-1 overflow-y-auto space-y-2 pr-1');
                    step.unvisited.forEach(poiId => {
                        let row = $('<div>').addClass('bg-white border border-neutral-200 p-2.5 text-xs text-neutral-500 rounded-none');
                        // Find name
                        let checkboxInput = $('.poi-checkbox[value="' + poiId + '"]');
                        let poiName = checkboxInput.closest('label').find('.text-xs').text().trim() || poiId;
                        row.text(poiName);
                        listDiv.append(row);
                    });
                    laneUnvisited.append(listDiv);
                    container.append(laneUnvisited);
                }

                // Update button disabled state
                $('#btn-anim-prev').prop('disabled', idx === 0);
                $('#btn-anim-next').prop('disabled', idx === stepsData.length - 1);

                // Update map markers and paths
                updateMapForSchedule(step.schedule);
            }

            // Play/Pause Animation Simulation
            $('#btn-anim-play').click(function() {
                if (animationInterval) {
                    stopAnimation();
                } else {
                    playAnimation();
                }
            });

            function playAnimation() {
                if (animationInterval) return;
                
                // If we are at the end, start from beginning
                if (currentStepIndex === stepsData.length - 1) {
                    currentStepIndex = 0;
                    renderStep(0);
                }

                animationInterval = setInterval(() => {
                    currentStepIndex++;
                    if (currentStepIndex >= stepsData.length) {
                        stopAnimation();
                        return;
                    }
                    renderStep(currentStepIndex);
                }, 1000); // Step speed is 1 second

                $('#btn-anim-play').text('Jeda Simulasi').addClass('bg-neutral-800 hover:bg-neutral-900').removeClass('bg-[#0f766e] hover:bg-[#0c5c56]');
            }

            function stopAnimation() {
                if (animationInterval) {
                    clearInterval(animationInterval);
                    animationInterval = null;
                }
                $('#btn-anim-play').text('Putar Simulasi').addClass('bg-[#0f766e] hover:bg-[#0c5c56]').removeClass('bg-neutral-800 hover:bg-neutral-900');
            }

            // Stepper controls: Prev and Next
            $('#btn-anim-prev').click(function() {
                stopAnimation();
                if (currentStepIndex > 0) {
                    currentStepIndex--;
                    renderStep(currentStepIndex);
                }
            });

            $('#btn-anim-next').click(function() {
                stopAnimation();
                if (currentStepIndex < stepsData.length - 1) {
                    currentStepIndex++;
                    renderStep(currentStepIndex);
                }
            });

            // Export current step schedule to CSV file
            $('#btn-export-csv').click(function() {
                if (stepsData.length === 0) return;

                let csv = "Hari,Jam Mulai,Jam Selesai,ID POI,Nama Tempat Wisata,Kategori,Skor Kepadatan (%)\n";
                let currentSchedule = stepsData[currentStepIndex].schedule;
                let startDayInt = parseInt($('#start-day').val());

                for (let day in currentSchedule) {
                    let dayOfWeekName = DAY_NAMES[(startDayInt + (parseInt(day) - 1)) % 7];
                    currentSchedule[day].forEach(visit => {
                        let startStr = formatTime(visit.start);
                        let endStr = formatTime(visit.end);
                        let row = [
                            `Hari ${day} (${dayOfWeekName})`,
                            startStr,
                            endStr,
                            visit.poi.id,
                            `"${visit.poi.input_name.replace(/"/g, '""')}"`,
                            visit.poi.category,
                            Math.round(visit.avg_density)
                        ].join(",");
                        csv += row + "\n";
                    });
                }
                
                let blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                let link = document.createElement("a");
                let url = URL.createObjectURL(blob);
                link.setAttribute("href", url);
                link.setAttribute("download", `itinerary_optimasi_langkah_${currentStepIndex}.csv`);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            });

            // Initialize Leaflet Map
            function initMap() {
                if (map) {
                    map.invalidateSize();
                    return;
                }
                
                // Surakarta (Solo) center coordinates: -7.5666, 110.8166
                map = L.map('map', {
                    zoomControl: false,
                    attributionControl: false
                }).setView([-7.5666, 110.8166], 13);

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19
                }).addTo(map);

                // Add zoom control on the top right
                L.control.zoom({
                    position: 'topright'
                }).addTo(map);

                markersLayer = L.layerGroup().addTo(map);
                polylineLayer = L.layerGroup().addTo(map);
            }

            // Update Map for current schedule
            function updateMapForSchedule(schedule) {
                initMap();
                markersLayer.clearLayers();
                polylineLayer.clearLayers();

                let colors = ['#0f766e', '#2563eb', '#e11d48', '#d97706', '#7c3aed', '#0891b2', '#4f46e5'];
                let bounds = [];

                for (let day in schedule) {
                    let dayVisits = schedule[day];
                    let latlngs = [];
                    let color = colors[(parseInt(day) - 1) % colors.length];

                    dayVisits.forEach((visit, index) => {
                        let lat = visit.poi.latitude;
                        let lng = visit.poi.longitude;
                        let name = visit.poi.input_name;
                        let timeStr = formatTime(visit.start) + ' - ' + formatTime(visit.end);

                        latlngs.push([lat, lng]);
                        bounds.push([lat, lng]);

                        // Custom square marker badge
                        let markerHtml = `
                            <div class="flex items-center justify-center bg-white border-2 text-neutral-800 font-bold font-mono text-[9px] w-6 h-6 rounded-none" style="border-color: ${color};">
                                H${day}
                            </div>
                        `;
                        
                        let customIcon = L.divIcon({
                            html: markerHtml,
                            className: 'custom-div-icon',
                            iconSize: [24, 24],
                            iconAnchor: [12, 12]
                        });

                        let marker = L.marker([lat, lng], { icon: customIcon })
                            .bindPopup(`<div class="font-sans p-1 text-xs"><strong class="block mb-1 text-neutral-900">${name}</strong>Hari ${day} &middot; ${timeStr}</div>`, {
                                closeButton: false,
                                className: 'custom-popup'
                            });
                        markersLayer.addLayer(marker);
                    });

                    // Draw travel polyline per day
                    if (latlngs.length > 1) {
                        let polyline = L.polyline(latlngs, {
                            color: color,
                            weight: 3,
                            opacity: 0.8,
                            dashArray: '5, 5'
                        });
                        polylineLayer.addLayer(polyline);
                    }
                }

                // Auto-center and fit map bounds to show all markers
                if (bounds.length > 0) {
                    // Slight delay to ensure DOM is fully layed out and Leaflet reads size correctly
                    setTimeout(() => {
                        map.invalidateSize();
                        map.fitBounds(bounds, { padding: [40, 40] });
                    }, 100);
                }
            }
        });
    </script>
</body>
</html>