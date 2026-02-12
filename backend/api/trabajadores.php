<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../clases/Trabajadores.php';

$trabajadores = new Trabajadores();
$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['path'] ?? '';

try {
    switch ($method) {
        case 'GET':
            if (!empty($_GET['id'])) {
                $resultado = $trabajadores->obtenerPorId($_GET['id']);
            } elseif (!empty($_GET['disponibles'])) {
                $resultado = $trabajadores->obtenerDisponibles($_GET['puesto_id'], $_GET['turno_id'], $_GET['fecha']);
            } else {
                $filtros = [
                    'area' => $_GET['area'] ?? null,
                    'search' => $_GET['search'] ?? null,
                    'incluir_inactivos' => isset($_GET['incluir_inactivos']) ? boolval($_GET['incluir_inactivos']) : false
                ];
                $resultado = $trabajadores->obtenerTodos($filtros);
            }
            echo json_encode([
                'success' => true,
                'data' => $resultado
            ]);
            break;

        case 'POST':
            $datos = json_decode(file_get_contents('php://input'), true);

            if ($path === 'restriccion') {
                $resultado = $trabajadores->agregarRestriccion($datos);
            } else {
                $resultado = $trabajadores->crear($datos);
            }

            echo json_encode($resultado);
            break;

        case 'PUT':
            $datos = json_decode(file_get_contents('php://input'), true);
            $id = $_GET['id'] ?? null;
            $accion = $_GET['accion'] ?? null;
            
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'ID requerido']);
                break;
            }
            
            if ($accion === 'desactivar') {
                $resultado = $trabajadores->desactivar($id);
            } elseif ($accion === 'activar') {
                $resultado = $trabajadores->activar($id);
            } else {
                $resultado = $trabajadores->actualizar($id, $datos);
            }
            
            echo json_encode($resultado);
            break;
        
        case 'DELETE':
            if ($path === 'restriccion'){
                $resultado = $trabajadores->eliminarRestriccion($_GET['id']);
            } else {
                $resultado = $trabajadores->desactivar($_GET['id']);
            }

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