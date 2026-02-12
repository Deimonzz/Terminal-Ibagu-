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

        $resultado = $asignacion->asignarMesCompleto(
            $datos['mes'],
            $datos['anio'],
            $datos['opciones'] ?? []
        );
        sendJsonAndExit($resultado);
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