<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../clases/CambiosTurno.php';

$cambios = new CambiosTurno();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            if ($action === 'pendientes'){
                $resultado = $cambios->obtenerPendientes($_GET['trabajador_id'] ?? null);
            } elseif (!empty($_GET['id'])){
                $resultado = $cambios->obtenerPorId($_GET['id']);
            } else {
                $filtros = [
                    'estado' => $_GET['estado'] ?? null,
                    'trababajador_id' => $_GET['trabajador_id'] ?? null,
                    'fecha' => $_GET['fecha'] ?? null
                ];
                $resultado = $cambios->obtenerCambios($filtros);
            }
            echo json_decode(['success' => true, 'data' => $resultado]);
            break;

        case 'POST':
            $datos = json_decode(file_get_contents('php://input'), true);
            if ($action === 'aprobar'){
                $resultado = $cambios->aprobar($_GET['id'], $datos['aprobado_por']);
            } elseif ($action === 'rechazar'){
                $resultado = $cambios->rechazar($_GET['id'], $datos['motivo'] ?? null);
            } else {
                $resultado = $cambios->solicitar($datos);
            }
            echo json_encode($resultado);
            break;

        default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Metodo no permitido']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error del servidor: '  . $e->getMessage()]);
}
?>