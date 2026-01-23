<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../clases/DiasEspeciales.php';

$dias = new DiasEspeciales();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            if ($action === 'disponibles'){
                $resultado = $dias->obtenerDisponibles($_GET['fecha'], $_GET['horario'] ?? null);
            } else {
                $filtros = [
                    'fecha' => $_GET['fecha'] ?? null,
                    'tipo' => $_GET['tipo'] ?? null,
                    'mes' => $_GET['mes'] ?? null,
                    'trabajador_id' => $_GET['trabajador_id'] ?? null
                    ];
                    $resultado = $dias->obtener($filtros);
            }
            echo json_encode(['success' => true, 'data' => $resultado]);
            break;

        case 'POST':
            $datos = json_decode(file_get_contents('php://input'), true);
            $resultado = $dias->crear($datos);
            echo json_encode($resultado);
            break;
        
        case 'PUT':
            $datos = json_decode(file_get_contents('php://input'), true);
            $resultado = $dias->actualizar($_GET['id'], $datos);
            echo json_encode($resultado);
            break;

        case 'DELETE':
            $resultado = $dias->eliminar($_GET['id']);
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