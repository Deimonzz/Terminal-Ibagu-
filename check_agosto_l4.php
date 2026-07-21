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
    die("ERROR: " . $e->getMessage());
}

echo "=== ANÁLISIS DE ASIGNACIONES ===\n\n";

// Agosto específicamente
echo "AGOSTO 2026:\n";
$sql = "SELECT c.numero_turno, c.nombre, COUNT(*) as cantidad
        FROM turnos_asignados ta
        INNER JOIN configuracion_turnos c ON ta.turno_id = c.id
        WHERE ta.fecha BETWEEN '2026-08-01' AND '2026-08-31'
        GROUP BY c.numero_turno, c.nombre
        ORDER BY c.numero_turno";
$stmt = $pdo->query($sql);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($results)) {
    echo "  ⚠ No hay asignaciones en agosto\n";
} else {
    foreach ($results as $r) {
        $isL4 = in_array($r['numero_turno'], [4, 5]) ? ' ← L4' : '';
        echo "  {$r['numero_turno']}: {$r['nombre']}: {$r['cantidad']}{$isL4}\n";
    }
}

// Julio para comparación
echo "\nJULIO 2026:\n";
$sql = "SELECT c.numero_turno, c.nombre, COUNT(*) as cantidad
        FROM turnos_asignados ta
        INNER JOIN configuracion_turnos c ON ta.turno_id = c.id
        WHERE ta.fecha BETWEEN '2026-07-01' AND '2026-07-31'
        GROUP BY c.numero_turno, c.nombre
        ORDER BY c.numero_turno";
$stmt = $pdo->query($sql);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($results)) {
    echo "  ⚠ No hay asignaciones en julio\n";
} else {
    foreach ($results as $r) {
        $isL4 = in_array($r['numero_turno'], [4, 5]) ? ' ← L4' : '';
        echo "  {$r['numero_turno']}: {$r['nombre']}: {$r['cantidad']}{$isL4}\n";
    }
}

// Mostrar ejemplos de L4s en agosto
echo "\nEJEMPLOS DE L4s EN AGOSTO 2026 (primeros 10):\n";
$sql = "SELECT ta.id, ta.fecha, t.nombre, c.nombre as turno
        FROM turnos_asignados ta
        INNER JOIN trabajadores t ON ta.trabajador_id = t.id
        INNER JOIN configuracion_turnos c ON ta.turno_id = c.id
        WHERE ta.fecha BETWEEN '2026-08-01' AND '2026-08-31'
        AND c.numero_turno IN (4, 5)
        LIMIT 10";
$stmt = $pdo->query($sql);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($results)) {
    echo "  ✗ No hay L4s en agosto\n";
} else {
    foreach ($results as $r) {
        echo "  {$r['fecha']}: {$r['nombre']} - {$r['turno']}\n";
    }
}
?>
