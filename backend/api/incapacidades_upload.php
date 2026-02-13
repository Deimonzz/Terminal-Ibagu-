<?php
// Endpoint para crear incapacidad con upload de archivo opcional
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../clases/Incapacidades.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Validar campos obligatorios
    $trabajador_id = $_POST['trabajador_id'] ?? null;
    $tipo = $_POST['tipo'] ?? null;
    $fecha_inicio = $_POST['fecha_inicio'] ?? null;
    $fecha_fin = $_POST['fecha_fin'] ?? null;

    if (!$trabajador_id || !$tipo || !$fecha_inicio || !$fecha_fin) {
        echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
        exit;
    }

    // Preparar datos
    $datos = [
        'trabajador_id' => intval($trabajador_id),
        'tipo' => sanitizar($tipo),
        'fecha_inicio' => $fecha_inicio,
        'fecha_fin' => $fecha_fin,
        'descripcion' => sanitizar($_POST['descripcion'] ?? ''),
        'eps' => sanitizar($_POST['eps'] ?? ''),
        'genera_restriccion' => ($_POST['genera_restriccion'] ?? '0') == '1',
        'tipo_restriccion_generada' => sanitizar($_POST['tipo_restriccion_generada'] ?? null),
        'restriccion_permanente' => ($_POST['restriccion_permanente'] ?? '0') == '1',
        'fecha_fin_restriccion' => $_POST['fecha_fin_restriccion'] ?? null
    ];

    // Manejar archivo si existe
    if (!empty($_FILES['documento']['name'])) {
        $file = $_FILES['documento'];
        
        // Solo permitir tipos específicos
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        $allowed_mimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($mime, $allowed_mimes)) {
            echo json_encode(['success' => false, 'message' => 'Tipo de archivo no permitido']);
            exit;
        }

        // Crear directorio si no existe
        $uploadDir = __DIR__ . '/../../uploads/incapacidades/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Generar nombre seguro
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'incapacidad_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $targetFile = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $targetFile)) {
            echo json_encode(['success' => false, 'message' => 'Error al guardar el archivo']);
            exit;
        }

        $datos['documento_soporte'] = $filename;
    }

    // Guardar incapacidad
    $inc = new Incapacidades();
    $resultado = $inc->crear($datos);

    echo json_encode($resultado);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
}

function sanitizar($str) {
    if (is_null($str) || $str === '') return null;
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}
?>
