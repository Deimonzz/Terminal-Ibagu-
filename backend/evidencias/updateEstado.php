<?php
require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents("php://input"), true);
$id = $data["id"] ?? null;
$estado = $data["estado"] ?? null;

if (!$id || !$estado) {
    echo json_encode(["success" => false, "error" => "Datos incompletos"]);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE evaluaciones SET estado = ? WHERE id = ?");
    $stmt->execute([$estado, $id]);

    echo json_encode(["success" => true]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
