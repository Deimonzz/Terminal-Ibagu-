<?php
require_once dirname(dirname(__DIR__)) . '/config/database.php';
require_once __DIR__ . '/../clases/TurnosAsignados.php';

$turnos = new TurnosAsignados();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            if ($action === 'calendario') {
                $resultado = $turnos->obtenerCalendario(
                    $_GET['mes'],
                    $_GET['anio'],
                    $_GET['area'] ?? null
                );
            } elseif ($action === 'estadisticas') {
                $resultado = $turnos->obtenerEstadisticas(
                    $_GET['fecha_inicio'],
                    $_GET['fecha_fin']
                );
            } elseif (!empty($_GET['id'])) {
                $resultado = $turnos->obtenerPorId($_GET['id']);
            } else {
                $filtros = [
                    'fecha' => $_GET['fecha'] ?? null,
                    'fecha_inicio' => $_GET['fecha_inicio'] ?? null,
                    'fecha_fin' => $_GET['fecha_fin'] ?? null,
                    'trabajador_id' => $_GET['trabajador_id'] ?? null,
                    'area' => $_GET['area'] ?? null,
                    'turno' => $_GET['turno'] ?? null,
                    'estado' => $_GET['estado'] ?? null
                ];
                $resultado = $turnos->obtenerTurnos($filtros);
            }
            
            echo json_encode([
                'success' => true,
                'data' => $resultado
            ]);
            break;
            
        case 'POST':
            $input = file_get_contents('php://input');
            $datos = json_decode($input, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON inválido: ' . json_last_error_msg());
            }
            
            if ($action === 'validar') {
                $resultado = $turnos->validarAsignacion(
                    $datos['trabajador_id'],
                    $datos['puesto_trabajo_id'],
                    $datos['turno_id'],
                    $datos['fecha']
                );
                echo json_encode([
                    'success' => true,
                    'data' => $resultado
                ]);
            } elseif ($action === 'masivo') {
                $resultado = $turnos->asignarMasivo($datos['asignaciones']);
                echo json_encode($resultado);
            } else {
                $resultado = $turnos->asignar($datos);
                echo json_encode($resultado);
            }
            break;
            
        case 'PUT':
            $input = file_get_contents('php://input');
            $datos = json_decode($input, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON inválido: ' . json_last_error_msg());
            }
            
            if ($action === 'cancelar') {
                $resultado = $turnos->cancelar(
                    $_GET['id'],
                    $datos['motivo'] ?? null,
                    $datos['usuario_id'] ?? null
                );
            } else {
                $resultado = $turnos->actualizar($_GET['id'], $datos);
            }
            
            echo json_encode($resultado);
            break;
            
        case 'DELETE':
            $resultado = $turnos->cancelar($_GET['id'], 'Eliminado');
            echo json_encode($resultado);
            break;
            
        default:
            http_response_code(405);
            echo json_encode([
                'success' => false,
                'message' => 'Método no permitido'
            ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error del servidor: ' . $e->getMessage()
    ]);
}
?>