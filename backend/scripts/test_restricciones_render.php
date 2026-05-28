<?php
/**
 * Test de restricciones en Render PostgreSQL
 * Verificar que la validación de puesto_especifico funciona correctamente
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
    'tests' => [
        'test_1_restricciones_table' => test_restricciones_table($db),
        'test_2_puesto_especifico_validation' => test_puesto_especifico_validation($db),
        'test_3_asignacion_con_restriccion' => test_asignacion_con_restriccion($db),
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

function test_restricciones_table($db) {
    $result = ['status' => 'fail', 'message' => '', 'data' => []];
    
    try {
        // 1. Verificar que la tabla existe
        $sql = "SELECT * FROM restricciones_trabajador LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $result['data']['table_exists'] = true;
        
        // 2. Obtener estructura
        $columns = [];
        $descSql = "SELECT column_name, data_type FROM information_schema.columns 
                   WHERE table_name = 'restricciones_trabajador' AND table_schema = 'public'";
        try {
            $descStmt = $db->prepare($descSql);
            $descStmt->execute();
            $columns = $descStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // Fallback para MySQL
            $descSql = "DESCRIBE restricciones_trabajador";
            $descStmt = $db->prepare($descSql);
            $descStmt->execute();
            $columns = $descStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $result['data']['columns'] = $columns;
        $result['data']['has_puesto_trabajo_id'] = in_array('puesto_trabajo_id', array_column($columns, 'column_name'));
        $result['data']['has_puesto_id'] = in_array('puesto_id', array_column($columns, 'column_name'));
        
        // 3. Contar restricciones
        $countSql = "SELECT COUNT(*) as cnt FROM restricciones_trabajador WHERE activa = true";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute();
        $count = $countStmt->fetch(PDO::FETCH_ASSOC);
        $result['data']['active_restrictions_count'] = $count['cnt'];
        
        // 4. Obtener restricciones puesto_especifico
        $puestoCol = Database::getColumnName('restricciones_trabajador', 'puesto_trabajo_id', 'puesto_id');
        $sql = "SELECT id, trabajador_id, tipo_restriccion, " . ($puestoCol ? $puestoCol : "NULL") . " as puesto_id_actual
                FROM restricciones_trabajador 
                WHERE tipo_restriccion = 'puesto_especifico' AND activa = true
                LIMIT 5";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $result['data']['sample_puesto_especifico'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result['status'] = 'pass';
        $result['message'] = 'Tabla de restricciones verificada';
    } catch (Exception $e) {
        $result['message'] = $e->getMessage();
    }
    
    return $result;
}

function test_puesto_especifico_validation($db) {
    $result = ['status' => 'fail', 'message' => '', 'data' => []];
    
    try {
        // Obtener un trabajador con restricción puesto_especifico
        $puestoCol = Database::getColumnName('restricciones_trabajador', 'puesto_trabajo_id', 'puesto_id');
        
        $sql = "SELECT DISTINCT t.id, t.nombre, r." . ($puestoCol ? $puestoCol : "NULL") . " as puesto_id_actual
                FROM trabajadores t
                INNER JOIN restricciones_trabajador r ON t.id = r.trabajador_id
                WHERE r.tipo_restriccion = 'puesto_especifico' 
                AND r.activa = true
                LIMIT 1";
        
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $trabajador = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$trabajador) {
            $result['message'] = 'No hay trabajadores con restricción puesto_especifico activa';
            return $result;
        }
        
        $result['data']['test_worker_id'] = $trabajador['id'];
        $result['data']['test_worker_name'] = $trabajador['nombre'];
        $result['data']['test_worker_restricted_puesto_id'] = $trabajador['puesto_id_actual'];
        
        // Intentar validación usando método estático de Database
        $validaSql = "SELECT COUNT(*) as cnt FROM restricciones_trabajador
                      WHERE trabajador_id = ? 
                      AND tipo_restriccion = 'puesto_especifico'
                      AND activa = true
                      AND " . ($puestoCol ? $puestoCol : "NULL") . " = ?
                      AND fecha_inicio <= CURRENT_DATE
                      AND (fecha_fin IS NULL OR fecha_fin >= CURRENT_DATE)";
        
        $validaStmt = $db->prepare($validaSql);
        $validaStmt->execute([$trabajador['id'], $trabajador['puesto_id_actual']]);
        $validaResult = $validaStmt->fetch(PDO::FETCH_ASSOC);
        
        $result['data']['validation_result'] = $validaResult;
        $result['data']['restriction_found'] = $validaResult['cnt'] > 0;
        
        $result['status'] = 'pass';
        $result['message'] = 'Validación de restricción ' . ($validaResult['cnt'] > 0 ? 'SÍ ENCONTRADA' : 'NO encontrada');
    } catch (Exception $e) {
        $result['message'] = 'Error: ' . $e->getMessage();
    }
    
    return $result;
}

function test_asignacion_con_restriccion($db) {
    $result = ['status' => 'fail', 'message' => '', 'data' => []];
    
    try {
        // Obtener trabajador con restricción
        $puestoCol = Database::getColumnName('restricciones_trabajador', 'puesto_trabajo_id', 'puesto_id');
        
        $sql = "SELECT DISTINCT t.id, t.nombre, r." . ($puestoCol ? $puestoCol : "NULL") . " as puesto_restringido
                FROM trabajadores t
                INNER JOIN restricciones_trabajador r ON t.id = r.trabajador_id
                WHERE r.tipo_restriccion = 'puesto_especifico' 
                AND r.activa = true
                LIMIT 1";
        
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $trabajador = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$trabajador) {
            $result['message'] = 'No hay trabajadores con restricción';
            return $result;
        }
        
        // Obtener un turno y una fecha
        $turnoStmt = $db->prepare("SELECT id FROM configuracion_turnos LIMIT 1");
        $turnoStmt->execute();
        $turno = $turnoStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$turno) {
            $result['message'] = 'No hay turnos en la BD';
            return $result;
        }
        
        $fecha = date('Y-m-d');
        
        // Intentar asignación usando TurnosAsignados
        $turnosAsignados = new TurnosAsignados($db);
        $validacion = $turnosAsignados->validarAsignacion(
            $trabajador['id'],
            $trabajador['puesto_restringido'],
            $turno['id'],
            $fecha
        );
        
        $result['data']['test_worker_id'] = $trabajador['id'];
        $result['data']['test_worker_name'] = $trabajador['nombre'];
        $result['data']['test_puesto_id'] = $trabajador['puesto_restringido'];
        $result['data']['validation_result'] = $validacion;
        
        if (!$validacion['valido']) {
            $result['status'] = 'pass';
            $result['message'] = '✅ Restricción CORRECTAMENTE BLOQUEADA';
        } else {
            $result['status'] = 'fail';
            $result['message'] = '❌ RESTRICCIÓN NO FUE BLOQUEADA - PROBLEMA ENCONTRADO';
        }
        
    } catch (Exception $e) {
        $result['message'] = 'Error: ' . $e->getMessage();
    }
    
    return $result;
}
?>
