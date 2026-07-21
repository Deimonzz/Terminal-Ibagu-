<?php
require_once dirname(dirname(__DIR__)) . '/config/database.php';
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);

require_once __DIR__ . '/../clases/TurnosAsignados.php';
require_once __DIR__ . '/../clases/Trabajadores.php';
require_once __DIR__ . '/../clases/DiasEspeciales.php';
require_once __DIR__ . '/../clases/Incapacidades.php';

try {
    $mes = isset($_GET['mes']) ? intval($_GET['mes']) : null;
    $anio = isset($_GET['anio']) ? intval($_GET['anio']) : null;
    $tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'general';
    $trabajador_id = isset($_GET['trabajador_id']) ? intval($_GET['trabajador_id']) : null;

    if (!$mes || !$anio) {
        // Default to current month
        $mes = intval(date('n'));
        $anio = intval(date('Y'));
    }

    $primerDia = sprintf('%04d-%02d-01', $anio, $mes);
    $ultimoDia = date('Y-m-t', strtotime($primerDia));

    $turnosApi = new TurnosAsignados();
    $trabajadoresApi = new Trabajadores();
    $diasApi = new DiasEspeciales();
    $incApi = new Incapacidades();

    $trabajadores = [];
    if ($trabajador_id) {
        $t = $trabajadoresApi->obtenerPorId($trabajador_id);
        if ($t) $trabajadores[] = $t;
    } else {
        $trabajadores = $trabajadoresApi->obtenerTodos();
    }

    // Prepare CSV header for payroll-style summary
    $rows = [];
    $rows[] = ['trabajador_id','nombre','cedula','total_turnos','total_horas','total_nocturnos','total_tnr','total_dias_libres'];

    foreach ($trabajadores as $trab) {
        $tid = $trab['id'];
        $filtros = ['fecha_inicio' => $primerDia, 'fecha_fin' => $ultimoDia, 'trabajador_id' => $tid];
        $turnos = $turnosApi->obtenerTurnos($filtros);

        $total_turnos = count($turnos);
        $total_horas = 0.0;
        $total_nocturnos = 0;
        $total_tnr = 0;

        foreach ($turnos as $t) {
            $h = isset($t['horas_laborales']) && is_numeric($t['horas_laborales']) ? floatval($t['horas_laborales']) : null;
            if ($h === null) {
                // try compute from hora_inicio/hora_fin
                $hi = substr($t['hora_inicio'] ?? '', 0, 5);
                $hf = substr($t['hora_fin'] ?? '', 0, 5);
                if ($hi && $hf) {
                    $p1 = explode(':', $hi); $p2 = explode(':', $hf);
                    $m1 = intval($p1[0]) * 60 + intval($p1[1]);
                    $m2 = intval($p2[0]) * 60 + intval($p2[1]);
                    $min = $m2 - $m1;
                    if ($min < 0) $min += 1440;
                    $h = $min / 60.0;
                } else {
                    $h = 0;
                }
            }
            $total_horas += $h;
            $num = intval($t['numero_turno'] ?? 0);
            if (!empty($t['es_nocturno']) || $num === 3) $total_nocturnos++;
            if (($t['estado'] ?? '') === 'no_presentado') $total_tnr++;
        }

        $diasLibres = $diasApi->obtener(['trabajador_id' => $tid, 'fecha_inicio' => $primerDia, 'fecha_fin' => $ultimoDia]);
        $total_dias_libres = 0;
        if (is_array($diasLibres)) {
            // Count days overlapping the range
            foreach ($diasLibres as $d) {
                $fi = $d['fecha_inicio'];
                $ff = $d['fecha_fin'] ?: $fi;
                $d1 = max($fi, $primerDia);
                $d2 = min($ff, $ultimoDia);
                if ($d1 <= $d2) {
                    $total_dias_libres += (strtotime($d2) - strtotime($d1)) / 86400 + 1;
                }
            }
        }

        $rows[] = [$tid, $trab['nombre'] ?? '', $trab['cedula'] ?? '', $total_turnos, round($total_horas,2), $total_nocturnos, $total_tnr, $total_dias_libres];
    }

    // Ensure exports dir exists
    $exportsDir = dirname(__DIR__) . '/exports';
    if (!is_dir($exportsDir)) mkdir($exportsDir, 0755, true);

    $filename = sprintf('reporte_%s_%04d-%02d_%s.csv', preg_replace('/[^a-z0-9_\-]/i', '', $tipo), $anio, $mes, date('Ymd_His'));
    $path = $exportsDir . '/' . $filename;
    $fh = fopen($path, 'w');
    foreach ($rows as $r) {
        fputcsv($fh, $r);
    }
    fclose($fh);

    // Return relative URL path
    $relative = 'backend/exports/' . $filename;
    echo json_encode(['success' => true, 'file' => $relative, 'path' => $path]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

?>
