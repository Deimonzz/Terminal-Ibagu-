
<?php
require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

$nombre = trim($data['nombre'] ?? '');
$correo = trim($data['correo'] ?? '');

if ($nombre === '' || $correo === '') {
    echo json_encode(['success' => false, 'msg' => 'Nombre y correo son obligatorios']);
    exit;
}

// Puedes validar el correo si lo deseas
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'msg' => 'Correo no válido']);
    exit;
}

try {
    $stmt = $pdo->prepare("INSERT INTO responsables (nombre, correo) VALUES (?, ?)");
    $stmt->execute([$nombre, $correo]);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'msg' => 'Error al guardar responsable']);
}