<?php
require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json');

try {
    $stmt = $pdo->query("SELECT id, nombre, correo FROM responsables ORDER BY nombre");
    $responsables = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($responsables);
} catch (Exception $e) {
    echo json_encode([]);
}