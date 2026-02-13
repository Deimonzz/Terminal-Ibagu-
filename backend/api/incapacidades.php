<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../clases/Incapacidades.php';

$incapacidades = new Incapacidades();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            if ($action === 'estadisticas') {
                $resultado = $incapacidades->obtenerEstadisticas($_GET['fecha_inicio'], $_GET['fecha_fin']);
            } elseif (!empty($_GET['id'])) {
                $resultado = $incapacidades->obtenerPorId($_GET['id']);
            } else {
                $filtros = [
                    'trabajador_id' => $_GET['trabajador_id'] ?? null,
                    'estado' => $_GET['estado'] ?? null,
                    'fecha' => $_GET['fecha'] ?? null,
                    'activas' => $_GET['activas'] ?? null
                ];
                $resultado = $incapacidades->obtenerIncapacidades($filtros);
            }

            echo json_encode(['success' => true, 'data' => $resultado]);
            break;

        case 'POST':
            $datos = json_decode(file_get_contents('php://input'), true);
            if ($action === 'prorrogar') {
                $resultado = $incapacidades->porrogar($_GET['id'], $datos['nueva_fecha_fin']);
            } else {
                $resultado = $incapacidades->crear($datos);
            }
            echo json_encode($resultado);
            break;

        case 'PUT':
            $datos = json_decode(file_get_contents('php://input'), true);
            if (!empty($datos['id'])) {
                $resultado = $incapacidades->actualizar($datos['id'], $datos);
            } else {
                $resultado = ['success' => false, 'message' => 'ID no proporcionado'];
            }
            echo json_encode($resultado);
            break;
        
        default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Metodo no permitido']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error del servidor:' . $e->getMessage()]);
}
?>