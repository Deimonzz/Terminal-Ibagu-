<?php
$pdo = new PDO('mysql:host=localhost;dbname=gestion_turnos_db;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$stmt = $pdo->query("SELECT id, numero_turno, nombre, horas_laborales FROM configuracion_turnos WHERE activo = 1 ORDER BY numero_turno, id");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo $r['id'] . '|' . $r['numero_turno'] . '|' . $r['nombre'] . '|' . $r['horas_laborales'] . PHP_EOL;
}

echo '---PUESTOS---' . PHP_EOL;
$stmt2 = $pdo->query("SELECT id, codigo, nombre, activo FROM puestos_trabajo WHERE activo = 1 ORDER BY codigo");
foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo $r['id'] . '|' . $r['codigo'] . '|' . $r['nombre'] . PHP_EOL;
}
