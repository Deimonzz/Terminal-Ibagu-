<?php
// Verificar asignaciones de L4 en BD

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

echo "=== VERIFICACIÓN DE TURNOS L4 ===\n\n";

// 1. Ver configuración de turnos L4
echo "1. CONFIGURACIÓN DE TURNOS L4:\n";
$sql = "SELECT id, numero_turno, nombre, hora_inicio, hora_fin FROM configuracion_turnos WHERE numero_turno IN (4, 5) ORDER BY numero_turno";
$stmt = $pdo->query($sql);
$turnos = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($turnos)) {
    echo "   ❌ NO hay configuración de turnos L4 (numero_turno 4 o 5)\n";
} else {
    foreach ($turnos as $t) {
        echo "   ✓ Turno ID {$t['id']}: {$t['numero_turno']} - {$t['nombre']} ({$t['hora_inicio']}-{$t['hora_fin']})\n";
    }
}
echo "\n";

// 2. Contar L4s asignados en todo el mes
echo "2. L4s ASIGNADOS EN AGOSTO 2026:\n";
$sql = "SELECT COUNT(*) as total FROM turnos_asignados ta 
        INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id 
        WHERE ct.numero_turno IN (4, 5) 
        AND ta.fecha BETWEEN '2026-08-01' AND '2026-08-31'";
$stmt = $pdo->query($sql);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
echo "   Total L4s en agosto: " . ($result['total'] ?? 0) . "\n";
echo "\n";

// 3. Mostrar ejemplos de L4s asignados
echo "3. EJEMPLOS DE L4s ASIGNADOS:\n";
$sql = "SELECT ta.id, ta.trabajador_id, ta.puesto_id, ta.turno_id, ta.fecha, ct.numero_turno, ct.nombre
        FROM turnos_asignados ta 
        INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id 
        WHERE ct.numero_turno IN (4, 5) 
        AND ta.fecha BETWEEN '2026-08-01' AND '2026-08-31'
        LIMIT 10";
$stmt = $pdo->query($sql);
$ejemplos = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($ejemplos)) {
    echo "   ❌ No hay ejemplos de L4s asignados\n";
} else {
    foreach ($ejemplos as $ej) {
        echo "   ✓ Trabajador {$ej['trabajador_id']}, Puesto {$ej['puesto_id']}, {$ej['fecha']}: {$ej['numero_turno']} ({$ej['nombre']})\n";
    }
}
echo "\n";

// 4. Contar por turno tipo (para ver composición general)
echo "4. COMPOSICIÓN DE TURNOS EN AGOSTO 2026:\n";
$sql = "SELECT ct.numero_turno, ct.nombre, COUNT(*) as cantidad
        FROM turnos_asignados ta 
        INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id 
        WHERE ta.fecha BETWEEN '2026-08-01' AND '2026-08-31'
        GROUP BY ct.numero_turno, ct.nombre
        ORDER BY ct.numero_turno";
$stmt = $pdo->query($sql);
$composicion = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($composicion as $c) {
    $isL4 = in_array($c['numero_turno'], [4, 5]) ? ' ← L4' : '';
    echo "   {$c['numero_turno']}: {$c['nombre']}: {$c['cantidad']}{$isL4}\n";
}
echo "\n";

// 5. Ver último log de asignación
echo "5. ÚLTIMO LOG DE ASIGNACIÓN:\n";
if (file_exists(__DIR__ . '/backend/logs/asignacion_debug.log')) {
    $lines = array_slice(file(__DIR__ . '/backend/logs/asignacion_debug.log'), -20);
    foreach ($lines as $line) {
        echo "   " . trim($line) . "\n";
    }
} else {
    echo "   ❌ No hay logs de asignación\n";
}

echo "\n=== FIN VERIFICACIÓN ===\n";
?>
