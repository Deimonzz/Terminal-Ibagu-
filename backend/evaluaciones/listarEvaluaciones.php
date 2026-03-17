<?php
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $visita = $_GET['visita'] ?? '';
    $estado = $_GET['estado'] ?? '';

    $sql = "SELECT 
                e.id,
                e.observacion,
                e.estado,
                a.nombre AS aspecto
            FROM evaluaciones e
            LEFT JOIN catalogo_aspectos a ON a.id = e.aspecto_id
            WHERE 1=1";

    $params = [];

    if (!empty($visita)) {
        $sql .= " AND e.visita_id = ?";
        $params[] = $visita;
    }

    if (!empty($estado)) {
        $sql .= " AND e.estado = ?";
        $params[] = $estado;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $evaluaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($evaluaciones);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
