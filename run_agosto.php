<?php
// Limpiar agosto primero
@ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(0);

require_once __DIR__ . '/config/database.php';

try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=gestion_turnos_db;charset=utf8mb4',
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    die("DB Error: " . $e->getMessage());
}

echo "=== LIMPIANDO AGOSTO 2026 ===\n";
$sql = "DELETE FROM turnos_asignados WHERE fecha BETWEEN '2026-08-01' AND '2026-08-31'";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$deleted = $stmt->rowCount();
echo "Registros eliminados: $deleted\n\n";

// Ahora ejecutar asignación
echo "=== INICIANDO ASIGNACIÓN DE AGOSTO 2026 ===\n";
echo "Fecha inicio: " . date('Y-m-d H:i:s') . "\n\n";

require_once __DIR__ . '/backend/clases/Trabajadores.php';
require_once __DIR__ . '/backend/clases/TurnosAsignados.php';
require_once __DIR__ . '/backend/clases/AsignacionAutomatica.php';

try {
    $asignador = new AsignacionAutomatica();
    
    $inicio = microtime(true);
    $resultado = $asignador->asignarMesCompleto(8, 2026, ['modo_rapido' => false]);
    $tiempo = microtime(true) - $inicio;
    
    echo "✓ Asignación completada en " . number_format($tiempo, 2) . " segundos\n";
    
    if (is_array($resultado)) {
        echo "\nResultado:\n";
        foreach ($resultado as $key => $val) {
            if (is_array($val)) {
                echo "  $key: " . count($val) . " items\n";
            } else {
                echo "  $key: $val\n";
            }
        }
    }
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== VERIFICACIÓN FINAL ===\n";

// Ver composición de agosto después de la asignación
$sql = "SELECT c.numero_turno, c.nombre, COUNT(*) as cantidad
        FROM turnos_asignados ta
        INNER JOIN configuracion_turnos c ON ta.turno_id = c.id
        WHERE ta.fecha BETWEEN '2026-08-01' AND '2026-08-31'
        GROUP BY c.numero_turno, c.nombre
        ORDER BY c.numero_turno";
$stmt = $pdo->query($sql);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($results)) {
    echo "⚠ Sin asignaciones en agosto\n";
} else {
    echo "Composición de AGOSTO 2026:\n";
    $totalL4 = 0;
    $totalTurnos = 0;
    foreach ($results as $r) {
        $isL4 = in_array($r['numero_turno'], [4, 5]) ? ' ← L4' : '';
        if (in_array($r['numero_turno'], [4, 5])) {
            $totalL4 += $r['cantidad'];
        }
        $totalTurnos += $r['cantidad'];
        echo "  {$r['numero_turno']}: {$r['nombre']}: {$r['cantidad']}{$isL4}\n";
    }
    echo "\nRESUMEN:\n";
    echo "  Total L4s: $totalL4\n";
    echo "  Total turnos: $totalTurnos\n";
    echo "  Porcentaje L4: " . round(($totalL4/$totalTurnos)*100, 1) . "%\n";
}
?>
