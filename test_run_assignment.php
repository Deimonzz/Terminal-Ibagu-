<?php
// Ejecutar asignación automática y verificar L4s

@ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(0);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/backend/clases/Trabajadores.php';
require_once __DIR__ . '/backend/clases/TurnosAsignados.php';
require_once __DIR__ . '/backend/clases/AsignacionAutomatica.php';

echo "=== INICIANDO ASIGNACIÓN AUTOMÁTICA ===\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $asignador = new AsignacionAutomatica();
    
    echo "Ejecutando asignación para Agosto 2026...\n";
    $inicio = microtime(true);
    $resultado = $asignador->asignarMesCompleto(8, 2026, ['modo_rapido' => false]);
    $tiempo = microtime(true) - $inicio;
    
    echo "\n✓ Asignación completada en " . number_format($tiempo, 2) . " segundos\n";
    
    // Mostrar resultado
    if (is_array($resultado)) {
        echo "\nResultado:\n";
        foreach ($resultado as $key => $val) {
            if (is_array($val)) {
                echo "  $key: " . json_encode($val) . "\n";
            } else {
                echo "  $key: $val\n";
            }
        }
    } else {
        echo "\nResultado: " . $resultado . "\n";
    }
    
} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n=== VERIFICANDO L4s ASIGNADOS ===\n";

try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=gestion_turnos_db;charset=utf8mb4',
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Contar L4s
    $sql = "SELECT COUNT(*) as total FROM turnos_asignados WHERE turno_id IN (4, 9, 13, 5, 10, 14) AND fecha BETWEEN '2026-08-01' AND '2026-08-31'";
    $stmt = $pdo->query($sql);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Total L4s asignados en Agosto: " . ($result['total'] ?? 0) . "\n";
    
    // Contar todos los turnos
    $sql = "SELECT COUNT(*) as total FROM turnos_asignados WHERE fecha BETWEEN '2026-08-01' AND '2026-08-31'";
    $stmt = $pdo->query($sql);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Total turnos asignados en Agosto: " . ($result['total'] ?? 0) . "\n";
    
} catch (Exception $e) {
    echo "DB Error: " . $e->getMessage() . "\n";
}

echo "\n=== FIN ===\n";
?>
