<?php
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

$mes = 8;
$anio = 2026;

echo "=== DEBUG FASE L4 ===\n";
echo "Mes: $mes, Año: $anio\n\n";

// 1. Verificar puestos L4
$sql = "SELECT * FROM puestos_trabajo WHERE codigo IN ('F11', 'F5', 'F15', 'D1', 'D2')";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$puestosL4 = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Puestos L4 disponibles: " . count($puestosL4) . "\n";
foreach ($puestosL4 as $p) {
    echo "  - " . $p['codigo'] . " (ID:" . $p['id'] . ")\n";
}
echo "\n";

// 2. Verificar cuántos turnos L4 ya existen en agosto
$sql = "SELECT COUNT(*) as total FROM turnos_asignados 
        WHERE fecha BETWEEN '2026-08-01' AND '2026-08-31' 
        AND turno_id IN (4, 5)";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$existentes = $stmt->fetchAll(PDO::FETCH_ASSOC)[0];
echo "Turnos L4 existentes en agosto: " . $existentes['total'] . "\n\n";

// 3. Verificar qué turno_id corresponde a L4
$sql = "SELECT id, numero_turno, horas_laborales FROM configuracion_turnos WHERE numero_turno IN (4, 5)";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$turnosL4Config = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Configuración de turnos L4:\n";
foreach ($turnosL4Config as $t) {
    echo "  - turno_id=" . $t['id'] . ", numero_turno=" . $t['numero_turno'] . ", horas=" . $t['horas_laborales'] . "\n";
}
echo "\n";

// 4. Verificar si hay restricciones que bloqueen L4
$sql = "SELECT COUNT(*) as total FROM restricciones_trabajador WHERE tipo_restriccion = 'L4'";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$restricciones = $stmt->fetchAll(PDO::FETCH_ASSOC)[0];
echo "Trabajadores con restricción L4: " . $restricciones['total'] . "\n\n";

// 5. Verificar composición de turnos asignados en agosto (sin L4)
$sql = "SELECT numero_turno, COUNT(*) as total 
        FROM turnos_asignados ta 
        INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id 
        WHERE ta.fecha BETWEEN '2026-08-01' AND '2026-08-31' 
        AND ta.estado IN ('programado', 'activo')
        GROUP BY numero_turno 
        ORDER BY numero_turno";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$composicion = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Composición de turnos asignados en agosto:\n";
foreach ($composicion as $c) {
    echo "  Turno " . $c['numero_turno'] . ": " . $c['total'] . " asignaciones\n";
}
echo "\n";

echo "\n✓ Debug completado\n";
?>
