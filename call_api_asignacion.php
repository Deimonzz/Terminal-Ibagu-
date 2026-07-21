<?php
@ini_set('display_errors', '1');
error_reporting(E_ALL);

$url = 'http://localhost/Terminal-Ibagu-/backend/api/asignacion_automatica.php';

echo "=== ENVIANDO SOLICITUD DE ASIGNACIÓN ===\n";
echo "URL: $url\n";
echo "Datos: mes=8, anio=2026, modo_rapido=false\n\n";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 600);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'mes'         => 8,
    'anio'        => 2026,
    'modo_rapido' => false
]));

echo "Esperando respuesta...\n";
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";

if ($error) {
    echo "Error: $error\n";
} else {
    echo "\nRespuesta:\n";
    echo $response . "\n";
    
    // Parsear JSON
    $json = json_decode($response, true);
    if ($json) {
        echo "\n=== RESULTADO ===\n";
        foreach ($json as $key => $val) {
            if (is_array($val)) {
                echo "$key: " . count($val) . " items\n";
            } else {
                echo "$key: $val\n";
            }
        }
    }
}

// Verificar asignaciones
echo "\n=== VERIFICANDO AUGUST 2026 ===\n";

require_once __DIR__ . '/../config/database.php';
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=gestion_turnos_db;charset=utf8mb4',
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $sql = "SELECT c.numero_turno, COUNT(*) as cantidad
            FROM turnos_asignados ta
            INNER JOIN configuracion_turnos c ON ta.turno_id = c.id
            WHERE ta.fecha BETWEEN '2026-08-01' AND '2026-08-31'
            GROUP BY c.numero_turno
            ORDER BY c.numero_turno";
    $stmt = $pdo->query($sql);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($results)) {
        echo "⚠ No hay asignaciones\n";
    } else {
        $totalL4 = 0;
        foreach ($results as $r) {
            if (in_array($r['numero_turno'], [4, 5])) $totalL4 += $r['cantidad'];
            echo "  Turno {$r['numero_turno']}: {$r['cantidad']}\n";
        }
        echo "\n✓ L4s asignados: $totalL4\n";
    }
} catch (Exception $e) {
    echo "DB Error: " . $e->getMessage() . "\n";
}
?>
