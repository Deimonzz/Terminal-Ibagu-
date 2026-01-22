<?php
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $stmt = $pdo->query("SELECT id, nombre, descripcion, peso FROM catalogo_aspectos ORDER BY nombre");
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Limpiar caracteres especiales que puedan causar problemas con JSON
    foreach ($resultados as &$fila) {
        foreach ($fila as &$valor) {
            if ($valor !== null) {
                $valor = mb_convert_encoding($valor, 'UTF-8', 'UTF-8');
            }
        }
    }
    
    echo json_encode($resultados, JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error de base de datos: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error: ' . $e->getMessage()
    ]);
}
?>