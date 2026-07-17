<?php
require 'C:\xampp\htdocs\Terminal-Ibagu-\backend\clases\Trabajadores.php';
$trab = new Trabajadores();
$reflect = new ReflectionClass($trab);
$method = $reflect->getMethod('obtenerDisponiblesTurno');
$turno_id = 1;
$fecha = '2026-07-01';
$turno = $trab->getTurno($turno_id); // private, can't access
