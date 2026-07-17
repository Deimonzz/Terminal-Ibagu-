<?php
require 'c:/xampp/htdocs/Terminal-Ibagu-/config/database.php';
require 'c:/xampp/htdocs/Terminal-Ibagu-/backend/clases/TurnosAsignados.php';
$db = Database::getInstance()->getConnection();
$ta = new TurnosAsignados();
$fi = '2026-07-01';
$ff = '2026-07-31';
$puestoCol = Database::getColumnName('turnos_asignados', 'puesto_trabajo_id', 'puesto_id');
$lines = [];
if (!$puestoCol) { $lines[] = 'NO_PUESTO_COL'; file_put_contents('c:/xampp/htdocs/Terminal-Ibagu-/backend/logs/diag_invalid_auto.txt', implode(PHP_EOL, $lines)); exit(0); }
$sql = "SELECT id, trabajador_id, $puestoCol as puesto_id, turno_id, fecha, observaciones FROM turnos_asignados WHERE fecha BETWEEN :fi AND :ff AND estado IN ('programado','activo') AND (LOWER(COALESCE(observaciones,'')) LIKE 'asignacion automatica%' OR LOWER(COALESCE(observaciones,'')) LIKE 'asignación automática%' OR LOWER(REPLACE(REPLACE(COALESCE(observaciones,''),'á','a'),'ó','o')) LIKE 'asignacion automatica%') ORDER BY fecha,id";
$stmt = $db->prepare($sql);
$stmt->execute([':fi'=>$fi,':ff'=>$ff]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$invalid = [];
foreach ($rows as $r) {
  $v = $ta->validarAsignacion((int)$r['trabajador_id'], (int)$r['puesto_id'], (int)$r['turno_id'], (string)$r['fecha'], (int)$r['id']);
  if (empty($v['valido'])) {
    $invalid[] = [
      'id' => (int)$r['id'],
      'fecha' => (string)$r['fecha'],
      'trabajador_id' => (int)$r['trabajador_id'],
      'puesto_id' => (int)$r['puesto_id'],
      'turno_id' => (int)$r['turno_id'],
      'errores' => $v['errores'] ?? []
    ];
    if (count($invalid) >= 40) break;
  }
}
$lines[] = 'AUTO_TOTAL=' . count($rows);
$lines[] = 'AUTO_INVALID_FOUND=' . count($invalid);
foreach ($invalid as $it) {
  $lines[] = json_encode($it, JSON_UNESCAPED_UNICODE);
}
file_put_contents('c:/xampp/htdocs/Terminal-Ibagu-/backend/logs/diag_invalid_auto.txt', implode(PHP_EOL, $lines));
?>
