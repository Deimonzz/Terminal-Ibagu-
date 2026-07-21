<?php
require 'config/database.php';
require 'backend/clases/Trabajadores.php';
require 'backend/clases/TurnosAsignados.php';
$db = Database::getInstance()->getConnection();
$turnos = new TurnosAsignados($db);
$trabajador_id = 12;
$puesto_id = 1;
$turno_id = 9;
$fecha = '2026-08-03';
$valid = $turnos->validarAsignacion($trabajador_id, $puesto_id, $turno_id, $fecha);
$result = $turnos->asignarDirecto([
    'trabajador_id' => $trabajador_id,
    'puesto_trabajo_id' => $puesto_id,
    'turno_id' => $turno_id,
    'fecha' => $fecha,
    'observaciones' => 'Probe direct',
    'created_by' => 1,
], true);
header('Content-Type: application/json');
echo json_encode(['validacion' => $valid, 'insert' => $result], JSON_UNESCAPED_UNICODE);
