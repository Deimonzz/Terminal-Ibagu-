<?php
// Headers PRIMERO - antes de cualquier salida
header('Content-Type: application/json; charset=utf-8');
http_response_code(500); // Default a 500, se cambia a 200 si todo va bien

ini_set('display_errors', '0');
error_reporting(0);
@set_time_limit(0);

// Setup de logging
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/asignacion_errors.log';

// Funciones de error ANTES de cualquier require
function logError($msg) {
    global $logFile;
    error_log('[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", 3, $logFile);
}

function sendJson($success, $message = '', $data = []) {
    $response = array_merge(['success' => $success, 'message' => $message], $data);
    if ($success) http_response_code(200);
    echo json_encode($response);
    exit;
}

// Limpiar output buffer
ob_start();

// Try-catch para TODOS los requires
try {
    if (!file_exists(dirname(dirname(__DIR__)) . '/config/database.php')) {
        throw new Exception('database.php not found');
    }
    require_once dirname(dirname(__DIR__)) . '/config/database.php';
    
    if (!file_exists(__DIR__ . '/../clases/AsignacionAutomatica.php')) {
        throw new Exception('AsignacionAutomatica.php not found');
    }
    require_once __DIR__ . '/../clases/AsignacionAutomatica.php';
    
    if (!class_exists('AsignacionAutomatica')) {
        throw new Exception('AsignacionAutomatica class not found after require');
    }
    
} catch (Throwable $e) {
    ob_end_clean();
    logError('Failed to load dependencies: ' . $e->getMessage());
    sendJson(false, 'Error al cargar dependencias: ' . $e->getMessage());
}

// Inicializar la clase
try {
    $asignacion = new AsignacionAutomatica();
} catch (Throwable $e) {
    ob_end_clean();
    logError('Failed to instantiate AsignacionAutomatica: ' . $e->getMessage());
    sendJson(false, 'Error al inicializar: ' . $e->getMessage());
}

// Test básico
if (isset($_GET['test'])) {
    try {
        $test = $asignacion->testConnection();
        ob_end_clean();
        sendJson(true, 'Connection OK', ['test' => $test]);
    } catch (Throwable $e) {
        ob_end_clean();
        logError('Test failed: ' . $e->getMessage());
        sendJson(false, 'Error de conexión: ' . $e->getMessage());
    }
}

// Procesar solicitud
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'POST') {
        $rawInput = file_get_contents('php://input');
        $datos = json_decode($rawInput, true);
        
        if (!$datos) {
            ob_end_clean();
            sendJson(false, 'JSON inválido en POST');
        }
        
        $action = $datos['action'] ?? '';

        if ($action === 'deshacer_mes') {
            $mes  = intval($datos['mes']  ?? 0);
            $anio = intval($datos['anio'] ?? 0);
            if (!$mes || !$anio) {
                ob_end_clean();
                sendJson(false, 'Mes y año requeridos');
            }
            
            try {
                $db = Database::getInstance()->getConnection();
                $db->beginTransaction();
                
                $fechaInicio = sprintf('%04d-%02d-01', $anio, $mes);
                $fechaFin    = date('Y-m-t', strtotime($fechaInicio));
                
                $stmtT = $db->prepare("DELETE FROM turnos_asignados WHERE fecha BETWEEN :fi AND :ff AND estado IN ('programado','activo','cancelado','no_presentado')");
                $stmtT->execute([':fi' => $fechaInicio, ':ff' => $fechaFin]);
                $turnosEliminados = $stmtT->rowCount();
                
                $stmtL = $db->prepare("DELETE FROM dias_especiales WHERE fecha_inicio BETWEEN :fi AND :ff AND tipo IN ('L','L8','LC','VAC','SUS','ADMM','ADMT','ADM')");
                $stmtL->execute([':fi' => $fechaInicio, ':ff' => $fechaFin]);
                $libresEliminados = $stmtL->rowCount();
                
                $db->commit();
                ob_end_clean();
                sendJson(true, 'Mes deshecho correctamente', [
                    'turnos_eliminados' => $turnosEliminados,
                    'libres_eliminados' => $libresEliminados
                ]);
            } catch (Throwable $e) {
                if (isset($db)) $db->rollback();
                ob_end_clean();
                logError('Undo failed: ' . $e->getMessage());
                sendJson(false, 'Error al deshacer: ' . $e->getMessage());
            }
        } else {
            // Asignar mes completo
            $mes = intval($datos['mes'] ?? 0);
            $anio = intval($datos['anio'] ?? 0);
            
            if (!$mes || !$anio) {
                ob_end_clean();
                sendJson(false, 'Mes y año requeridos');
            }
            
            try {
                $resultado = $asignacion->asignarMesCompleto($mes, $anio, $datos['opciones'] ?? []);
                ob_end_clean();
                echo json_encode($resultado);
            } catch (Throwable $e) {
                ob_end_clean();
                logError('Assignment failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
                sendJson(false, 'Error en asignación: ' . $e->getMessage());
            }
        }
        
    } elseif ($method === 'DELETE') {
        $rawInput = file_get_contents('php://input');
        $datos = json_decode($rawInput, true);
        
        if (!$datos) {
            ob_end_clean();
            sendJson(false, 'JSON inválido en DELETE');
        }
        
        $mes   = intval($datos['mes']  ?? 0);
        $anio  = intval($datos['anio'] ?? 0);

        if (!$mes || !$anio) {
            ob_end_clean();
            sendJson(false, 'Mes y año requeridos');
        }

        try {
            $db = Database::getInstance()->getConnection();
            $db->beginTransaction();

            $fechaInicio = sprintf('%04d-%02d-01', $anio, $mes);
            $fechaFin    = date('Y-m-t', strtotime($fechaInicio));

            $sqlT = "DELETE FROM turnos_asignados WHERE fecha BETWEEN :fi AND :ff AND estado IN ('programado','activo','cancelado','no_presentado')";
            $stmtT = $db->prepare($sqlT);
            $stmtT->execute([':fi' => $fechaInicio, ':ff' => $fechaFin]);
            $turnosEliminados = $stmtT->rowCount();

            $sqlL = "DELETE FROM dias_especiales WHERE fecha_inicio BETWEEN :fi AND :ff AND tipo IN ('L','L8','LC','VAC','SUS','ADMM','ADMT','ADM')";
            $stmtL = $db->prepare($sqlL);
            $stmtL->execute([':fi' => $fechaInicio, ':ff' => $fechaFin]);
            $libresEliminados = $stmtL->rowCount();

            $db->commit();
            ob_end_clean();
            sendJson(true, 'Mes deshecho correctamente', [
                'turnos_eliminados' => $turnosEliminados,
                'libres_eliminados' => $libresEliminados
            ]);
        } catch (Throwable $e) {
            if (isset($db)) $db->rollback();
            ob_end_clean();
            logError('Delete failed: ' . $e->getMessage());
            sendJson(false, 'Error al eliminar: ' . $e->getMessage());
        }

    } else {
        ob_end_clean();
        sendJson(false, 'Metodo no permitido');
    }
    
} catch (Throwable $e) {
    ob_end_clean();
    logError('Unexpected error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    sendJson(false, 'Error inesperado: ' . $e->getMessage());
}
?>