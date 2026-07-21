<?php
// CLI script to verify L4 assignments
require_once __DIR__ . '/config/database.php';

try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=gestion_turnos_db;charset=utf8mb4',
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    die("ERROR: " . $e->getMessage() . "\n");
}

echo "=== VERIFICACIÓN DE L4s EN AGOSTO 2026 ===\n\n";

// Contar L4s
$sql = "SELECT COUNT(*) as total FROM turnos_asignados WHERE turno_id IN (4, 9, 13, 5, 10, 14) AND fecha BETWEEN '2026-08-01' AND '2026-08-31'";
$stmt = $pdo->query($sql);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$totalL4 = $result['total'] ?? 0;

// Contar todos
$sql = "SELECT COUNT(*) as total FROM turnos_asignados WHERE fecha BETWEEN '2026-08-01' AND '2026-08-31'";
$stmt = $pdo->query($sql);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$totalTodos = $result['total'] ?? 0;

echo "Total L4s: $totalL4\n";
echo "Total turnos: $totalTodos\n";

if ($totalTodos > 0) {
    $porcentaje = round(($totalL4 / $totalTodos) * 100, 1);
    echo "Porcentaje L4: $porcentaje%\n";
    echo "\n✓ ÉXITO: Se asignaron $totalL4 L4s\n";
} else {
    echo "\n✗ ERROR: No hay asignaciones\n";
}
?>
