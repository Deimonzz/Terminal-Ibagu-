<?php
// test_db.php - Archivo temporal para probar conexión
require_once 'tu_archivo_de_conexion.php'; // Cambia por el nombre de tu archivo

try {
    $db = Database::getInstance()->getConnection();
    
    // Probar consulta simple
    $stmt = $db->query("SELECT 1 as test");
    $result = $stmt->fetch();
    
    echo json_encode([
        'success' => true,
        'message' => '✅ Conexión exitosa a la base de datos de Render',
        'db_name' => DB_NAME,
        'db_driver' => DB_DRIVER,
        'test_query' => $result
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '❌ Error de conexión: ' . $e->getMessage()
    ]);
}
?>