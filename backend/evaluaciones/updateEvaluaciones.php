<?php
require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents("php://input"), true);

    $evaluacion_id = $input['evaluacion_id'] ?? null;
    $estado = $input['estado'] ?? null;
    $actividad = $input['actividad'] ?? null;
    $responsable = $input['responsable'] ?? null;
    $evidencia = $input['evidencia'] ?? null; // nombre archivo guardado en uploads

    if(!$evaluacion_id || !$estado){
        throw new Exception("Datos incompletos");
    }

    // 1. Obtener el aspecto_id de la evaluación
    $stmt = $pdo->prepare("SELECT aspecto_id FROM evaluaciones WHERE id = ?");
    $stmt->execute([$evaluacion_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row){
        throw new Exception("Evaluación no encontrada");
    }
    $aspecto_id = $row['aspecto_id'];

    // 2. Actualizar la evaluación actual
    $stmt = $pdo->prepare("UPDATE evaluaciones 
                           SET estado = ?, actividad = ?, responsable = ?, evidencia = ? 
                           WHERE id = ?");
    $stmt->execute([$estado, $actividad, $responsable, $evidencia, $evaluacion_id]);

    // 3. Si se cumplió → actualizar todas las evaluaciones anteriores del mismo aspecto
    if ($estado === "CUMPLE") {
        $stmt = $pdo->prepare("UPDATE evaluaciones 
                               SET estado = 'CUMPLE' 
                               WHERE aspecto_id = ? 
                               AND id <> ?");
        $stmt->execute([$aspecto_id, $evaluacion_id]);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
