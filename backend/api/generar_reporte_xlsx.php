<?php
require_once dirname(__DIR__) . '/config/database.php';
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);

// Attempt to load Composer autoload
$autoload = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Vendor autoload missing. Run composer install to enable XLSX generation.']);
    exit;
}
require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

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
        $mes = intval(date('n')); $anio = intval(date('Y'));
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

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Resumen Nómina');

    $headers = ['trabajador_id','nombre','cedula','total_turnos','total_horas','total_nocturnos','total_tnr','total_dias_libres'];
    $col = 1;
    foreach ($headers as $h) {
        $sheet->setCellValueByColumnAndRow($col, 1, $h);
        $col++;
    }

    // style header
    $headerRange = 'A1:' . chr(64 + count($headers)) . '1';
    $sheet->getStyle($headerRange)->getFont()->setBold(true);
    $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('025B2D');
    $sheet->getStyle($headerRange)->getFont()->getColor()->setRGB('FFFFFF');

    $rowNum = 2;
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
                $hi = substr($t['hora_inicio'] ?? '', 0, 5);
                $hf = substr($t['hora_fin'] ?? '', 0, 5);
                if ($hi && $hf) {
                    $p1 = explode(':', $hi); $p2 = explode(':', $hf);
                    $m1 = intval($p1[0]) * 60 + intval($p1[1]);
                    $m2 = intval($p2[0]) * 60 + intval($p2[1]);
                    $min = $m2 - $m1; if ($min < 0) $min += 1440; $h = $min / 60.0;
                } else { $h = 0; }
            }
            $total_horas += $h;
            $num = intval($t['numero_turno'] ?? 0);
            if (!empty($t['es_nocturno']) || $num === 3) $total_nocturnos++;
            if (($t['estado'] ?? '') === 'no_presentado') $total_tnr++;
        }

        $diasLibres = $diasApi->obtener(['trabajador_id' => $tid, 'fecha_inicio' => $primerDia, 'fecha_fin' => $ultimoDia]);
        $total_dias_libres = 0;
        if (is_array($diasLibres)) {
            foreach ($diasLibres as $d) {
                $fi = $d['fecha_inicio']; $ff = $d['fecha_fin'] ?: $fi;
                $d1 = max($fi, $primerDia); $d2 = min($ff, $ultimoDia);
                if ($d1 <= $d2) $total_dias_libres += (strtotime($d2) - strtotime($d1)) / 86400 + 1;
            }
        }

        $values = [$tid, $trab['nombre'] ?? '', $trab['cedula'] ?? '', $total_turnos, round($total_horas,2), $total_nocturnos, $total_tnr, $total_dias_libres];
        $col = 1;
        foreach ($values as $v) {
            $sheet->setCellValueByColumnAndRow($col, $rowNum, $v);
            $col++;
        }
        $rowNum++;
    }

    // Autosize columns
    for ($i = 1; $i <= count($headers); $i++) {
        $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
    }

    $exportsDir = dirname(__DIR__) . '/exports';
    if (!is_dir($exportsDir)) mkdir($exportsDir, 0755, true);
    $filename = sprintf('reporte_xlsx_%s_%04d-%02d_%s.xlsx', preg_replace('/[^a-z0-9_\-]/i', '', $tipo), $anio, $mes, date('Ymd_His'));
    $path = $exportsDir . '/' . $filename;

    $writer = new Xlsx($spreadsheet);
    $writer->save($path);

    $relative = 'backend/exports/' . $filename;
    echo json_encode(['success' => true, 'file' => $relative, 'path' => $path]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

?>
