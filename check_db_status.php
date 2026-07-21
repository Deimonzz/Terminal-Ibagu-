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

// Contar total de turnos en la tabla
$sql = "SELECT COUNT(*) as total FROM turnos_asignados";
$stmt = $pdo->query($sql);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Total de turnos en tabla: " . ($result['total'] ?? 0) . "\n";

// Ver últimos 5 turnos
$sql = "SELECT ta.id, ta.fecha, ta.trabajador_id, t.nombre, c.nombre as turno 
        FROM turnos_asignados ta 
        LEFT JOIN trabajadores t ON ta.trabajador_id = t.id
        LEFT JOIN configuracion_turnos c ON ta.turno_id = c.id
        ORDER BY ta.id DESC LIMIT 5";
$stmt = $pdo->query($sql);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\nÚltimos 5 turnos:\n";
foreach ($results as $r) {
    echo "  ID {$r['id']}: {$r['fecha']} - {$r['nombre']} - {$r['turno']}\n";
}

// Ver composición de turnos
echo "\nComposición de turnos:\n";
$sql = "SELECT c.numero_turno, c.nombre, COUNT(*) as cantidad
        FROM turnos_asignados ta
        INNER JOIN configuracion_turnos c ON ta.turno_id = c.id
        GROUP BY c.numero_turno, c.nombre
        ORDER BY c.numero_turno";
$stmt = $pdo->query($sql);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($results as $r) {
    echo "  {$r['numero_turno']}: {$r['nombre']}: {$r['cantidad']}\n";
}
?>
