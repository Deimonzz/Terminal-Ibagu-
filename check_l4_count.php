<?php
require 'config/database.php';
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT COUNT(*) as c FROM turnos_asignados ta INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id WHERE ta.fecha BETWEEN '2026-08-01' AND '2026-08-31' AND ct.numero_turno IN (4,5) AND ta.estado IN ('programado','activo')");
$stmt->execute();
echo 'L4 count=' . $stmt->fetchColumn();
