<?php
require_once __DIR__ . '/../../config/db.php';
$data = json_decode(file_get_contents("php://input"), true);
if (!$data) { echo json_encode(['success'=>false,'msg'=>'No hay datos']); exit; }

$nombre = $data['nombre_visita'] ?? '';
$fecha_inicio = $data['fecha_inicio'] ?? null;
$fecha_fin = $data['fecha_fin'] ?? null;
$obs = $data['obs_adicionales'] ?? null;

$stmt = $pdo->prepare("INSERT INTO visitas (nombre_visita, fecha_inicio, fecha_fin, obs_adicionales) VALUES (?,?,?,?)");
$stmt->execute([$nombre, $fecha_inicio, $fecha_fin, $obs]);
echo json_encode(['success'=>true, 'id'=>$pdo->lastInsertId()]);
