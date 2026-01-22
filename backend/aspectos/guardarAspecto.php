<?php
require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);
$nombre = trim($data['nombre'] ?? '');
$descripcion = $data['descripcion'] ?? null;
$peso = intval($data['peso'] ?? 1);

if ($nombre === '') {
  echo json_encode(['success'=>false,'msg'=>'Nombre requerido']);
  exit;
}

try {
  // buscar por nombre (case-insensitive) para evitar duplicados
  $stmt = $pdo->prepare("SELECT id FROM catalogo_aspectos WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?)) LIMIT 1");
  $stmt->execute([$nombre]);
  $existe = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($existe) {
    // devuelve el id existente
    echo json_encode(['success' => true, 'id' => $existe['id'], 'msg' => 'Ya existía']);
    exit;
  }

  // insertar nuevo
  $stmt = $pdo->prepare("INSERT INTO catalogo_aspectos (nombre, descripcion, peso) VALUES (?,?,?)");
  $stmt->execute([$nombre, $descripcion, $peso]);
  $id = $pdo->lastInsertId();

  echo json_encode(['success' => true, 'id' => $id, 'msg' => 'Creado nuevo']);
} catch (Exception $e) {
  echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
}
