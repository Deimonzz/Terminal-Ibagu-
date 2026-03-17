<?php
require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents("php://input"), true);

$filename = $data['filename'] ?? null;
$eval_ids = $data['eval_ids'] ?? null;

if (!$filename || !is_array($eval_ids) || count($eval_ids) === 0) {
    echo json_encode([
        'success' => false,
        'error' => 'Datos incompletos para propagar'
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    $insert = $pdo->prepare("
        INSERT INTO evidencias_cumplimiento (evaluacion_id, archivo, uploaded_at)
        VALUES (?, ?, NOW())
    ");

    $update = $pdo->prepare("
        UPDATE evaluaciones SET estado='CUMPLE' WHERE id=?
    ");

    foreach ($eval_ids as $id) {
        $id = intval($id);
        $insert->execute([$id, $filename]);
        $update->execute([$id]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Evidencia propagada correctamente',
        'cantidad' => count($eval_ids)
    ]);
} catch(Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
