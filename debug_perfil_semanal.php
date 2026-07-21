<?php
@ini_set('display_errors', '1');
error_reporting(E_ALL);

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

echo "=== DEBUG PERFIL SEMANAL EN AGOSTO ===\n\n";

// Verificar los 4 trabajadores disponibles del test anterior
$trabajadores = [17, 26, 31, 49];

foreach ($trabajadores as $trabId) {
    // Calcular semanas en agosto
    $semanas = [];
    $fecha = '2026-08-01';
    while (strtotime($fecha) <= strtotime('2026-08-31')) {
        $dow = (int)date('w', strtotime($fecha)); // 0 = domingo
        $lunes = date('Y-m-d', strtotime($fecha) - ($dow == 0 ? 6 : $dow - 1) * 86400);
        $semanaKey = $lunes;
        if (!in_array($semanaKey, $semanas)) {
            $semanas[] = $semanaKey;
        }
        $fecha = date('Y-m-d', strtotime($fecha) + 86400);
    }
    
    echo "Trabajador ID $trabId - Semanas en agosto:\n";
    foreach ($semanas as $semana) {
        $sql = "SELECT COUNT(*) as total, SUM(CAST(ct.horas_laborales as FLOAT)) as horas
                FROM turnos_asignados ta
                INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                WHERE ta.trabajador_id = :trab_id
                AND ta.fecha >= :lunes AND ta.fecha < DATE_ADD(:lunes, INTERVAL 7 DAY)
                AND ta.estado IN ('programado', 'activo')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':trab_id' => $trabId, ':lunes' => $semana]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $turnos = $result['total'] ?? 0;
        $horas = $result['horas'] ?? 0;
        echo "  Semana $semana: $turnos turnos, $horas horas";
        if ($horas >= 42) {
            echo " ← YA LLENO (>=42h)";
        } else {
            echo " ← Disponible para L4";
        }
        echo "\n";
    }
    echo "\n";
}

?>
