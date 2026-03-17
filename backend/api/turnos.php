<?php
require_once dirname(dirname(__DIR__)) . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
ob_start();

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
            } elseif ($action === 'sin_libre_semana') {
                // Trabajadores sin día libre (L) en la semana de la fecha dada
                $fecha = $_GET['fecha'] ?? date('Y-m-d');
                // Calcular lunes y domingo de esa semana
                $ts = strtotime($fecha);
                $diaSemana = date('N', $ts); // 1=lun, 7=dom
                $lunes  = date('Y-m-d', strtotime('-' . ($diaSemana - 1) . ' days', $ts));
                $domingo = date('Y-m-d', strtotime('+' . (7 - $diaSemana) . ' days', $ts));

                $db = Database::getInstance()->getConnection();

                // Trabajadores activos
                $stmtT = $db->query("SELECT id, nombre, cedula FROM trabajadores WHERE activo = true ORDER BY nombre");
                $todos = $stmtT->fetchAll();

                // Quiénes tienen L (día libre) esa semana en dias_especiales
                $sqlL = "SELECT DISTINCT trabajador_id FROM dias_especiales
                         WHERE tipo IN ('L','L8','LC')
                         AND fecha_inicio BETWEEN :lunes AND :domingo
                         AND estado IN ('programado','activo')";
                $stmtL = $db->prepare($sqlL);
                $stmtL->execute([':lunes' => $lunes, ':domingo' => $domingo]);
                $conLibre = array_column($stmtL->fetchAll(), 'trabajador_id');

                // Filtrar los que NO tienen libre
                $sinLibre = array_filter($todos, fn($t) => !in_array($t['id'], $conLibre));

                $resultado = array_values($sinLibre);
                echo json_encode(['success' => true, 'data' => $resultado]);
                break;

            } elseif ($action === 'puestos') {
                $db = Database::getInstance()->getConnection();
                $resultado = $db->query("SELECT id, codigo, nombre, area FROM puestos_trabajo WHERE activo = TRUE ORDER BY area, codigo")->fetchAll();
                echo json_encode(['success' => true, 'data' => $resultado]);
                break;
            } elseif ($action === 'configuracion') {
                $db = Database::getInstance()->getConnection();
                $resultado = $db->query("SELECT id, numero_turno, nombre, hora_inicio, hora_fin FROM configuracion_turnos ORDER BY numero_turno")->fetchAll();
                echo json_encode(['success' => true, 'data' => $resultado]);
                break;
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
            } elseif ($action === 'actualizar') {
                // Actualizar turno existente (PUT alternativo para Apache)
                $id = $datos['id'] ?? null;
                if (!$id) {
                    echo json_encode(['success' => false, 'message' => 'ID requerido']);
                    break;
                }
                $resultado = $turnos->actualizar($id, $datos);
                echo json_encode($resultado);
            } elseif ($action === 'cancelar') {
                $id = $datos['id'] ?? null;
                if (!$id) {
                    echo json_encode(['success' => false, 'message' => 'ID requerido']);
                    break;
                }
                $resultado = $turnos->cancelar($id, $datos['motivo'] ?? null, $datos['usuario_id'] ?? null);
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