<?php
require_once __DIR__ . '/backend/clases/TurnosAsignados.php';
$ta = new TurnosAsignados();
$res = $ta->validarAsignacion(12, 1, 9, '2026-08-01', null);
var_export($res);
echo PHP_EOL;
