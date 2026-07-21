<?php
@ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/backend/clases/Trabajadores.php';

try {
    $trabajadores = new Trabajadores();
    
    echo "=== DEBUG L4 DISPONIBILIDAD ===\n\n";
    
    // Test con una fecha de agosto
    $fechaTest = '2026-08-01';
    $puestoIdTest = 14; // Algún puesto
    $turnoIdTest = 9;   // Turno L4
    
    echo "Probando obtenerDisponiblesL4:\n";
    echo "  Fecha: $fechaTest\n";
    echo "  Puesto ID: $puestoIdTest\n";
    echo "  Turno ID: $turnoIdTest\n\n";
    
    $result = $trabajadores->obtenerDisponiblesL4($puestoIdTest, $turnoIdTest, $fechaTest);
    
    echo "Resultado: " . count($result) . " trabajadores disponibles\n";
    if (count($result) > 0) {
        echo "Primeros 5:\n";
        foreach (array_slice($result, 0, 5) as $t) {
            echo "  - {$t['id']}: {$t['nombre']}\n";
        }
    } else {
        echo "❌ SIN DISPONIBLES PARA ESTE TURNO/FECHA\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
?>
