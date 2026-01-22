<?php
// backend/evidencias/uploadEvidencia.php  (versión mejorada)
require_once __DIR__ . "/../../config/db.php";
header('Content-Type: application/json; charset=utf-8');

if (!isset($_POST['evaluacion_id']) || empty($_FILES['evidencia']['name'])) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
    exit;
}

$evaluacion_id = intval($_POST['evaluacion_id']);

// Subida de archivo
$uploadDir = __DIR__ . "/../../uploads/cumplimientos/";
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

$filename = time() . "_" . basename($_FILES["evidencia"]["name"]);
$targetFile = $uploadDir . $filename;

if (!move_uploaded_file($_FILES["evidencia"]["tmp_name"], $targetFile)) {
    echo json_encode(['success' => false, 'error' => 'Error al mover el archivo']);
    exit;
}

// Si se pasó eval_ids en el form (JSON string), lo decodificamos
$eval_ids = [];
if (!empty($_POST['eval_ids'])) {
    $maybe = json_decode($_POST['eval_ids'], true);
    if (is_array($maybe)) $eval_ids = array_map('intval', $maybe);
}

// Si no se pidió propagar, por defecto insertar solo para el evaluacion_id
if (empty($eval_ids)) $eval_ids = [$evaluacion_id];

try {
    $pdo->beginTransaction();
    $stmtIns = $pdo->prepare("INSERT INTO evidencias_cumplimiento (evaluacion_id, archivo, uploaded_at) VALUES (?, ?, NOW())");
    $stmtUpd = $pdo->prepare("UPDATE evaluaciones SET estado = 'CUMPLE' WHERE id = ?");

    foreach ($eval_ids as $id) {
        $id = intval($id);
        if ($id <= 0) continue;
        $stmtIns->execute([$id, $filename]);
        $stmtUpd->execute([$id]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'file' => $filename,
        'message' => 'Evidencia subida y propagada a ' . count($eval_ids) . ' evaluaciones',
        'updated' => $eval_ids
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
