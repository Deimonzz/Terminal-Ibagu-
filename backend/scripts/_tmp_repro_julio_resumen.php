<?php
require 'c:/xampp/htdocs/Terminal-Ibagu-/config/database.php';
$db = Database::getInstance()->getConnection();
$rows = $db->query("SELECT fecha, COUNT(*) c FROM turnos_asignados WHERE fecha BETWEEN '2026-07-01' AND '2026-07-10' AND observaciones LIKE 'Asignacion automatica%' AND estado IN ('programado','activo') GROUP BY fecha ORDER BY fecha")->fetchAll(PDO::FETCH_ASSOC);
echo "AUTO_1_10\n";
foreach($rows as $r){ echo $r['fecha'].' '.$r['c']."\n"; }
?>
