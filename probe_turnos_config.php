<?php
require 'config/database.php';
$db = Database::getInstance()->getConnection();
$stmt = $db->query("SELECT id, nombre, numero_turno, horas_laborales, activo FROM configuracion_turnos WHERE activo = TRUE AND numero_turno IN (1,2,3,4,5) ORDER BY numero_turno, id");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: application/json');
echo json_encode($rows, JSON_UNESCAPED_UNICODE);
