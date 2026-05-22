<?php
// Asegurar encoding
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Manejar CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Si es GET, redirigir a POST para consistencia
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $_POST = $_GET;
}

try {
    // Intentar conectar a BD
    require_once dirname(dirname(__DIR__)) . '/config/database.php';
    $db = Database::getInstance()->getConnection();
    $dbReady = true;
} catch (Exception $e) {
    $db = null;
    $dbReady = false;
    error_log('DB Error en reportes_export.php: ' . $e->getMessage());
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$formato = $_POST['formato'] ?? $_GET['formato'] ?? 'html'; // html, excel

try {
    if (!$dbReady && $action !== 'test') {
        throw new Exception('Base de datos no disponible');
    }
    
    switch ($action) {
        case 'test':
            // Test endpoint para verificar que el archivo existe
            echo json_encode(['success' => true, 'message' => 'Archivo reportes_export.php está funcionando']);
            exit;
            
        case 'turnos_mes':
            generarReporteTurnosMes($db, $formato);
            break;
            
        case 'cobertura':
            generarReporteCobertura($db, $formato);
            break;
            
        case 'equidad':
            generarReporteEquidad($db, $formato);
            break;
            
        case 'trabajador':
            $trabajador_id = $_POST['trabajador_id'] ?? $_GET['trabajador_id'] ?? null;
            if (!$trabajador_id) {
                throw new Exception('trabajador_id requerido');
            }
            generarReporteTrabajador($db, $trabajador_id, $formato);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Acción no válida: ' . $action]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function generarReporteTurnosMes($db, $formato) {
    $mes = $_GET['mes'] ?? date('m');
    $anio = $_GET['anio'] ?? date('Y');
    
    $primerDia = "$anio-$mes-01";
    $ultimoDia = date('Y-m-t', strtotime($primerDia));
    
    $sql = "SELECT 
            ta.fecha,
            t.nombre as trabajador,
            t.cedula,
            pt.codigo as puesto,
            pt.area,
            ct.numero_turno,
            ct.nombre as turno_nombre,
            ta.estado
            FROM turnos_asignados ta
            INNER JOIN trabajadores t ON ta.trabajador_id = t.id
            LEFT JOIN puestos_trabajo pt ON ta.puesto_id = pt.id OR ta.puesto_trabajo_id = pt.id
            INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
            WHERE ta.fecha BETWEEN :inicio AND :fin
            ORDER BY ta.fecha, t.nombre";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([':inicio' => $primerDia, ':fin' => $ultimoDia]);
    $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($formato === 'excel') {
        exportarExcel("Reporte_Turnos_{$mes}_{$anio}", $datos);
    } elseif ($formato === 'pdf') {
        exportarPDF("Reporte de Turnos - $mes/$anio", $datos);
    } else {
        echo json_encode(['success' => true, 'data' => $datos]);
    }
}

function generarReporteCobertura($db, $formato) {
    $fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
    $fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-t');
    
    $sql = "SELECT 
            ta.fecha,
            COUNT(DISTINCT CASE WHEN ta.turno_id = 1 THEN ta.id END) as turno_1,
            COUNT(DISTINCT CASE WHEN ta.turno_id = 2 THEN ta.id END) as turno_2,
            COUNT(DISTINCT CASE WHEN ta.turno_id = 3 THEN ta.id END) as turno_3,
            COUNT(DISTINCT CASE WHEN ta.estado = 'programado' THEN ta.id END) as programados,
            COUNT(DISTINCT CASE WHEN ta.estado = 'activo' THEN ta.id END) as activos,
            COUNT(DISTINCT CASE WHEN ta.estado = 'cancelado' THEN ta.id END) as cancelados
            FROM turnos_asignados ta
            WHERE ta.fecha BETWEEN :inicio AND :fin
            GROUP BY ta.fecha
            ORDER BY ta.fecha";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([':inicio' => $fecha_inicio, ':fin' => $fecha_fin]);
    $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($formato === 'excel') {
        exportarExcel("Reporte_Cobertura", $datos);
    } elseif ($formato === 'pdf') {
        exportarPDF("Reporte de Cobertura", $datos);
    } else {
        echo json_encode(['success' => true, 'data' => $datos]);
    }
}

function generarReporteEquidad($db, $formato) {
    $mes = $_GET['mes'] ?? date('m');
    $anio = $_GET['anio'] ?? date('Y');
    
    $primerDia = "$anio-$mes-01";
    $ultimoDia = date('Y-m-t', strtotime($primerDia));
    
    $sql = "SELECT 
            t.nombre as trabajador,
            t.cedula,
            COUNT(ta.id) as total_turnos,
            COUNT(CASE WHEN ta.turno_id = 1 THEN 1 END) as turno_dia,
            COUNT(CASE WHEN ta.turno_id = 2 THEN 1 END) as turno_tarde,
            COUNT(CASE WHEN ta.turno_id = 3 THEN 1 END) as turno_noche,
            COUNT(CASE WHEN ta.estado = 'activo' THEN 1 END) as realizados,
            COUNT(CASE WHEN ta.estado = 'cancelado' THEN 1 END) as cancelados,
            COUNT(DISTINCT ds.id) as dias_libres
            FROM trabajadores t
            LEFT JOIN turnos_asignados ta ON t.id = ta.trabajador_id 
                AND ta.fecha BETWEEN :inicio AND :fin
                AND ta.estado IN ('programado', 'activo')
            LEFT JOIN dias_especiales ds ON t.id = ds.trabajador_id 
                AND ds.tipo IN ('L', 'L8', 'LC')
                AND ds.fecha_inicio BETWEEN :inicio AND :fin
                AND ds.estado IN ('programado', 'activo')
            WHERE t.activo = true
            GROUP BY t.id, t.nombre, t.cedula
            ORDER BY total_turnos DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([':inicio' => $primerDia, ':fin' => $ultimoDia]);
    $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($formato === 'excel') {
        exportarExcel("Reporte_Equidad_{$mes}_{$anio}", $datos);
    } elseif ($formato === 'pdf') {
        exportarPDF("Análisis de Equidad - $mes/$anio", $datos);
    } else {
        echo json_encode(['success' => true, 'data' => $datos]);
    }
}

function generarReporteTrabajador($db, $trabajador_id, $formato) {
    $mes = $_GET['mes'] ?? date('m');
    $anio = $_GET['anio'] ?? date('Y');
    
    $primerDia = "$anio-$mes-01";
    $ultimoDia = date('Y-m-t', strtotime($primerDia));
    
    $sqlTrab = "SELECT * FROM trabajadores WHERE id = :id";
    $stmtTrab = $db->prepare($sqlTrab);
    $stmtTrab->execute([':id' => $trabajador_id]);
    $trabajador = $stmtTrab->fetch(PDO::FETCH_ASSOC);
    
    if (!$trabajador) {
        throw new Exception('Trabajador no encontrado');
    }
    
    $sql = "SELECT 
            ta.fecha,
            ct.nombre as turno_nombre,
            pt.codigo as puesto,
            pt.area,
            ta.estado
            FROM turnos_asignados ta
            INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
            LEFT JOIN puestos_trabajo pt ON ta.puesto_id = pt.id OR ta.puesto_trabajo_id = pt.id
            WHERE ta.trabajador_id = :trabajador_id
            AND ta.fecha BETWEEN :inicio AND :fin
            ORDER BY ta.fecha";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':trabajador_id' => $trabajador_id,
        ':inicio' => $primerDia,
        ':fin' => $ultimoDia
    ]);
    $turnos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $datos = [
        'cabecera' => [
            'Nombre' => $trabajador['nombre'],
            'Cédula' => $trabajador['cedula'],
            'Email' => $trabajador['email'] ?? 'N/A',
            'Teléfono' => $trabajador['telefono'] ?? 'N/A',
            'Período' => "$mes/$anio"
        ],
        'turnos' => $turnos,
        'resumen' => [
            'Total turnos' => count($turnos),
            'Realizados' => count(array_filter($turnos, fn($t) => $t['estado'] === 'activo')),
            'Programados' => count(array_filter($turnos, fn($t) => $t['estado'] === 'programado')),
            'Cancelados' => count(array_filter($turnos, fn($t) => $t['estado'] === 'cancelado'))
        ]
    ];
    
    if ($formato === 'excel') {
        exportarExcel("Reporte_{$trabajador['nombre']}_$mes-$anio", $datos);
    } elseif ($formato === 'pdf') {
        exportarPDF("Reporte de {$trabajador['nombre']}", $datos);
    } else {
        echo json_encode(['success' => true, 'data' => $datos]);
    }
}

function exportarExcel($nombre, $datos) {
    // Generar CSV compatible con Excel (más simple y sin dependencias)
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombre . '_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: max-age=0');
    
    $output = fopen('php://output', 'w');
    
    if (isset($datos['cabecera'])) {
        // Encabezado para reportes detallados
        fputcsv($output, ['REPORTE DETALLADO']);
        fputcsv($output, []);
        
        fputcsv($output, ['INFORMACIÓN DEL TRABAJADOR']);
        foreach ($datos['cabecera'] as $label => $value) {
            fputcsv($output, [$label, $value]);
        }
        fputcsv($output, []);
        fputcsv($output, ['TURNOS ASIGNADOS']);
        
        $headers = array_keys($datos['turnos'][0] ?? []);
        fputcsv($output, $headers);
        
        foreach ($datos['turnos'] as $item) {
            $row = [];
            foreach ($headers as $header) {
                $row[] = $item[$header] ?? '';
            }
            fputcsv($output, $row);
        }
        
        fputcsv($output, []);
        fputcsv($output, ['RESUMEN']);
        foreach ($datos['resumen'] as $label => $value) {
            fputcsv($output, [$label, $value]);
        }
    } else {
        // Formato simple para listados
        $headers = array_keys($datos[0] ?? []);
        fputcsv($output, $headers);
        
        foreach ($datos as $item) {
            $row = [];
            foreach ($headers as $header) {
                $row[] = $item[$header] ?? '';
            }
            fputcsv($output, $row);
        }
    }
    
    fclose($output);
    exit;
}

function exportarPDF($titulo, $datos) {
    // Generar HTML para impresión a PDF desde el navegador
    header('Content-Type: text/html; charset=utf-8');
    
    $html = '<!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . htmlspecialchars($titulo) . '</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            body {
                font-family: "Arial", sans-serif;
                padding: 20px;
                background: #fff;
                color: #333;
            }
            .container {
                max-width: 900px;
                margin: 0 auto;
                background: white;
                padding: 30px;
                border-radius: 8px;
            }
            h1 {
                text-align: center;
                color: #2c3e50;
                margin-bottom: 10px;
                border-bottom: 3px solid #3498db;
                padding-bottom: 10px;
            }
            .fecha {
                text-align: center;
                color: #7f8c8d;
                font-size: 12px;
                margin-bottom: 20px;
            }
            .seccion {
                margin: 25px 0;
            }
            .seccion-titulo {
                background: #3498db;
                color: white;
                padding: 10px 15px;
                font-weight: bold;
                font-size: 13px;
                border-radius: 4px;
                margin-bottom: 10px;
            }
            .info-item {
                display: grid;
                grid-template-columns: 200px 1fr;
                padding: 8px 0;
                border-bottom: 1px solid #ecf0f1;
            }
            .info-label {
                font-weight: bold;
                color: #2c3e50;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin: 10px 0;
                font-size: 12px;
            }
            th {
                background: #34495e;
                color: white;
                padding: 10px;
                text-align: left;
                font-weight: bold;
                border: 1px solid #2c3e50;
            }
            td {
                padding: 8px;
                border: 1px solid #ecf0f1;
            }
            tr:nth-child(even) {
                background: #f8f9fa;
            }
            .resumen-item {
                display: grid;
                grid-template-columns: 200px 1fr;
                padding: 8px 0;
                border-bottom: 1px solid #ecf0f1;
            }
            .resumen-label {
                font-weight: bold;
                color: #2c3e50;
            }
            .resumen-valor {
                text-align: right;
                font-weight: bold;
                color: #3498db;
            }
            @media print {
                body {
                    padding: 0;
                }
                .container {
                    box-shadow: none;
                    padding: 0;
                }
                a {
                    text-decoration: none;
                    color: #000;
                }
                .no-print {
                    display: none;
                }
            }
            .botones {
                text-align: center;
                margin-top: 30px;
            }
            .botones button {
                background: #3498db;
                color: white;
                padding: 10px 20px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                margin: 0 5px;
                font-size: 14px;
            }
            .botones button:hover {
                background: #2980b9;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>' . htmlspecialchars($titulo) . '</h1>
            <div class="fecha">Generado el ' . date('d/m/Y H:i:s') . '</div>';
    
    if (isset($datos['cabecera'])) {
        $html .= '<div class="seccion">
                <div class="seccion-titulo">INFORMACIÓN DEL TRABAJADOR</div>';
        foreach ($datos['cabecera'] as $label => $value) {
            $html .= '<div class="info-item">
                    <div class="info-label">' . htmlspecialchars($label) . ':</div>
                    <div>' . htmlspecialchars($value) . '</div>
                </div>';
        }
        $html .= '</div>';
        
        $html .= '<div class="seccion">
                <div class="seccion-titulo">TURNOS ASIGNADOS</div>
                <table>
                <thead><tr>';
        
        $headers = array_keys($datos['turnos'][0] ?? []);
        foreach ($headers as $header) {
            $html .= '<th>' . htmlspecialchars($header) . '</th>';
        }
        
        $html .= '</tr></thead><tbody>';
        
        foreach ($datos['turnos'] as $item) {
            $html .= '<tr>';
            foreach ($headers as $header) {
                $html .= '<td>' . htmlspecialchars($item[$header] ?? '') . '</td>';
            }
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table></div>';
        
        $html .= '<div class="seccion">
                <div class="seccion-titulo">RESUMEN</div>';
        foreach ($datos['resumen'] as $label => $value) {
            $html .= '<div class="resumen-item">
                    <div class="resumen-label">' . htmlspecialchars($label) . ':</div>
                    <div class="resumen-valor">' . htmlspecialchars($value) . '</div>
                </div>';
        }
        $html .= '</div>';
    } else {
        $html .= '<div class="seccion">
                <table>
                <thead><tr>';
        
        $headers = array_keys($datos[0] ?? []);
        foreach ($headers as $header) {
            $html .= '<th>' . htmlspecialchars($header) . '</th>';
        }
        
        $html .= '</tr></thead><tbody>';
        
        foreach ($datos as $item) {
            $html .= '<tr>';
            foreach ($headers as $header) {
                $html .= '<td>' . htmlspecialchars($item[$header] ?? '') . '</td>';
            }
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table></div>';
    }
    
    $html .= '<div class="botones no-print">
            <button onclick="window.print()">📄 Imprimir/Guardar como PDF</button>
            <button onclick="window.close()">✕ Cerrar</button>
        </div>
    </div>
    </body>
    </html>';
    
    echo $html;
    exit;
}
?>
