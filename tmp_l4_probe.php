<?php
require 'config/database.php';
require 'backend/clases/Trabajadores.php';
$t = new Trabajadores();
$fecha='2026-08-01';
$puestoId=8;
$turnoId=4;
$lista = $t->obtenerDisponiblesL4($puestoId,$turnoId,$fecha);
echo 'count=' . count($lista) . PHP_EOL;
foreach (array_slice($lista,0,20) as $row) {
    echo $row['id'] . '|' . $row['nombre'] . PHP_EOL;
}
