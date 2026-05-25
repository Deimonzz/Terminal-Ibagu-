<?php
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(__DIR__)) . '/config/database.php';
require_once dirname(__DIR__) . '/clases/AsignacionAutomatica.php';

try {
    // Parámetros de prueba
    $mes = intval($_GET['mes'] ?? date('n'));
    $anio = intval($_GET['anio'] ?? date('Y'));
    $puesto_id = intval($_GET['puesto_id'] ?? 0);
    $fecha = $_GET['fecha'] ?? date('Y-m-d');
    
    if (!$puesto_id) {
        throw new Exception('Parámetro puesto_id requerido');
    }
    
    $db = Database::getInstance()->getConnection();
    
    $debug = [
        'mes' => $mes,
        'anio' => $anio,
        'puesto_id' => $puesto_id,
        'fecha_test' => $fecha,
        'database_driver' => DB_DRIVER
    ];
    
    // 1. Verificar qué columna se detecta
    $puestoCol = Database::getColumnName('restricciones_trabajador', 'puesto_trabajo_id', 'puesto_id');
    $debug['detected_column'] = $puestoCol;
    
    // 2. Mostrar la query que se usa en prefetch
    $selectPuesto = $puestoCol ? "$puestoCol as puesto_trabajo_id" : "NULL as puesto_trabajo_id";
    $debug['prefetch_select'] = $selectPuesto;
    
    // 3. Simular el prefetch
    $fechaInicio = sprintf('%04d-%02d-01', $anio, $mes);
    $fechaFin = date('Y-m-t', strtotime($fechaInicio));
    
    $restrictionQuery = "SELECT trabajador_id, tipo_restriccion,
                               " . $selectPuesto . ",
                               fecha_inicio, fecha_fin
                        FROM restricciones_trabajador
                        WHERE activa = true
                        AND fecha_inicio <= ?
                        AND (fecha_fin IS NULL OR fecha_fin >= ?)";
    
    $debug['restriction_query'] = $restrictionQuery;
    
    $stmt = $db->prepare($restrictionQuery);
    $stmt->execute([$fechaFin, $fechaInicio]);
    $restricciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $debug['total_restrictions_fetched'] = count($restricciones);
    
    // 4. Filtrar solo puesto_especifico para el puesto actual
    $puestoEspecificoRestrictions = array_filter($restricciones, function($r) use ($puesto_id) {
        return $r['tipo_restriccion'] === 'puesto_especifico';
    });
    
    $debug['puesto_especifico_total'] = count($puestoEspecificoRestrictions);
    
    // 5. Mostrar si las restricciones tienen puesto_trabajo_id NULL
    $withNullPuesto = array_filter($puestoEspecificoRestrictions, function($r) {
        return $r['puesto_trabajo_id'] === null;
    });
    
    $debug['with_null_puesto_trabajo_id'] = count($withNullPuesto);
    $debug['affected_workers_with_null'] = array_column($withNullPuesto, 'trabajador_id');
    
    // 6. Mostrar ejemplos
    $debug['sample_restrictions_puesto_especifico'] = array_slice($puestoEspecificoRestrictions, 0, 3);
    
    // 7. Verificar la alternativa SQL (fallback)
    $fallbackQuery = "SELECT DISTINCT trabajador_id FROM restricciones_trabajador
                     WHERE tipo_restriccion = 'puesto_especifico'
                     AND activa = true
                     AND fecha_inicio <= ?
                     AND (fecha_fin IS NULL OR fecha_fin >= ?)
                     AND (puesto_trabajo_id = ? OR puesto_id = ?)";
    
    $debug['fallback_query'] = $fallbackQuery;
    
    $stmtAlt = $db->prepare($fallbackQuery);
    $stmtAlt->execute([$fecha, $fecha, $puesto_id, $puesto_id]);
    $fallbackResults = $stmtAlt->fetchAll(PDO::FETCH_ASSOC);
    
    $debug['fallback_blocked_workers'] = array_column($fallbackResults, 'trabajador_id');
    $debug['fallback_count'] = count($fallbackResults);
    
    // 8. Comparación
    $debug['diagnosis'] = [
        'column_exists' => $puestoCol !== null,
        'column_name' => $puestoCol,
        'prefetch_working' => count($puestoEspecificoRestrictions) > 0,
        'data_integrity_issue' => count($withNullPuesto) > 0,
        'fallback_working' => count($fallbackResults) > 0,
        'recommendation' => $puestoCol ? 'Usar columna detectada' : 'Usar fallback SQL'
    ];
    
    echo json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}
?>
