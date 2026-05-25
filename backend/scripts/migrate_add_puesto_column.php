<?php
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(__DIR__)) . '/config/database.php';

$db = Database::getInstance()->getConnection();

try {
    // Verificar si la columna puesto_trabajo_id ya existe
    $columnExistsPrimary = Database::hasColumn('restricciones_trabajador', 'puesto_trabajo_id');
    
    // Verificar si la columna puesto_id existe (fallback)
    $columnExistsFallback = Database::hasColumn('restricciones_trabajador', 'puesto_id');
    
    if ($columnExistsPrimary) {
        echo json_encode([
            'success' => true,
            'message' => 'La columna puesto_trabajo_id ya existe en restricciones_trabajador',
            'status' => 'already_exists'
        ]);
        exit;
    }
    
    if ($columnExistsFallback) {
        echo json_encode([
            'success' => true,
            'message' => 'La columna puesto_id existe en restricciones_trabajador (se usará como fallback)',
            'status' => 'fallback_exists'
        ]);
        exit;
    }
    
    // Agregar la columna puesto_trabajo_id
    $sql = "ALTER TABLE `restricciones_trabajador` 
            ADD COLUMN `puesto_trabajo_id` INT NULL 
            AFTER `tipo_restriccion`";
    
    $db->exec($sql);
    
    // Verificar si se agregó correctamente
    if (Database::hasColumn('restricciones_trabajador', 'puesto_trabajo_id')) {
        // Intentar agregar la foreign key
        try {
            $fkSql = "ALTER TABLE `restricciones_trabajador`
                     ADD CONSTRAINT `fk_restricciones_puesto` 
                     FOREIGN KEY (`puesto_trabajo_id`) 
                     REFERENCES `puestos_trabajo`(`id`) ON DELETE SET NULL";
            $db->exec($fkSql);
            $fkStatus = 'added';
        } catch (Exception $e) {
            $fkStatus = 'error: ' . $e->getMessage();
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Columna puesto_trabajo_id agregada exitosamente a restricciones_trabajador',
            'status' => 'column_added',
            'foreign_key_status' => $fkStatus
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Error: No se pudo verificar que la columna se agregó correctamente'
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al ejecutar migración: ' . $e->getMessage(),
        'error' => [
            'code' => $e->getCode(),
            'type' => get_class($e)
        ]
    ]);
}
?>
