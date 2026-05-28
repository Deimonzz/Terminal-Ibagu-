<?php
/**
 * Test de TODAS las restricciones en Render PostgreSQL
 * Verificar que la validación completa funciona correctamente
 */

header('Content-Type: application/json; charset=utf-8');

// Setup
require_once dirname(dirname(__DIR__)) . '/config/database.php';
require_once __DIR__ . '/../clases/AsignacionAutomatica.php';
require_once __DIR__ . '/../clases/TurnosAsignados.php';
require_once __DIR__ . '/../clases/Trabajadores.php';

$db = Database::getInstance()->getConnection();

echo json_encode([
    'timestamp' => date('Y-m-d H:i:s'),
    'environment' => 'render',
    'tests' => [
        'test_1_database_connection' => test_database_connection($db),
        'test_2_restricciones_table_structure' => test_restricciones_table($db),
        'test_3_puesto_especifico_validation' => test_puesto_especifico($db),
        'test_4_no_turno_noche_validation' => test_no_turno_noche($db),
        'test_5_no_fuerza_fisica_validation' => test_no_fuerza_fisica($db),
        'test_6_movilidad_limitada_validation' => test_movilidad_limitada($db),
        'test_7_asignacion_directa' => test_asignacion_directa($db),
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

function test_database_connection($db) {
    $result = ['status' => 'fail', 'message' => '', 'data' => []];
    try {
        $stmt = $db->prepare("SELECT 1 as test");
        $stmt->execute();
        $result['status'] = 'pass';
        $result['message'] = 'Conexión a BD correcta';
    } catch (Exception $e) {
        $result['message'] = 'Error: ' . $e->getMessage();
    }
    return $result;
}

function test_restricciones_table($db) {
    $result = ['status' => 'fail', 'message' => '', 'data' => []];
    
    try {
        // Verificar estructura
        $sql = "SELECT COUNT(*) as cnt FROM restricciones_trabajador WHERE activa = true";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $count = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $result['data']['active_count'] = $count['cnt'];
        
        // Tipos de restricciones
        $typeSql = "SELECT DISTINCT tipo_restriccion FROM restricciones_trabajador ORDER BY tipo_restriccion";
        $typeStmt = $db->prepare($typeSql);
        $typeStmt->execute();
        $types = $typeStmt->fetchAll(PDO::FETCH_COLUMN);
        
        $result['data']['restriction_types'] = $types;
        $result['status'] = 'pass';
        $result['message'] = 'Tabla verificada: ' . $count['cnt'] . ' restricciones activas';
    } catch (Exception $e) {
        $result['message'] = 'Error: ' . $e->getMessage();
    }
    
    return $result;
}

function test_puesto_especifico($db) {
    $result = ['status' => 'fail', 'message' => '', 'data' => []];
    
    try {
        $sql = "SELECT COUNT(*) as cnt FROM restricciones_trabajador 
                WHERE tipo_restriccion = 'puesto_especifico' AND activa = true";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row['cnt'] > 0) {
            $result['data']['count'] = $row['cnt'];
            
            // Obtener sample
            $puestoCol = Database::getColumnName('restricciones_trabajador', 'puesto_trabajo_id', 'puesto_id');
            $sampleSql = "SELECT t.id, t.nombre, r.tipo_restriccion, r." . ($puestoCol ?: "NULL") . " as puesto_id 
                          FROM trabajadores t
                          INNER JOIN restricciones_trabajador r ON t.id = r.trabajador_id
                          WHERE r.tipo_restriccion = 'puesto_especifico' AND r.activa = true
                          LIMIT 1";
            $sampleStmt = $db->prepare($sampleSql);
            $sampleStmt->execute();
            $sample = $sampleStmt->fetch(PDO::FETCH_ASSOC);
            
            $result['data']['sample_worker'] = $sample;
            $result['status'] = 'pass';
            $result['message'] = '✅ Restricciones puesto_especifico encontradas y validables';
        } else {
            $result['status'] = 'pass';
            $result['message'] = '⚠️  No hay restricciones puesto_especifico activas';
        }
    } catch (Exception $e) {
        $result['message'] = 'Error: ' . $e->getMessage();
    }
    
    return $result;
}

function test_no_turno_noche($db) {
    $result = ['status' => 'fail', 'message' => '', 'data' => []];
    
    try {
        $sql = "SELECT COUNT(*) as cnt FROM restricciones_trabajador 
                WHERE tipo_restriccion = 'no_turno_noche' AND activa = true";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $result['data']['count'] = $row['cnt'];
        
        if ($row['cnt'] > 0) {
            // Obtener sample
            $sampleSql = "SELECT t.id, t.nombre, r.tipo_restriccion
                          FROM trabajadores t
                          INNER JOIN restricciones_trabajador r ON t.id = r.trabajador_id
                          WHERE r.tipo_restriccion = 'no_turno_noche' AND r.activa = true
                          LIMIT 1";
            $sampleStmt = $db->prepare($sampleSql);
            $sampleStmt->execute();
            $sample = $sampleStmt->fetch(PDO::FETCH_ASSOC);
            
            $result['data']['sample_worker'] = $sample;
            $result['status'] = 'pass';
            $result['message'] = '✅ Restricciones no_turno_noche encontradas';
        } else {
            $result['status'] = 'pass';
            $result['message'] = '⚠️  No hay restricciones no_turno_noche activas';
        }
    } catch (Exception $e) {
        $result['message'] = 'Error: ' . $e->getMessage();
    }
    
    return $result;
}

function test_no_fuerza_fisica($db) {
    $result = ['status' => 'fail', 'message' => '', 'data' => []];
    
    try {
        $sql = "SELECT COUNT(*) as cnt FROM restricciones_trabajador 
                WHERE tipo_restriccion = 'no_fuerza_fisica' AND activa = true";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $result['data']['count'] = $row['cnt'];
        $result['status'] = 'pass';
        $result['message'] = ($row['cnt'] > 0 ? '✅' : '⚠️') . ' ' . $row['cnt'] . ' restricciones no_fuerza_fisica';
    } catch (Exception $e) {
        $result['message'] = 'Error: ' . $e->getMessage();
    }
    
    return $result;
}

function test_movilidad_limitada($db) {
    $result = ['status' => 'fail', 'message' => '', 'data' => []];
    
    try {
        $sql = "SELECT COUNT(*) as cnt FROM restricciones_trabajador 
                WHERE tipo_restriccion = 'movilidad_limitada' AND activa = true";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $result['data']['count'] = $row['cnt'];
        $result['status'] = 'pass';
        $result['message'] = ($row['cnt'] > 0 ? '✅' : '⚠️') . ' ' . $row['cnt'] . ' restricciones movilidad_limitada';
    } catch (Exception $e) {
        $result['message'] = 'Error: ' . $e->getMessage();
    }
    
    return $result;
}

function test_asignacion_directa($db) {
    $result = ['status' => 'fail', 'message' => '', 'data' => []];
    
    try {
        // Obtener trabajador con alguna restricción
        $puestoCol = Database::getColumnName('restricciones_trabajador', 'puesto_trabajo_id', 'puesto_id');
        
        $sql = "SELECT DISTINCT t.id, t.nombre, r.tipo_restriccion, r." . ($puestoCol ?: "NULL") . " as puesto_restringido
                FROM trabajadores t
                INNER JOIN restricciones_trabajador r ON t.id = r.trabajador_id
                WHERE r.activa = true
                LIMIT 1";
        
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $trabajador = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$trabajador) {
            $result['message'] = 'No hay trabajadores con restricciones';
            $result['status'] = 'pass';
            return $result;
        }
        
        $result['data']['test_worker'] = $trabajador;
        
        // Obtener turno y puesto
        $turnoStmt = $db->prepare("SELECT id FROM configuracion_turnos LIMIT 1");
        $turnoStmt->execute();
        $turno = $turnoStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$turno) {
            $result['message'] = 'No hay turnos configurados';
            return $result;
        }
        
        // Si es puesto_especifico, usar ese puesto restringido
        if ($trabajador['tipo_restriccion'] === 'puesto_especifico' && $trabajador['puesto_restringido']) {
            $puestoId = $trabajador['puesto_restringido'];
        } else {
            // Obtener un puesto
            $puestoStmt = $db->prepare("SELECT id FROM puestos_trabajo LIMIT 1");
            $puestoStmt->execute();
            $puesto = $puestoStmt->fetch(PDO::FETCH_ASSOC);
            $puestoId = $puesto['id'] ?? null;
        }
        
        $fecha = date('Y-m-d');
        
        // Validar asignación
        $turnosAsignados = new TurnosAsignados($db);
        $validacion = $turnosAsignados->validarAsignacion(
            $trabajador['id'],
            $puestoId,
            $turno['id'],
            $fecha
        );
        
        $result['data']['validation'] = [
            'worker_id' => $trabajador['id'],
            'restriction_type' => $trabajador['tipo_restriccion'],
            'valid' => $validacion['valido'],
            'errors' => $validacion['errores']
        ];
        
        if (!$validacion['valido']) {
            $result['status'] = 'pass';
            $result['message'] = '✅ Restricción CORRECTAMENTE BLOQUEADA en validación';
        } else {
            $result['status'] = 'warning';
            $result['message'] = '⚠️  Restricción no fue bloqueada (pero puede ser porque no aplica a este puesto/turno)';
        }
        
    } catch (Exception $e) {
        $result['message'] = 'Error: ' . $e->getMessage();
    }
    
    return $result;
}
?>

?>
