<?php
@ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/config/database.php';

try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=gestion_turnos_db;charset=utf8mb4',
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    die(json_encode(['error' => $e->getMessage()]));
}

// Contar L4s asignados en Agosto 2026
$sql = "SELECT COUNT(*) as total FROM turnos_asignados WHERE turno_id IN (4, 9, 13, 5, 10, 14) AND fecha BETWEEN '2026-08-01' AND '2026-08-31'";
$stmt = $pdo->query($sql);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$totalL4 = $result['total'] ?? 0;

// Contar todos los turnos
$sql = "SELECT COUNT(*) as total FROM turnos_asignados WHERE fecha BETWEEN '2026-08-01' AND '2026-08-31'";
$stmt = $pdo->query($sql);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$totalTodos = $result['total'] ?? 0;

// Mostrar resultados
header('Content-Type: application/json');
echo json_encode([
    'l4_asignados' => $totalL4,
    'total_turnos' => $totalTodos,
    'porcentaje_l4' => $totalTodos > 0 ? round(($totalL4 / $totalTodos) * 100, 2) . '%' : '0%',
    'estado' => ($totalL4 > 0) ? 'OK ✓' : 'ERROR ✗'
]);
?>
