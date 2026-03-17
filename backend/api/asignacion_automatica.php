<?php
require_once dirname(dirname(__DIR__)) . '/config/database.php';
require_once __DIR__ . '/../clases/AsignacionAutomatica.php';

// Asegurar que el endpoint responde JSON y capturar errores
header('Content-Type: application/json; charset=utf-8');

// Registrar errores propios del endpoint
ini_set('display_errors', '0');
ini_set('log_errors', '1');
$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/asignacion_errors.log';

set_error_handler(function($severity, $message, $file, $line) use ($logFile) {
    $msg = date('[Y-m-d H:i:s] ') . "PHP Error: {$message} in {$file} on line {$line}\n";
    error_log($msg, 3, $logFile);
});

set_exception_handler(function($e) use ($logFile) {
    $msg = date('[Y-m-d H:i:s] ') . "Uncaught Exception: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n";
    error_log($msg, 3, $logFile);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
    exit;
});

// Evitar salidas accidentales antes de enviar JSON
ob_start();

function sendJsonAndExit($data, $status = 200) {
    if (ob_get_length() !== false) {
        @ob_end_clean();
    }
    http_response_code($status);
    echo json_encode($data);
    exit;
}

$asignacion = new AsignacionAutomatica();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'POST') {
        $datos = json_decode(file_get_contents('php://input'), true);
        $action = $datos['action'] ?? '';

        if ($action === 'deshacer_mes') {
            $mes  = intval($datos['mes']  ?? 0);
            $anio = intval($datos['anio'] ?? 0);
            if (!$mes || !$anio) {
                sendJsonAndExit(['success' => false, 'message' => 'Mes y año requeridos'], 400);
            }
            $db = Database::getInstance()->getConnection();
            $db->beginTransaction();
            try {
                $fechaInicio = sprintf('%04d-%02d-01', $anio, $mes);
                $fechaFin    = date('Y-m-t', strtotime($fechaInicio));
                $stmtT = $db->prepare("DELETE FROM turnos_asignados WHERE fecha BETWEEN :fi AND :ff AND estado IN ('programado','activo','cancelado','no_presentado')");
                $stmtT->execute([':fi' => $fechaInicio, ':ff' => $fechaFin]);
                $turnosEliminados = $stmtT->rowCount();
                $stmtL = $db->prepare("DELETE FROM dias_especiales WHERE fecha_inicio BETWEEN :fi AND :ff AND tipo IN ('L','L8','LC','VAC','SUS','ADMM','ADMT','ADM')");
                $stmtL->execute([':fi' => $fechaInicio, ':ff' => $fechaFin]);
                $libresEliminados = $stmtL->rowCount();
                $db->commit();
                sendJsonAndExit(['success' => true, 'turnos_eliminados' => $turnosEliminados, 'libres_eliminados' => $libresEliminados, 'message' => 'Mes deshecho correctamente']);
            } catch (Exception $e) {
                $db->rollback();
                sendJsonAndExit(['success' => false, 'message' => $e->getMessage()], 500);
            }
        } else {
            $resultado = $asignacion->asignarMesCompleto(
                $datos['mes'],
                $datos['anio'],
                $datos['opciones'] ?? []
            );
            sendJsonAndExit($resultado);
        }
    } elseif ($method === 'DELETE') {
        $datos = json_decode(file_get_contents('php://input'), true);
        $mes   = intval($datos['mes']  ?? 0);
        $anio  = intval($datos['anio'] ?? 0);

        if (!$mes || !$anio) {
            sendJsonAndExit(['success' => false, 'message' => 'Mes y año requeridos'], 400);
        }

        $db = Database::getInstance()->getConnection();
        $db->beginTransaction();

        try {
            // Eliminar turnos del mes
            $fechaInicio = sprintf('%04d-%02d-01', $anio, $mes);
            $fechaFin    = date('Y-m-t', strtotime($fechaInicio)); // último día del mes

            $sqlT = "DELETE FROM turnos_asignados
                     WHERE fecha BETWEEN :fi AND :ff
                     AND estado IN ('programado','activo','cancelado','no_presentado')";
            $stmtT = $db->prepare($sqlT);
            $stmtT->execute([':fi' => $fechaInicio, ':ff' => $fechaFin]);
            $turnosEliminados = $stmtT->rowCount();

            // Eliminar días libres automáticos del mes (descripcion empieza con AUTO:)
            $sqlL = "DELETE FROM dias_especiales
                     WHERE fecha_inicio BETWEEN :fi AND :ff
                     AND tipo IN ('L','L8','LC','VAC','SUS','ADMM','ADMT','ADM')";
            $stmtL = $db->prepare($sqlL);
            $stmtL->execute([':fi' => $fechaInicio, ':ff' => $fechaFin]);
            $libresEliminados = $stmtL->rowCount();

            $db->commit();

            sendJsonAndExit([
                'success'          => true,
                'turnos_eliminados' => $turnosEliminados,
                'libres_eliminados' => $libresEliminados,
                'message'          => 'Mes deshecho correctamente'
            ]);
        } catch (Exception $e) {
            $db->rollback();
            sendJsonAndExit(['success' => false, 'message' => $e->getMessage()], 500);
        }

    } else {
        sendJsonAndExit([
            'success' => false,
            'message' => 'Metodo no permitido'
        ], 405);
    }
} catch (Exception $e) {
    sendJsonAndExit([
        'success' => false,
        'message' => 'Error del servidor: ' . $e->getMessage()
    ], 500);
}
?>