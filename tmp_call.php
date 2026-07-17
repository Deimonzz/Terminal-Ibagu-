<?php
require 'C:\xampp\htdocs\Terminal-Ibagu-\backend\clases\Trabajadores.php';
$trab = new Trabajadores();
$reflect = new ReflectionMethod($trab, 'obtenerDisponiblesTurno');
$reflect->setAccessible(true);
try {
    $res = $reflect->invoke($trab, 1, '2026-07-01');
    echo 'OK ' . count($res) . PHP_EOL;
} catch (Throwable $e) {
    echo 'ERR ' . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
}
