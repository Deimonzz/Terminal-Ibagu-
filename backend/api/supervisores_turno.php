<?php
require_once dirname(dirname(__DIR__)) . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
ob_start();

function sendJson($data, $status = 200) {
    if (ob_get_length()) @ob_end_clean();
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$db     = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $id    = $_GET['id']    ?? null;
        $fecha = $_GET['fecha'] ?? null;

        $fechaInicio = $_GET['fecha_inicio'] ?? null;
        $fechaFin    = $_GET['fecha_fin']    ?? null;

        if ($id) {
            // Obtener uno por id
            $stmt = $db->prepare(
                "SELECT st.*, t.nombre as trabajador, t.cedula
                 FROM supervisores_turno st
                 INNER JOIN trabajadores t ON t.id = st.trabajador_id
                 WHERE st.id = :id"
            );
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            sendJson(['success' => true, 'data' => $row ?: null]);
        } elseif ($fechaInicio && $fechaFin) {
            // Rango de fechas (para grilla mensual)
            $stmt = $db->prepare(
                "SELECT st.*, t.nombre as trabajador, t.cedula
                 FROM supervisores_turno st
                 INNER JOIN trabajadores t ON t.id = st.trabajador_id
                 WHERE st.fecha BETWEEN :fi AND :ff
                 ORDER BY st.fecha ASC, st.hora_inicio ASC"
            );
            $stmt->execute([':fi' => $fechaInicio, ':ff' => $fechaFin]);
            sendJson(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } elseif ($fecha) {
            // Un día específico (para vista diaria)
            $stmt = $db->prepare(
                "SELECT st.*, t.nombre as trabajador, t.cedula
                 FROM supervisores_turno st
                 INNER JOIN trabajadores t ON t.id = st.trabajador_id
                 WHERE st.fecha = :fecha
                 ORDER BY st.hora_inicio ASC"
            );
            $stmt->execute([':fecha' => $fecha]);
            sendJson(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } else {
            sendJson(['success' => false, 'message' => 'Parámetro fecha o id requerido'], 400);
        }

    } elseif ($method === 'POST') {
        $body   = json_decode(file_get_contents('php://input'), true);
        $action = $body['action'] ?? '';

        if ($action === 'actualizar') {
            $id = $body['id'] ?? null;
            if (!$id) sendJson(['success' => false, 'message' => 'ID requerido'], 400);
            $stmt = $db->prepare(
                "UPDATE supervisores_turno
                 SET hora_inicio = :hi, hora_fin = :hf, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id"
            );
            $stmt->execute([':hi' => $body['hora_inicio'], ':hf' => $body['hora_fin'], ':id' => $id]);
            sendJson(['success' => true, 'message' => 'Turno actualizado']);

        } elseif ($action === 'eliminar') {
            $id = $body['id'] ?? null;
            if (!$id) sendJson(['success' => false, 'message' => 'ID requerido'], 400);
            $stmt = $db->prepare("DELETE FROM supervisores_turno WHERE id = :id");
            $stmt->execute([':id' => $id]);
            sendJson(['success' => true, 'message' => 'Turno eliminado']);

        } else {
            // Crear nuevo
            $trabId    = $body['trabajador_id'] ?? null;
            $fecha     = $body['fecha']         ?? null;
            $horaIni   = $body['hora_inicio']   ?? null;
            $horaFin   = $body['hora_fin']       ?? null;
            $usuarioId = $body['usuario_id']     ?? 1;

            if (!$trabId || !$fecha || !$horaIni || !$horaFin) {
                sendJson(['success' => false, 'message' => 'Faltan campos requeridos'], 400);
            }

            // Verificar que no exista ya un turno supervisor para este trabajador ese día
            $chk = $db->prepare(
                "SELECT COUNT(*) as cnt FROM supervisores_turno
                 WHERE trabajador_id = :tid AND fecha = :fecha"
            );
            $chk->execute([':tid' => $trabId, ':fecha' => $fecha]);
            if ((int)$chk->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) {
                sendJson(['success' => false, 'message' => 'Este supervisor ya tiene turno registrado ese día'], 409);
            }

            // Verificar que no tenga un turno normal asignado ese día
            $chkTurno = $db->prepare(
                "SELECT COUNT(*) as cnt FROM turnos_asignados
                 WHERE trabajador_id = :tid AND fecha = :fecha AND estado IN ('programado', 'activo')"
            );
            $chkTurno->execute([':tid' => $trabId, ':fecha' => $fecha]);
            if ((int)$chkTurno->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) {
                sendJson(['success' => false, 'message' => 'Este trabajador ya tiene un turno normal asignado ese día'], 409);
            }

            // Verificar que no tenga un día especial que impida asignación
            $chkEspecial = $db->prepare(
                "SELECT COUNT(*) as cnt FROM dias_especiales
                 WHERE trabajador_id = :tid AND tipo IN ('LC', 'L', 'L8', 'VAC', 'SUS')
                 AND :fecha BETWEEN fecha_inicio AND COALESCE(fecha_fin, fecha_inicio)
                 AND estado IN ('programado', 'activo')"
            );
            $chkEspecial->execute([':tid' => $trabId, ':fecha' => $fecha]);
            if ((int)$chkEspecial->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) {
                sendJson(['success' => false, 'message' => 'Este trabajador tiene un día especial que impide asignación'], 409);
            }

            $stmt = $db->prepare(
                "INSERT INTO supervisores_turno (trabajador_id, fecha, hora_inicio, hora_fin, usuario_id)
                 VALUES (:tid, :fecha, :hi, :hf, :uid)"
            );
            $stmt->execute([
                ':tid'   => $trabId,
                ':fecha' => $fecha,
                ':hi'    => $horaIni,
                ':hf'    => $horaFin,
                ':uid'   => $usuarioId
            ]);
            sendJson(['success' => true, 'id' => $db->lastInsertId(), 'message' => 'Turno supervisor guardado']);
        }

    } else {
        sendJson(['success' => false, 'message' => 'Método no permitido'], 405);
    }

} catch (Exception $e) {
    sendJson(['success' => false, 'message' => $e->getMessage()], 500);
}
?>