<?php
require 'config/database.php';
require 'backend/clases/Trabajadores.php';
require 'backend/clases/TurnosAsignados.php';
require 'backend/clases/AsignacionAutomatica.php';

$db = Database::getInstance()->getConnection();
$asignador = new AsignacionAutomatica();
$ref = new ReflectionClass($asignador);
$method = $ref->getMethod('getDisponiblesL4');
$method->setAccessible(true);
$ctx = [
    'puestosL4TurnoIds' => [1 => [9,13], 2 => [10,14], 8 => [9,13], 10 => [9,13], 6 => [9,13]],
    'puestosL4TurnoId' => [1 => 9, 2 => 10, 8 => 9, 10 => 9, 6 => 9],
];
$lista = $method->invoke($asignador, 1, '2026-08-03', '2026-08-03', $ctx);
$trabajador = $lista[0] ?? null;
if (!$trabajador) {
    header('Content-Type: application/json');
echo json_encode(['error' => 'No candidate']);
    exit;
}
$turnoId = 9;
$result = (new TurnosAsignados($db))->asignarDirecto([
    'trabajador_id' => (int)$trabajador['id'],
    'puesto_trabajo_id' => 1,
    'turno_id' => $turnoId,
    'fecha' => '2026-08-03',
    'observaciones' => 'Probe L4',
    'created_by' => 1,
], true);

header('Content-Type: application/json');
echo json_encode(['candidate' => $trabajador, 'result' => $result], JSON_UNESCAPED_UNICODE);
