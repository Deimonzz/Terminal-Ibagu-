<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../clases/DiasEspeciales.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
ob_start();

$dias = new DiasEspeciales();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            // Actualizar estados automáticamente antes de devolver datos
            $dias->actualizarEstados();

            if ($action === 'disponibles'){
                $resultado = $dias->obtenerDisponibles($_GET['fecha'], $_GET['horario'] ?? null);
            } else {
                $filtros = [
                    'fecha' => $_GET['fecha'] ?? null,
                    'tipo' => $_GET['tipo'] ?? null,
                    'mes' => $_GET['mes'] ?? null,
                    'trabajador_id' => $_GET['trabajador_id'] ?? null,
                    'fecha_inicio' => $_GET['fecha_inicio'] ?? null,
                    'fecha_fin'    => $_GET['fecha_fin'] ?? null
                    ];
                    $resultado = $dias->obtener($filtros);
            }
            echo json_encode(['success' => true, 'data' => $resultado]);
            break;

        case 'POST':
            $datos = json_decode(file_get_contents('php://input'), true);
            $action_post = $datos['action'] ?? '';

            if ($action_post === 'eliminar') {
                // Eliminar por id, o por trabajador_id+fecha+tipo (alternativa a DELETE)
                if (!empty($datos['id'])) {
                    $resultado = $dias->eliminar($datos['id']);
                } elseif (!empty($datos['trabajador_id']) && !empty($datos['fecha'])) {
                    $resultado = $dias->eliminarPorFecha(
                        $datos['trabajador_id'],
                        $datos['fecha'],
                        $datos['tipo'] ?? null
                    );
                } else {
                    $resultado = ['success' => false, 'message' => 'Parámetros insuficientes'];
                }
            } else {
                $resultado = $dias->crear($datos);
            }
            echo json_encode($resultado);
            break;
        
        case 'PUT':
            $datos = json_decode(file_get_contents('php://input'), true);
            $resultado = $dias->actualizar($_GET['id'], $datos);
            echo json_encode($resultado);
            break;

        case 'DELETE':
            // Eliminar por id, o por trabajador_id+fecha+tipo
            if (!empty($_GET['id'])) {
                $resultado = $dias->eliminar($_GET['id']);
            } elseif (!empty($_GET['trabajador_id']) && !empty($_GET['fecha'])) {
                $resultado = $dias->eliminarPorFecha(
                    $_GET['trabajador_id'],
                    $_GET['fecha'],
                    $_GET['tipo'] ?? null
                );
            } else {
                $resultado = ['success' => false, 'message' => 'Parámetros insuficientes'];
            }
            echo json_encode($resultado);
            break;

        default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Metodo no permitido']);    
    }
} catch (Exception $e){
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
}
?>