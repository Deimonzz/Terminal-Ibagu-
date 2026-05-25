<?php
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(__DIR__)) . '/config/database.php';

$db = Database::getInstance()->getConnection();
$driver = DB_DRIVER;

try {
    $diagnostics = [
        'database' => DB_NAME,
        'driver' => $driver,
        'timestamp' => date('Y-m-d H:i:s')
    ];

    // 1. Verificar columnas de la tabla restricciones_trabajador
    if ($driver === 'pgsql') {
        $columnsSql = "SELECT column_name, data_type, is_nullable 
                      FROM information_schema.columns 
                      WHERE table_name = 'restricciones_trabajador' 
                      AND table_schema = 'public'
                      ORDER BY ordinal_position";
    } else {
        $columnsSql = "SELECT COLUMN_NAME as column_name, COLUMN_TYPE as data_type, IS_NULLABLE as is_nullable 
                      FROM information_schema.COLUMNS 
                      WHERE TABLE_SCHEMA = DATABASE() 
                      AND TABLE_NAME = 'restricciones_trabajador'
                      ORDER BY ORDINAL_POSITION";
    }
    
    $stmt = $db->prepare($columnsSql);
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $diagnostics['table_columns'] = $columns;
    
    // Extraer nombres de columnas para verificación
    $columnNames = array_map(function($col) {
        return $col['column_name'];
    }, $columns);
    
    // 2. Verificar si existen las columnas de puesto
    $hasPuestoTrabajoId = in_array('puesto_trabajo_id', $columnNames);
    $hasPuestoId = in_array('puesto_id', $columnNames);
    
    $diagnostics['has_puesto_trabajo_id'] = $hasPuestoTrabajoId;
    $diagnostics['has_puesto_id'] = $hasPuestoId;
    $diagnostics['column_names'] = $columnNames;
    
    // 3. Verificar restricciones de tipo 'puesto_especifico'
    $restrictionQuery = "SELECT 
                        COUNT(*) as total_restrictions,
                        COUNT(CASE WHEN tipo_restriccion = 'puesto_especifico' THEN 1 END) as puesto_especifico_count,
                        COUNT(CASE WHEN activa = true THEN 1 END) as activas
                        FROM restricciones_trabajador";
    
    $stmt = $db->prepare($restrictionQuery);
    $stmt->execute();
    $restrictionsCounts = $stmt->fetch(PDO::FETCH_ASSOC);
    $diagnostics['restrictions_summary'] = $restrictionsCounts;
    
    // 4. Mostrar ejemplos de restricciones puesto_especifico
    if ($hasPuestoTrabajoId) {
        $selectPuesto = "r.puesto_trabajo_id";
    } elseif ($hasPuestoId) {
        $selectPuesto = "r.puesto_id";
    } else {
        $selectPuesto = "NULL as puesto_data";
    }
    
    $exampleSql = "SELECT 
                   t.id as trabajador_id,
                   t.nombre as trabajador,
                   r.tipo_restriccion,
                   " . $selectPuesto . " as puesto_value,
                   r.fecha_inicio,
                   r.fecha_fin,
                   r.activa
                   FROM restricciones_trabajador r
                   INNER JOIN trabajadores t ON r.trabajador_id = t.id
                   WHERE r.tipo_restriccion = 'puesto_especifico'
                   ORDER BY r.fecha_inicio DESC
                   LIMIT 10";
    
    $stmt = $db->prepare($exampleSql);
    $stmt->execute();
    $examples = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $diagnostics['examples_puesto_especifico'] = $examples;
    
    // 5. Contar cuántos tienen puesto_especifico con valores NULL vs con valores
    if (!empty($examples)) {
        $nullCount = 0;
        $withValueCount = 0;
        foreach ($examples as $ex) {
            if ($ex['puesto_value'] === null) {
                $nullCount++;
            } else {
                $withValueCount++;
            }
        }
        $diagnostics['data_integrity'] = [
            'with_values' => $withValueCount,
            'with_nulls' => $nullCount,
            'status' => $nullCount > 0 ? 'PROBLEM: Algunos registros tienen puesto_value NULL' : 'OK: Todos tienen valores'
        ];
    }
    
    // 6. Verificar qué método se usaría en código
    $columnName = Database::getColumnName('restricciones_trabajador', 'puesto_trabajo_id', 'puesto_id');
    $diagnostics['active_column_detected'] = $columnName;
    
    // 7. Verificar puestos disponibles
    $puestosCheckSql = "SELECT id, codigo, nombre FROM puestos_trabajo WHERE activo = true ORDER BY codigo LIMIT 10";
    $stmt = $db->prepare($puestosCheckSql);
    $stmt->execute();
    $puestos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $diagnostics['sample_puestos'] = $puestos;
    
    // 8. Generar recomendaciones
    $diagnostics['recommendations'] = [];
    if (!$hasPuestoTrabajoId && !$hasPuestoId) {
        $diagnostics['recommendations'][] = '❌ CRÍTICO: Ni puesto_trabajo_id ni puesto_id existen. Necesitas ejecutar la migración.';
        if ($driver === 'pgsql') {
            $diagnostics['recommendations'][] = 'Ejecutar en Render: GET /backend/scripts/migrate_add_puesto_column.php';
        }
    } elseif (!$hasPuestoTrabajoId && $hasPuestoId) {
        $diagnostics['recommendations'][] = '⚠️ Usando puesto_id (fallback). El código debería detectarlo automáticamente.';
    } elseif ($hasPuestoTrabajoId) {
        $diagnostics['recommendations'][] = '✅ puesto_trabajo_id existe correctamente.';
    }
    
    if ($diagnostics['data_integrity']['with_nulls'] > 0) {
        $diagnostics['recommendations'][] = '⚠️ ' . $diagnostics['data_integrity']['with_nulls'] . ' restricciones tienen puesto_value NULL. Verifica que se están guardando correctamente.';
    }
    
    echo json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}
?>
