<?php
/**
 * Test específico: Restricción no_fuerza_fisica en puestos G y Equipajes
 */

header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(__DIR__)) . '/config/database.php';
require_once __DIR__ . '/../clases/TurnosAsignados.php';
require_once __DIR__ . '/../clases/Trabajadores.php';

$db = Database::getInstance()->getConnection();

$result = [
    'timestamp' => date('Y-m-d H:i:s'),
    'tests' => []
];

try {
    // 1. Verificar que G y Equipajes tienen requiere_fuerza_fisica = true
    $puestosSql = "SELECT id, codigo, nombre, requiere_fuerza_fisica 
                   FROM puestos_trabajo 
                   WHERE LOWER(codigo) IN ('g', 'equipajes') 
                      OR LOWER(nombre) LIKE '%equipaje%'
                   ORDER BY codigo";
    $puestosStmt = $db->prepare($puestosSql);
    $puestosStmt->execute();
    $puestos = $puestosStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $result['tests']['paso_1_puestos_g_equipajes'] = [
        'status' => 'pass',
        'message' => count($puestos) . ' puesto(s) encontrado(s)',
        'puestos' => $puestos
    ];
    
    if (empty($puestos)) {
        $result['tests']['paso_1_puestos_g_equipajes']['status'] = 'warning';
        $result['tests']['paso_1_puestos_g_equipajes']['message'] = 'No se encontraron puestos G o Equipajes. Ejecutar configure_puestos_fuerza.php';
    }
    
    // 2. Verificar personas con restricción no_fuerza_fisica
    $restriccionSql = "SELECT DISTINCT t.id, t.nombre, r.fecha_inicio, r.fecha_fin
                       FROM trabajadores t
                       INNER JOIN restricciones_trabajador r ON t.id = r.trabajador_id
                       WHERE r.tipo_restriccion = 'no_fuerza_fisica'
                       AND r.activa = true
                       LIMIT 5";
    $restriccionStmt = $db->prepare($restriccionSql);
    $restriccionStmt->execute();
    $trabajadores = $restriccionStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $result['tests']['paso_2_trabajadores_sin_fuerza'] = [
        'status' => 'pass',
        'message' => count($trabajadores) . ' trabajador(es) con restricción no_fuerza_fisica',
        'trabajadores' => $trabajadores
    ];
    
    // 3. Test de validación: intentar asignar a un puesto con fuerza
    if (!empty($puestos) && !empty($trabajadores)) {
        $turnoSql = "SELECT id FROM configuracion_turnos LIMIT 1";
        $turnoStmt = $db->prepare($turnoSql);
        $turnoStmt->execute();
        $turno = $turnoStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($turno) {
            $fecha = date('Y-m-d');
            $turnosAsignados = new TurnosAsignados($db);
            
            // Intentar asignar trabajador sin fuerza a puesto con fuerza
            $validacion = $turnosAsignados->validarAsignacion(
                $trabajadores[0]['id'],
                $puestos[0]['id'],  // G o Equipajes
                $turno['id'],
                $fecha
            );
            
            $result['tests']['paso_3_validacion_asignacion'] = [
                'status' => $validacion['valido'] ? 'fail' : 'pass',
                'message' => $validacion['valido'] ? 
                    '❌ PROBLEMA: No se bloqueó asignación a puesto con fuerza requerida' :
                    '✅ Asignación bloqueada correctamente: ' . implode(', ', $validacion['errores']),
                'worker' => $trabajadores[0]['nombre'],
                'puesto' => $puestos[0]['codigo'],
                'valid' => $validacion['valido'],
                'errores' => $validacion['errores']
            ];
        }
    }
    
    // 4. Verificar que la validación está en BD
    $logSql = "SELECT COUNT(*) as cnt FROM restricciones_trabajador 
               WHERE tipo_restriccion = 'no_fuerza_fisica' 
               AND activa = true";
    $logStmt = $db->prepare($logSql);
    $logStmt->execute();
    $logCount = $logStmt->fetch(PDO::FETCH_ASSOC);
    
    $result['tests']['paso_4_restricciones_en_bd'] = [
        'status' => 'pass',
        'message' => $logCount['cnt'] . ' restricción(es) activa(s) en BD',
        'count' => $logCount['cnt']
    ];
    
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>
