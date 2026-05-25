<?php
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(__DIR__)) . '/config/database.php';

try {
    // Parámetros
    $trabajador_id = intval($_GET['trabajador_id'] ?? 0);
    $puesto_id = intval($_GET['puesto_id'] ?? 0);
    $mes = intval($_GET['mes'] ?? date('n'));
    $anio = intval($_GET['anio'] ?? date('Y'));
    
    if (!$trabajador_id || !$puesto_id) {
        throw new Exception('Parámetros requeridos: trabajador_id, puesto_id');
    }
    
    $db = Database::getInstance()->getConnection();
    
    $debug = [
        'timestamp' => date('Y-m-d H:i:s'),
        'database' => DB_NAME,
        'driver' => DB_DRIVER,
        'params' => [
            'trabajador_id' => $trabajador_id,
            'puesto_id' => $puesto_id,
            'mes' => $mes,
            'anio' => $anio
        ]
    ];
    
    // 1. Verificar restricciones del trabajador
    $restrictionsSql = "SELECT id, tipo_restriccion, puesto_trabajo_id, puesto_id, fecha_inicio, fecha_fin, activa
                       FROM restricciones_trabajador
                       WHERE trabajador_id = :tid
                       AND activa = true
                       ORDER BY fecha_inicio DESC";
    
    $stmt = $db->prepare($restrictionsSql);
    $stmt->execute([':tid' => $trabajador_id]);
    $restrictions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $debug['worker_restrictions'] = $restrictions;
    
    // 2. Identificar restricciones puesto_especifico
    $puestoRestrictions = array_filter($restrictions, function($r) {
        return $r['tipo_restriccion'] === 'puesto_especifico';
    });
    
    $debug['puesto_especifico_restrictions'] = array_values($puestoRestrictions);
    
    // 3. Verificar si hay restricción para el puesto específico
    $hasRestrictionForThisPuesto = false;
    foreach ($puestoRestrictions as $rest) {
        $restPuesto = $rest['puesto_trabajo_id'] ?? $rest['puesto_id'];
        if ($restPuesto && (int)$restPuesto == (int)$puesto_id) {
            $hasRestrictionForThisPuesto = true;
            break;
        }
    }
    
    $debug['has_restriction_for_this_puesto'] = $hasRestrictionForThisPuesto;
    
    // 4. Verificar turnos asignados en el mes
    $fechaInicio = sprintf('%04d-%02d-01', $anio, $mes);
    $fechaFin = date('Y-m-t', strtotime($fechaInicio));
    
    $turnosSql = "SELECT ta.id, ta.fecha, ta.estado,
                         ct.numero_turno, ct.nombre as turno_nombre,
                         pt.codigo as puesto_codigo, pt.nombre as puesto_nombre
                  FROM turnos_asignados ta
                  INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                  LEFT JOIN puestos_trabajo pt ON ta.puesto_trabajo_id = pt.id
                  WHERE ta.trabajador_id = :tid
                  AND ta.fecha BETWEEN :fi AND :ff
                  AND ta.estado IN ('programado', 'activo')
                  ORDER BY ta.fecha";
    
    $stmt = $db->prepare($turnosSql);
    $stmt->execute([':tid' => $trabajador_id, ':fi' => $fechaInicio, ':ff' => $fechaFin]);
    $assignedShifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $debug['assigned_shifts_in_month'] = $assignedShifts;
    
    // 5. Filtrar turnos en puesto restringido
    $shiftsInRestrictedPuesto = [];
    if ($hasRestrictionForThisPuesto) {
        foreach ($assignedShifts as $shift) {
            // El puesto viene de puesto_trabajo_id (el que se guardó en la asignación)
            if ($shift['puesto_trabajo_id'] == $puesto_id) {
                $shiftsInRestrictedPuesto[] = $shift;
            }
        }
    }
    
    $debug['shifts_in_restricted_puesto'] = $shiftsInRestrictedPuesto;
    $debug['issue'] = count($shiftsInRestrictedPuesto) > 0 
        ? 'PROBLEMA: El trabajador tiene ' . count($shiftsInRestrictedPuesto) . ' turnos en puesto restringido'
        : 'OK: No hay turnos en puesto restringido';
    
    // 6. Información del puesto
    $puestosql = "SELECT id, codigo, nombre FROM puestos_trabajo WHERE id = :id";
    $stmt = $db->prepare($puestosql);
    $stmt->execute([':id' => $puesto_id]);
    $puesto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $debug['puesto_info'] = $puesto;
    
    // 7. Info del trabajador
    $trabSql = "SELECT id, nombre FROM trabajadores WHERE id = :id";
    $stmt = $db->prepare($trabSql);
    $stmt->execute([':id' => $trabajador_id]);
    $trab = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $debug['trabajador_info'] = $trab;
    
    echo json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}
?>
