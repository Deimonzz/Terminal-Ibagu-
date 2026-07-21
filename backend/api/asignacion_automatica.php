<?php
// Headers PRIMERO - antes de cualquier salida
header('Content-Type: application/json; charset=utf-8');
header('Connection: close');

ini_set('display_errors', '0');
error_reporting(0);
// Tope duro de 110s para que PHP aborte el script y libere el lock si el cliente se cuelga.
@ini_set('max_execution_time', '110');
@ini_set('memory_limit', '512M');
@ini_set('default_socket_timeout', '120');
@set_time_limit(110);
ignore_user_abort(false);

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
    http_response_code(200);
    echo json_encode($response);
    exit;
}

function getAssignmentStatePath($mes, $anio) {
    global $logDir;
    return $logDir . '/asignacion_' . intval($anio) . '_' . intval($mes) . '.state';
}

function acquireMonthLock($mes, $anio) {
    global $logDir;
    $lockPath = $logDir . '/asignacion_' . intval($anio) . '_' . intval($mes) . '.lock';
    $handle = @fopen($lockPath, 'c');
    if (!$handle) {
        throw new Exception('No se pudo crear el candado de asignación');
    }

    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return [null, $lockPath, null];
    }

    ftruncate($handle, 0);
    fwrite($handle, getmypid() . '|' . date('c'));
    fflush($handle);

    $statePath = getAssignmentStatePath($mes, $anio);
    $stateData = [
        'pid' => getmypid(),
        'started_at' => date('c'),
        'status' => 'running',
        'cancel_requested' => false,
    ];
    file_put_contents($statePath, json_encode($stateData));

    return [$handle, $lockPath, $statePath];
}

function releaseMonthLock($handle, $lockPath = null, $statePath = null) {
    if (is_resource($handle)) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
    if ($lockPath && file_exists($lockPath)) {
        @unlink($lockPath);
    }
    if ($statePath && file_exists($statePath)) {
        @unlink($statePath);
    }
}

function registerAssignmentCleanup($handle, $lockPath = null, $statePath = null) {
    if (!is_resource($handle) && !$lockPath && !$statePath) {
        return;
    }

    register_shutdown_function(function() use ($handle, $lockPath, $statePath) {
        releaseMonthLock($handle, $lockPath, $statePath);
    });
}

function requestCancelAssignment($mes, $anio) {
    $statePath = getAssignmentStatePath($mes, $anio);
    if (!file_exists($statePath)) {
        return false;
    }

    $state = json_decode(file_get_contents($statePath), true);
    if (!is_array($state)) {
        return false;
    }

    $state['cancel_requested'] = true;
    $state['status'] = 'cancelling';
    file_put_contents($statePath, json_encode($state));
    return true;
}

function shouldCancelAssignment($mes, $anio) {
    $statePath = getAssignmentStatePath($mes, $anio);
    if (!file_exists($statePath)) {
        return false;
    }

    $state = json_decode(file_get_contents($statePath), true);
    if (!is_array($state)) {
        return false;
    }

    return !empty($state['cancel_requested']);
}

function deleteRowsInBatches(PDO $db, $table, $whereSql, array $params, $batchSize = 250, $maxRetries = 3) {
    $total = 0;
    $batchSize = max(1, (int)$batchSize);
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    if ($table === '') {
        throw new Exception('Tabla inválida para borrado por lotes');
    }

    while (true) {
        $ids = [];
        $selectSql = "SELECT id FROM {$table} WHERE {$whereSql} ORDER BY id ASC LIMIT {$batchSize}";
        $stmtIds = $db->prepare($selectSql);
        $stmtIds->execute($params);
        $ids = $stmtIds->fetchAll(PDO::FETCH_COLUMN);

        if (empty($ids)) {
            break;
        }

        $attempt = 0;
        while (true) {
            try {
                $db->beginTransaction();
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                // Usar :id0, :id1, ... para no mezclar named y posicional con el WHERE externo.
                $namedIdPlaceholders = [];
                $idParams = [];
                foreach (array_values($ids) as $idx => $idValue) {
                    $key = ':id_' . $idx;
                    $namedIdPlaceholders[] = $key;
                    $idParams[$key] = $idValue;
                }
                $namedIdList = implode(',', $namedIdPlaceholders);
                $stmtDel = $db->prepare("DELETE FROM {$table} WHERE id IN ({$namedIdList})");
                $stmtDel->execute($idParams);
                $total += $stmtDel->rowCount();
                $db->commit();
                break;
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $attempt++;
                if ($attempt >= $maxRetries) {
                    throw $e;
                }
                usleep(200000);
            }
        }
    }

    return $total;
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

        if ($action === 'cancelar_asignacion') {
            $mes  = intval($datos['mes']  ?? 0);
            $anio = intval($datos['anio'] ?? 0);
            if (!$mes || !$anio) {
                ob_end_clean();
                sendJson(false, 'Mes y año requeridos');
            }

            $cancelado = requestCancelAssignment($mes, $anio);
            ob_end_clean();
            sendJson(true, 'Cancelación solicitada', ['cancelado' => $cancelado]);
        } elseif ($action === 'deshacer_mes') {
            $mes  = intval($datos['mes']  ?? 0);
            $anio = intval($datos['anio'] ?? 0);
            if (!$mes || !$anio) {
                ob_end_clean();
                sendJson(false, 'Mes y año requeridos');
            }
            
            try {
                [$lockHandle, $lockPath, $statePath] = acquireMonthLock($mes, $anio);
                if (!$lockHandle) {
                    ob_end_clean();
                    sendJson(false, 'Hay otra operación de asignación/deshacer en curso para ese mes. Intenta nuevamente en unos segundos.');
                }

                registerAssignmentCleanup($lockHandle, $lockPath, $statePath);
                $db = Database::getInstance()->getConnection();
                
                $fechaInicio = sprintf('%04d-%02d-01', $anio, $mes);
                $fechaFin    = date('Y-m-t', strtotime($fechaInicio));

                $whereTurnos = "fecha BETWEEN :fi AND :ff AND estado IN ('programado','activo','cancelado','no_presentado')";
                $turnosEliminados = deleteRowsInBatches($db, 'turnos_asignados', $whereTurnos, [':fi' => $fechaInicio, ':ff' => $fechaFin], 200, 4);

                $whereLibres = "fecha_inicio BETWEEN :fi AND :ff AND tipo IN ('L','L8','LC','VAC','SUS','ADMM','ADMT','ADM')";
                $libresEliminados = deleteRowsInBatches($db, 'dias_especiales', $whereLibres, [':fi' => $fechaInicio, ':ff' => $fechaFin], 200, 4);

                releaseMonthLock($lockHandle, $lockPath, $statePath);
                ob_end_clean();
                sendJson(true, 'Mes deshecho correctamente', [
                    'turnos_eliminados' => $turnosEliminados,
                    'libres_eliminados' => $libresEliminados
                ]);
            } catch (Throwable $e) {
                if (isset($lockHandle)) {
                    releaseMonthLock($lockHandle, $lockPath ?? null, $statePath ?? null);
                }
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

            [$lockHandle, $lockPath, $statePath] = acquireMonthLock($mes, $anio);
            if (!$lockHandle) {
                ob_end_clean();
                sendJson(false, 'Ya hay una asignación automática ejecutándose para ese mes. Espera a que termine antes de volver a intentar.');
            }
            
            try {
                registerAssignmentCleanup($lockHandle, $lockPath, $statePath);
                $resultado = $asignacion->asignarMesCompleto($mes, $anio, $datos['opciones'] ?? []);
                releaseMonthLock($lockHandle, $lockPath, $statePath);
                ob_end_clean();
                http_response_code(200);
                echo json_encode($resultado);
                exit;
            } catch (Throwable $e) {
                releaseMonthLock($lockHandle, $lockPath, $statePath ?? null);
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

            $fechaInicio = sprintf('%04d-%02d-01', $anio, $mes);
            $fechaFin    = date('Y-m-t', strtotime($fechaInicio));
            [$lockHandle, $lockPath, $statePath] = acquireMonthLock($mes, $anio);
            if (!$lockHandle) {
                ob_end_clean();
                sendJson(false, 'Hay otra operación de asignación/deshacer en curso para ese mes. Intenta nuevamente en unos segundos.');
            }

            registerAssignmentCleanup($lockHandle, $lockPath, $statePath);

            $turnosEliminados = deleteRowsInBatches(
                $db,
                'turnos_asignados',
                "fecha BETWEEN :fi AND :ff AND estado IN ('programado','activo','cancelado','no_presentado')",
                [':fi' => $fechaInicio, ':ff' => $fechaFin],
                200,
                4
            );

            $libresEliminados = deleteRowsInBatches(
                $db,
                'dias_especiales',
                "fecha_inicio BETWEEN :fi AND :ff AND tipo IN ('L','L8','LC','VAC','SUS','ADMM','ADMT','ADM')",
                [':fi' => $fechaInicio, ':ff' => $fechaFin],
                200,
                4
            );

            releaseMonthLock($lockHandle, $lockPath, $statePath);
            ob_end_clean();
            sendJson(true, 'Mes deshecho correctamente', [
                'turnos_eliminados' => $turnosEliminados,
                'libres_eliminados' => $libresEliminados
            ]);
        } catch (Throwable $e) {
            if (isset($lockHandle)) {
                releaseMonthLock($lockHandle, $lockPath ?? null, $statePath ?? null);
            }
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