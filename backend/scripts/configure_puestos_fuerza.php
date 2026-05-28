<?php
/**
 * Script para configurar puestos G y Equipajes con requiere_fuerza_fisica = true
 */

header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(__DIR__)) . '/config/database.php';

$db = Database::getInstance()->getConnection();

$result = [
    'status' => 'ok',
    'timestamp' => date('Y-m-d H:i:s'),
    'actions' => []
];

try {
    // 1. Buscar puestos G y Equipajes
    $sql = "SELECT id, codigo, nombre, requiere_fuerza_fisica 
            FROM puestos_trabajo 
            WHERE LOWER(codigo) IN ('g', 'equipajes') OR LOWER(nombre) LIKE '%equipaje%'
            ORDER BY codigo";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $puestos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $result['puestos_encontrados'] = $puestos;
    
    if (empty($puestos)) {
        $result['warning'] = 'No se encontraron puestos G o Equipajes. Buscar por otros nombres.';
        $result['actions'][] = [
            'status' => 'warning',
            'message' => 'Puestos no encontrados, revisar nombres en BD'
        ];
    } else {
        // 2. Actualizar puestos para que requieran fuerza física
        foreach ($puestos as $puesto) {
            if (!$puesto['requiere_fuerza_fisica']) {
                $updateSql = "UPDATE puestos_trabajo 
                             SET requiere_fuerza_fisica = true 
                             WHERE id = ?";
                $updateStmt = $db->prepare($updateSql);
                $updateStmt->execute([$puesto['id']]);
                
                $result['actions'][] = [
                    'puesto' => $puesto['codigo'],
                    'status' => 'updated',
                    'message' => 'Puesto actualizado: requiere_fuerza_fisica = true'
                ];
            } else {
                $result['actions'][] = [
                    'puesto' => $puesto['codigo'],
                    'status' => 'already_set',
                    'message' => 'Puesto ya tenía requiere_fuerza_fisica = true'
                ];
            }
        }
    }
    
    // 3. Verificar que todos los puestos estén listados
    $allSql = "SELECT id, codigo, nombre, requiere_fuerza_fisica, requiere_movilidad 
               FROM puestos_trabajo 
               WHERE activo = true 
               ORDER BY codigo";
    $allStmt = $db->prepare($allSql);
    $allStmt->execute();
    $todosLos = $allStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $result['todos_los_puestos'] = $todosLos;
    $result['total_puestos'] = count($todosLos);
    
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>
