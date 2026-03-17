<?php
// backend/evidencias/uploadHallazgo.php (mejorada)
require_once __DIR__ . "/../../config/db.php";
header('Content-Type: application/json; charset=utf-8');

if (!isset($_POST['evaluacion_id']) || empty($_FILES['evidencia']['name'])) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
    exit;
}

$evaluacion_id = intval($_POST['evaluacion_id']);

// Subir archivo
$uploadDir = __DIR__ . "/../../uploads/hallazgos/";
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

$filename = time() . "_" . basename($_FILES["evidencia"]["name"]);
$targetFile = $uploadDir . $filename;

if (!move_uploaded_file($_FILES["evidencia"]["tmp_name"], $targetFile)) {
    echo json_encode(['success' => false, 'error' => 'Error al mover el archivo']);
    exit;
}

// eval_ids opcional (JSON string en POST)
$eval_ids = [];
if (!empty($_POST['eval_ids'])) {
    $maybe = json_decode($_POST['eval_ids'], true);
    if (is_array($maybe)) $eval_ids = array_map('intval', $maybe);
}
if (empty($eval_ids)) $eval_ids = [$evaluacion_id];

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("UPDATE evaluaciones SET evidencia = ? WHERE id = ?");
    foreach ($eval_ids as $id) {
        $id = intval($id);
        if ($id <= 0) continue;
        $stmt->execute([$filename, $id]);
    }
    $pdo->commit();

    echo json_encode(['success' => true, 'file' => $filename, 'updated' => $eval_ids]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
