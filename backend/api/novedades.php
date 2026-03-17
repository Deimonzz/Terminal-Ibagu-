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
        $fecha = $_GET['fecha'] ?? '';
        if (!$fecha) sendJson(['success' => false, 'message' => 'Fecha requerida'], 400);

        $stmt = $db->prepare("SELECT * FROM novedades_dia WHERE fecha = :fecha");
        $stmt->execute([':fecha' => $fecha]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        sendJson([
            'success' => true,
            'data'    => $row ?: null
        ]);

    } elseif ($method === 'POST') {
        $body    = json_decode(file_get_contents('php://input'), true);
        $fecha   = $body['fecha']    ?? '';
        $texto   = trim($body['contenido'] ?? '');
        $usuario = $body['usuario_id'] ?? 1;

        if (!$fecha) sendJson(['success' => false, 'message' => 'Fecha requerida'], 400);

        if ($texto === '') {
            // Borrar novedad si el texto queda vacío
            $stmt = $db->prepare("DELETE FROM novedades_dia WHERE fecha = :fecha");
            $stmt->execute([':fecha' => $fecha]);
            sendJson(['success' => true, 'message' => 'Novedad eliminada', 'deleted' => true]);
        }

        // INSERT o UPDATE (UNIQUE KEY en fecha)
        $stmt = $db->prepare(
            "INSERT INTO novedades_dia (fecha, contenido, usuario_id)
             VALUES (:fecha, :contenido, :uid)
             ON DUPLICATE KEY UPDATE
                contenido  = VALUES(contenido),
                usuario_id = VALUES(usuario_id),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([
            ':fecha'     => $fecha,
            ':contenido' => $texto,
            ':uid'       => $usuario
        ]);

        sendJson(['success' => true, 'message' => 'Novedad guardada']);

    } else {
        sendJson(['success' => false, 'message' => 'Método no permitido'], 405);
    }

} catch (Exception $e) {
    sendJson(['success' => false, 'message' => $e->getMessage()], 500);
}
?>